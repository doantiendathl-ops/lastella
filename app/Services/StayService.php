<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\StayStatus;
use App\Models\RoomAssignment;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StayService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly FolioService $folios,
    ) {
    }

    public function createStayFromAssignment(RoomAssignment $assignment): Stay
    {
        /** @var Stay $stay */
        $stay = Stay::firstOrCreate(
            ['room_assignment_id' => $assignment->id],
            [
                'booking_id' => $assignment->booking_id,
                'room_id' => $assignment->room_id,
                'planned_checkin_at' => $assignment->start_at,
                'planned_checkout_at' => $assignment->end_at,
                'status' => StayStatus::Reserved,
            ],
        );

        return $stay->load(['booking', 'roomAssignment', 'room']);
    }

    public function checkIn(Stay $stay, CarbonInterface|string|null $actualCheckinAt = null): Stay
    {
        return DB::transaction(function () use ($stay, $actualCheckinAt): Stay {
            $stay->update([
                'actual_checkin_at' => $actualCheckinAt ?? now(),
                'status'            => StayStatus::CheckedIn,
                'checked_in_by'     => Auth::id(),
            ]);

            $stay->roomAssignment->update([
                'status' => AssignmentStatus::CheckedIn,
            ]);

            $this->folios->autoPostRoomCharge($stay->booking);

            $this->bookings->updateBookingStayStatus($stay->booking);

            return $stay->refresh();
        });
    }

    public function checkOut(Stay $stay, CarbonInterface|string|null $actualCheckoutAt = null): Stay
    {
        return DB::transaction(function () use ($stay, $actualCheckoutAt): Stay {
            $stay->update([
                'actual_checkout_at' => $actualCheckoutAt ?? now(),
                'status' => StayStatus::CheckedOut,
                'checked_out_by' => Auth::id(),
            ]);

            $stay->roomAssignment->update([
                'status' => AssignmentStatus::CheckedOut,
            ]);

            $this->bookings->updateBookingStayStatus($stay->booking);

            return $stay->refresh();
        });
    }

    public function checkInMany(iterable $stays): array
    {
        $checkedIn = [];

        foreach ($stays as $stay) {
            $checkedIn[] = $this->checkIn($stay);
        }

        return $checkedIn;
    }

    public function checkOutMany(iterable $stays): array
    {
        $checkedOut = [];

        foreach ($stays as $stay) {
            $checkedOut[] = $this->checkOut($stay);
        }

        return $checkedOut;
    }
}
