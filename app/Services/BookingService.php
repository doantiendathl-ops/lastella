<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\StayStatus;
use App\Models\Booking;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    public function createBooking(array $data): Booking
    {
        return DB::transaction(function () use ($data): Booking {
            $requirements = Arr::pull($data, 'requirements', []);
            $data['booking_code'] = $data['booking_code'] ?? $this->generateBookingCode();
            $data['status'] = $data['status'] ?? BookingStatus::PendingAssignment;
            $data['created_by'] = $data['created_by'] ?? Auth::id();
            $data['updated_by'] = $data['updated_by'] ?? Auth::id();

            /** @var Booking $booking */
            $booking = Booking::create($data);

            foreach ($requirements as $requirement) {
                $booking->bookingRequirements()->create($requirement);
            }

            return $booking->load(['bookingRequirements.roomType']);
        });
    }

    public function updateBooking(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data): Booking {
            $requirements = Arr::pull($data, 'requirements', null);
            $data['updated_by'] = $data['updated_by'] ?? Auth::id();

            $booking->fill($data)->save();

            if (is_array($requirements)) {
                $booking->bookingRequirements()->delete();

                foreach ($requirements as $requirement) {
                    $booking->bookingRequirements()->create($requirement);
                }

                $this->updateBookingAssignmentStatus($booking);
            }

            return $booking->refresh()->load(['bookingRequirements.roomType']);
        });
    }

    public function cancelBooking(Booking $booking, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $reason): Booking {
            $booking->roomAssignments()
                ->whereIn('status', AssignmentStatus::activeValues())
                ->get()
                ->each(fn ($assignment) => $assignment->update([
                    'status' => AssignmentStatus::Cancelled,
                    'released_by' => Auth::id(),
                    'released_at' => now(),
                    'release_reason' => $reason,
                ]));

            $booking->stays()
                ->whereIn('status', [StayStatus::Reserved->value, StayStatus::CheckedIn->value])
                ->get()
                ->each(fn ($stay) => $stay->update([
                    'status' => StayStatus::Cancelled,
                ]));

            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
                'updated_by' => Auth::id(),
            ]);

            return $booking->refresh();
        });
    }

    public function updateBookingAssignmentStatus(Booking $booking): Booking
    {
        $booking->loadMissing(['bookingRequirements', 'roomAssignments']);

        if ($booking->status === BookingStatus::Cancelled || $booking->status === BookingStatus::NoShow) {
            return $booking;
        }

        $requiredByType = $booking->bookingRequirements
            ->groupBy('room_type_id')
            ->map(fn ($requirements): int => (int) $requirements->sum('quantity'));

        $totalRequired = (int) $requiredByType->sum();

        if ($totalRequired === 0) {
            $booking->update(['status' => BookingStatus::Draft]);

            return $booking->refresh();
        }

        $assignedByType = $booking->roomAssignments
            ->whereIn('status', [
                AssignmentStatus::Assigned,
                AssignmentStatus::CheckedIn,
                AssignmentStatus::CheckedOut,
            ])
            ->groupBy('room_type_id')
            ->map(fn ($assignments): int => $assignments->count());

        $totalAssignedAgainstRequirement = 0;

        foreach ($requiredByType as $roomTypeId => $required) {
            $totalAssignedAgainstRequirement += min($required, (int) ($assignedByType[$roomTypeId] ?? 0));
        }

        $status = match (true) {
            $totalAssignedAgainstRequirement === 0 => BookingStatus::PendingAssignment,
            $totalAssignedAgainstRequirement < $totalRequired => BookingStatus::PartiallyAssigned,
            default => BookingStatus::FullyAssigned,
        };

        $booking->update([
            'status' => $status,
            'updated_by' => Auth::id(),
        ]);

        return $booking->refresh();
    }

    public function updateBookingStayStatus(Booking $booking): Booking
    {
        $booking->loadMissing('stays');

        if ($booking->status === BookingStatus::Cancelled || $booking->status === BookingStatus::NoShow) {
            return $booking;
        }

        $stays = $booking->stays->whereNotIn('status', [
            StayStatus::Cancelled,
            StayStatus::NoShow,
        ]);

        $total = $stays->count();

        if ($total === 0) {
            return $booking;
        }

        $checkedIn = $stays->where('status', StayStatus::CheckedIn)->count();
        $checkedOut = $stays->where('status', StayStatus::CheckedOut)->count();

        $status = match (true) {
            $checkedOut === $total => BookingStatus::CheckedOut,
            $checkedOut > 0 => BookingStatus::PartiallyCheckedOut,
            $checkedIn === $total => BookingStatus::CheckedIn,
            $checkedIn > 0 => BookingStatus::PartiallyCheckedIn,
            default => $booking->status,
        };

        $booking->update([
            'status' => $status,
            'updated_by' => Auth::id(),
        ]);

        return $booking->refresh();
    }

    private function generateBookingCode(): string
    {
        do {
            $code = 'BK-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }
}
