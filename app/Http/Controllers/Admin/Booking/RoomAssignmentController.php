<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ReleaseAssignmentRequest;
use App\Http\Requests\Booking\StoreRoomAssignmentRequest;
use App\Http\Requests\Booking\StoreRoomBoardAssignmentRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Services\RoomAssignmentService;
use App\Services\RoomAvailabilityRuleService;
use App\Services\StayService;
use Illuminate\Http\RedirectResponse;

class RoomAssignmentController extends Controller
{
    public function __construct(
        private readonly RoomAssignmentService $assignments,
        private readonly StayService $stays,
        private readonly RoomAvailabilityRuleService $rules,
    ) {
    }

    public function store(StoreRoomAssignmentRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('create', RoomAssignment::class);

        $data = $request->validated();
        $roomIds = collect($data['room_ids'] ?? [$data['room_id']])
            ->map(fn ($roomId): int => (int) $roomId)
            ->unique()
            ->values();
        $rooms = Room::query()->whereKey($roomIds)->get()->keyBy('id');

        // Room Demand/Room Board Unification M2: atomic requirement mapping —
        // every assignment created here gets booking_requirement_id resolved and
        // written in the same transaction. assignRooms() (legacy) is unchanged
        // and still used by every other call site.
        $assignments = $this->assignments->assignRoomsWithRequirementLink(
            $booking,
            $roomIds->map(function (int $roomId) use ($rooms, $data): array {
                $room = $rooms->get($roomId);

                return [
                    'room_id' => $room->id,
                    'room_type_id' => $room->room_type_id,
                    'start_at' => $data['start_at'],
                    'end_at' => $data['end_at'],
                ];
            })->all(),
            $data['target_requirement_id'] ?? [],
        );

        foreach ($assignments as $assignment) {
            $this->stays->createStayFromAssignment($assignment);
        }

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã phân phòng.');
    }

    /**
     * Room Demand/Room Board Unification M3 — Room-Board-first entry point.
     * Thin by design: all reconciliation/allocation/atomicity lives in
     * RoomAssignmentService::assignRoomsFromRoomBoard(), including Stay
     * creation (unlike store() above, there is deliberately no post-call Stay
     * loop here — Stay rows are created INSIDE the service's transaction,
     * Implementation Plan Mục XIII). Authorization (room.assign AND
     * booking.update) is fully enforced by StoreRoomBoardAssignmentRequest::authorize().
     */
    public function storeFromRoomBoard(StoreRoomBoardAssignmentRequest $request, Booking $booking): RedirectResponse
    {
        $data = $request->validated();

        $groups = collect($data['groups'])->map(fn (array $group): array => [
            'room_type_id' => (int) $group['room_type_id'],
            'room_ids' => collect($group['room_ids'])->map(fn ($id): int => (int) $id)->all(),
            'target_requirement_id' => isset($group['target_requirement_id']) ? (int) $group['target_requirement_id'] : null,
            'room_price' => isset($group['room_price']) ? (float) $group['room_price'] : null,
            'price_source' => $group['price_source'] ?? null,
            'note' => $group['note'] ?? null,
            'adults' => isset($group['adults']) ? (int) $group['adults'] : null,
            'children_under_6' => isset($group['children_under_6']) ? (int) $group['children_under_6'] : null,
            'children_over_6' => isset($group['children_over_6']) ? (int) $group['children_over_6'] : null,
        ])->all();

        $this->assignments->assignRoomsFromRoomBoard($booking, $groups, $data['start_at'], $data['end_at']);

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
            ->with('success', 'Đã phân phòng từ Sơ đồ phòng.');
    }

    public function release(ReleaseAssignmentRequest $request, Booking $booking, RoomAssignment $assignment): RedirectResponse
    {
        $this->authorize('release', $assignment);
        abort_unless($assignment->booking_id === $booking->id, 404);

        $this->assignments->releaseAssignment($assignment, $request->validated('release_reason'));

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã giải phóng phòng.');
    }

    public function releaseConflict(ReleaseAssignmentRequest $request, Booking $booking, RoomAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->booking_id === $booking->id, 404);
        abort_unless($this->rules->isAssignmentBlockingBooking($assignment, $booking), 404);

        $this->authorize('release', $assignment);

        $this->assignments->releaseAssignment($assignment, $request->validated('release_reason'));

        return redirect()
            ->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])
            ->with('success', 'Đã gỡ phòng khỏi booking đang chiếm phòng.');
    }
}
