<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\FinalCheckoutConfirmationRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoomOperations\BulkCheckInRequest;
use App\Http\Requests\RoomOperations\BulkCheckOutRequest;
use App\Http\Requests\RoomOperations\SwapExecuteRequest;
use App\Http\Requests\RoomOperations\SwapPreviewRequest;
use App\Http\Requests\RoomOperations\UpdateQuickNoteRequest;
use App\Models\Booking;
use App\Models\ProductService;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Services\BookingService;
use App\Services\RoomOperationsBoardService;
use App\Services\RoomSwapService;
use App\Services\StayService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Daily Room Operations Board — Mục XLI: thin controller, all business logic
 * lives in RoomOperationsBoardService (read model) and RoomSwapService (swap
 * engine). Check-in/checkout here are thin orchestration over the EXISTING
 * StayService::checkIn()/checkOut() core — never a re-implementation — added
 * because the existing per-stay routes redirect to Booking Show, which would
 * navigate the operator away from the board (Mục XXII/XXIII still require
 * every guard — inspection warning, balance warning, early check-in,
 * ADMIN-only actual-time override — to keep applying unchanged).
 */
class RoomOperationsController extends Controller
{
    public function __construct(
        private readonly RoomOperationsBoardService $board,
        private readonly RoomSwapService $swaps,
        private readonly StayService $stays,
        private readonly BookingService $bookings,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorizeView($request);

        $date = $request->query('date') ?: Carbon::today()->format('Y-m-d');

        if (! $this->isValidDate($date)) {
            $date = Carbon::today()->format('Y-m-d');
        }

        $user = $request->user();

        return Inertia::render('Admin/RoomOperations/Index', [
            'board' => $this->board->boardForDate($date, $user),
            'dailySummary' => $this->board->dailySummaryForDate($date, $user),
            'filters' => ['date' => $date],
            // Fast Inspection Popup: identical payload shape/source
            // CheckoutInspectionController::index() already sends —
            // CheckoutInspectionModal.vue is reused verbatim, never a second
            // inspection-item catalog.
            'inspectionProducts' => $user->can('checkout_inspection.perform') ? $this->inspectionProducts() : [],
            'can' => [
                'swap' => $user->can('room.assign'),
                'checkIn' => $user->can('stay.checkin'),
                'checkOut' => $user->can('stay.checkout'),
                'inspect' => $user->can('checkout_inspection.perform'),
                'overrideCheckoutInspection' => $user->can('checkout_inspection.override'),
                'clean' => $user->can('room.cleaning.update'),
                'viewBooking' => $user->can('viewAny', Booking::class),
            ],
        ]);
    }

    private function inspectionProducts(): array
    {
        return ProductService::usableInCheckoutInspection()
            ->with('category')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ProductService $p): array => [
                'id' => $p->id,
                'category_name' => $p->category?->name,
                'code' => $p->code,
                'name' => $p->name,
                'unit' => $p->unit,
                'price' => (float) $p->price,
                'free_quantity_default' => $p->free_quantity_default,
            ])
            ->values()
            ->all();
    }

    public function swapPreview(SwapPreviewRequest $request): JsonResponse
    {
        return response()->json($this->swaps->preview($request->validated('pairs')));
    }

    public function swapExecute(SwapExecuteRequest $request): RedirectResponse
    {
        try {
            $this->swaps->execute(
                $request->validated('pairs'),
                $request->user(),
                $request->boolean('warnings_acknowledged', false),
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->with('swap_failed', true);
        }

        return back()->with('success', 'Đã đổi phòng thành công.');
    }

    public function updateQuickNote(UpdateQuickNoteRequest $request, RoomAssignment $assignment): RedirectResponse
    {
        $assignment->update(['quick_note' => $request->validated('quick_note')]);

        return back()->with('success', 'Đã lưu ghi chú.');
    }

    /**
     * Thin orchestration over StayService::checkIn() (Mục XXII) — per-stay
     * isolation so one ineligible stay in a multi-select does not silently
     * abort check-ins that had already succeeded earlier in the batch.
     */
    public function checkIn(BulkCheckInRequest $request): RedirectResponse
    {
        $stays = Stay::whereIn('id', $request->validated('stay_ids'))->get();
        $errors = [];

        foreach ($stays as $stay) {
            $this->authorize('checkIn', $stay);

            try {
                $this->stays->checkIn($stay);
            } catch (ValidationException $e) {
                $errors["stay_{$stay->id}"] = "Phòng {$stay->room?->room_number}: ".implode(' ', $e->errors()['stay'] ?? ['Không thể nhận phòng.']);
            }
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->with('partial_failure', true);
        }

        return back()->with('success', 'Đã nhận phòng.');
    }

    /**
     * Thin orchestration over StayService::checkOut() (Mục XXIII) — never
     * bypasses the inspection/balance guards; a stay needing confirmation is
     * reported back individually instead of silently skipped.
     */
    public function checkOut(BulkCheckOutRequest $request): RedirectResponse
    {
        $stays = Stay::whereIn('id', $request->validated('stay_ids'))->get();
        $confirmed = $request->boolean('confirmed', false);
        $errors = [];
        $needsConfirmation = [];
        // docs/Prompt_2.txt mục VIII — keyed by stay_id (not booking_id) so
        // CheckoutFlowDialogs.vue can label each pending room with its own
        // booking's real posted balance, even when several bookings' final
        // stays land in the same bulk selection.
        $balancesByStay = [];

        foreach ($stays as $stay) {
            $this->authorize('checkOut', $stay);

            try {
                $this->stays->checkOut($stay, null, $confirmed);
            } catch (FinalCheckoutConfirmationRequiredException) {
                $needsConfirmation[] = $stay->id;
                $balancesByStay[$stay->id] = $this->bookings->paymentSummary($stay->booking)['balance_due'];
            } catch (ValidationException $e) {
                $errors["stay_{$stay->id}"] = "Phòng {$stay->room?->room_number}: ".implode(' ', $e->errors()['stay'] ?? ['Không thể trả phòng.']);
            }
        }

        if ($needsConfirmation !== []) {
            return back()
                ->with('final_checkout_confirmation_required', $needsConfirmation)
                ->with('final_checkout_balances', $balancesByStay);
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->with('partial_failure', true);
        }

        return back()->with('success', 'Đã trả phòng.');
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        $allowed = ($user?->can('room.assign') ?? false)
            || ($user?->can('stay.checkin') ?? false)
            || ($user?->can('stay.checkout') ?? false)
            || ($user?->can('housekeeping.view') ?? false)
            || ($user?->can('checkout_inspection.view') ?? false);

        abort_unless($allowed, 403);
    }

    private function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && Carbon::createFromFormat('Y-m-d', $date) !== false;
    }
}
