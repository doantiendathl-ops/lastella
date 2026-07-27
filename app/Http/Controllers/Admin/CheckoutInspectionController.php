<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StayStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveCheckoutInspectionRequest;
use App\Models\CheckoutInspection;
use App\Models\Floor;
use App\Models\ProductService;
use App\Models\Stay;
use App\Services\CheckoutInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CheckoutInspectionController extends Controller
{
    public function __construct(private readonly CheckoutInspectionService $inspections)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', CheckoutInspection::class);

        $stays = Stay::query()
            ->where('status', StayStatus::CheckedIn)
            ->with(['room.floor', 'room.roomType', 'booking', 'checkoutInspection'])
            ->get();

        $floors = Floor::query()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Floor $floor) use ($stays): array {
                $floorStays = $stays->filter(fn (Stay $stay): bool => $stay->room?->floor_id === $floor->id);

                return [
                    'id' => $floor->id,
                    'code' => $floor->code,
                    'name' => $floor->name,
                    'rooms' => $floorStays->map(fn (Stay $stay): array => $this->mapStay($stay))->values(),
                ];
            })
            ->filter(fn (array $floor): bool => count($floor['rooms']) > 0)
            ->values();

        $products = ProductService::usableInCheckoutInspection()
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
            ]);

        return Inertia::render('Admin/CheckoutInspections/Index', [
            'floors' => $floors,
            'products' => $products,
            'can' => [
                'perform' => $request->user()->can('checkout_inspection.perform'),
                'override' => $request->user()->can('checkout_inspection.override'),
            ],
        ]);
    }

    public function draft(Request $request, Stay $stay): JsonResponse
    {
        $this->authorize('create', CheckoutInspection::class);

        $inspection = $this->inspections->getOrCreateDraft($stay, $request->user());

        return response()->json($this->mapInspection($inspection));
    }

    public function saveDraft(SaveCheckoutInspectionRequest $request, CheckoutInspection $checkoutInspection): JsonResponse
    {
        $this->authorize('update', $checkoutInspection);

        $data = $request->validated();
        $inspection = $this->inspections->saveDraft($checkoutInspection, $data['items'] ?? [], $data['note'] ?? null, $request->user());

        return response()->json($this->mapInspection($inspection));
    }

    public function complete(SaveCheckoutInspectionRequest $request, CheckoutInspection $checkoutInspection): JsonResponse
    {
        $this->authorize('update', $checkoutInspection);

        $data = $request->validated();
        $inspection = $this->inspections->complete($checkoutInspection, $data['items'] ?? [], $data['note'] ?? null, $request->user());

        return response()->json($this->mapInspection($inspection));
    }

    private function mapStay(Stay $stay): array
    {
        $inspection = $stay->checkoutInspection;
        $isOverdue = $stay->planned_checkout_at !== null && $stay->planned_checkout_at->isPast();

        return [
            'stay_id' => $stay->id,
            'booking_id' => $stay->booking_id,
            'room_id' => $stay->room_id,
            'room_number' => $stay->room?->room_number,
            'room_type' => $stay->room?->roomType?->code,
            'guest_name' => $stay->booking?->customer_name,
            'planned_checkout_at' => $stay->planned_checkout_at?->toDateTimeString(),
            'is_checkout_today' => $stay->planned_checkout_at?->isToday() ?? false,
            'is_overdue' => $isOverdue,
            'inspection' => $inspection === null ? null : $this->mapInspection($inspection),
        ];
    }

    private function mapInspection(CheckoutInspection $inspection): array
    {
        $inspection->loadMissing('items');

        return [
            'id' => $inspection->id,
            'stay_id' => $inspection->stay_id,
            'status' => $inspection->status->value,
            'status_label' => $inspection->status->label(),
            'total_amount' => (float) $inspection->total_amount,
            'note' => $inspection->note,
            'completed_at' => $inspection->completed_at?->toDateTimeString(),
            'items' => $inspection->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_service_id' => $item->product_service_id,
                'product_name' => $item->product_name_snapshot,
                'unit' => $item->unit_snapshot,
                'unit_price' => (float) $item->unit_price_snapshot,
                'free_quantity' => $item->free_quantity,
                'actual_quantity' => $item->actual_quantity,
                'chargeable_quantity' => $item->chargeable_quantity,
                'line_total' => (float) $item->line_total,
                'note' => $item->note,
            ]),
        ];
    }
}
