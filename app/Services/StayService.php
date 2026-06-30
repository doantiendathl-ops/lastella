<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\RoomAssignment;
use App\Models\Stay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            // ADR-38: Booking lock FIRST — canonical order: Booking → Stay → RoomAssignment.
            $lockedBooking = Booking::whereKey($stay->booking_id)->lockForUpdate()->firstOrFail();
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();

            if ($assignment->status !== AssignmentStatus::Assigned) {
                throw ValidationException::withMessages([
                    'stay' => 'Không thể nhận phòng. Trạng thái phân phòng không hợp lệ.',
                ]);
            }

            if ($lockedStay->actual_checkin_at !== null) {
                throw ValidationException::withMessages([
                    'stay' => 'Phòng đã được nhận phòng rồi.',
                ]);
            }

            if ($lockedStay->planned_checkin_at !== null && now()->lt($lockedStay->planned_checkin_at)) {
                throw ValidationException::withMessages([
                    'stay' => 'Chưa đến thời gian nhận phòng dự kiến. Thời gian nhận phòng dự kiến: '
                        . $lockedStay->planned_checkin_at->format('d/m/Y H:i') . '.',
                ]);
            }

            $lockedStay->update([
                'actual_checkin_at' => $actualCheckinAt ?? now(),
                'status'            => StayStatus::CheckedIn,
                'checked_in_by'     => Auth::id(),
            ]);

            $assignment->update([
                'status' => AssignmentStatus::CheckedIn,
            ]);

            $this->folios->autoPostRoomCharge($lockedBooking);
            $this->bookings->updateBookingStayStatus($lockedBooking);

            return $lockedStay->refresh();
        });
    }

    public function checkOut(Stay $stay, CarbonInterface|string|null $actualCheckoutAt = null): Stay
    {
        return DB::transaction(function () use ($stay, $actualCheckoutAt): Stay {
            // ADR-38: Booking lock FIRST — canonical order: Booking → Stay → RoomAssignment.
            $lockedBooking = Booking::whereKey($stay->booking_id)->lockForUpdate()->firstOrFail();
            $lockedStay    = Stay::whereKey($stay->id)->lockForUpdate()->firstOrFail();
            $assignment    = RoomAssignment::whereKey($lockedStay->room_assignment_id)->lockForUpdate()->firstOrFail();

            if ($assignment->status !== AssignmentStatus::CheckedIn) {
                throw ValidationException::withMessages([
                    'stay' => 'Không thể trả phòng. Trạng thái phân phòng không hợp lệ.',
                ]);
            }

            if ($lockedStay->actual_checkin_at === null) {
                throw ValidationException::withMessages([
                    'stay' => 'Chưa nhận phòng nên không thể trả phòng.',
                ]);
            }

            if ($lockedStay->actual_checkout_at !== null) {
                throw ValidationException::withMessages([
                    'stay' => 'Phòng đã được trả phòng rồi.',
                ]);
            }

            $lockedStay->update([
                'actual_checkout_at' => $actualCheckoutAt ?? now(),
                'status'             => StayStatus::CheckedOut,
                'checked_out_by'     => Auth::id(),
            ]);

            $assignment->update([
                'status' => AssignmentStatus::CheckedOut,
            ]);

            // ADR-49: Active stay = Reserved OR CheckedIn (own DML visible within transaction).
            $remainingActive = Stay::where('booking_id', $lockedBooking->id)
                ->whereIn('status', [StayStatus::Reserved, StayStatus::CheckedIn])
                ->count();

            if ($remainingActive === 0) {
                // ADR-48: finalise runs inside caller's transaction; Booking lock already held.
                $this->bookings->finaliseBookingCheckout($lockedBooking);
            } else {
                $this->bookings->updateBookingStayStatus($lockedBooking);
            }

            return $lockedStay->refresh();
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
