<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ReleaseAssignmentRequest;
use App\Http\Requests\Booking\StoreRoomAssignmentRequest;
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

        $assignments = $this->assignments->assignRooms(
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
        );

        foreach ($assignments as $assignment) {
            $this->stays->createStayFromAssignment($assignment);
        }

        return redirect()->route('admin.bookings.show', ['booking' => $booking, 'tab' => 'room_map'])->with('success', 'Đã phân phòng.');
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
