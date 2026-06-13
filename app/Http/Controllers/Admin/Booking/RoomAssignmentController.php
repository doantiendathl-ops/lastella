<?php

namespace App\Http\Controllers\Admin\Booking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ReleaseAssignmentRequest;
use App\Http\Requests\Booking\StoreRoomAssignmentRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Services\RoomAssignmentService;
use App\Services\StayService;
use Illuminate\Http\RedirectResponse;

class RoomAssignmentController extends Controller
{
    public function __construct(
        private readonly RoomAssignmentService $assignments,
        private readonly StayService $stays,
    ) {
    }

    public function store(StoreRoomAssignmentRequest $request, Booking $booking): RedirectResponse
    {
        $this->authorize('create', RoomAssignment::class);

        $data = $request->validated();
        $room = Room::findOrFail($data['room_id']);

        $assignments = $this->assignments->assignRooms($booking, [[
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
        ]]);

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
}
