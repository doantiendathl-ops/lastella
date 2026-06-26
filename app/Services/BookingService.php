<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\BookingRequirement;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly RoomAvailabilityRuleService $rules,
    ) {
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Booking::query()
            ->with('salesUser')
            ->withExists([
                'stays as has_checked_in_stays' => fn (Builder $query): Builder => $query->where('status', StayStatus::CheckedIn->value),
            ]);

        $this->applyLikeFilter($query, $filters, 'booking_code');
        $this->applyLikeFilter($query, $filters, 'customer_name');
        $this->applyLikeFilter($query, $filters, 'customer_phone');

        foreach (['status', 'booking_type', 'sales_user_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        $this->applyStayPeriodOverlapFilter($query, $filters);

        $sort = in_array($filters['sort'] ?? null, [
            'booking_code',
            'customer_name',
            'customer_phone',
            'booking_type',
            'checkin_at',
            'checkout_at',
            'status',
            'created_at',
        ], true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 5), 100);

        return $query->orderBy($sort, $direction)->paginate($perPage)->withQueryString();
    }

    public function createBooking(array $data): Booking
    {
        return DB::transaction(function () use ($data): Booking {
            $requirements = Arr::pull($data, 'requirements', []);
            $data['booking_code'] = $data['booking_code'] ?? $this->generateBookingCode();
            $data['status'] = $data['status'] ?? ($requirements === [] ? BookingStatus::Draft : BookingStatus::PendingAssignment);
            $data['created_by'] = $data['created_by'] ?? Auth::id();
            $data['updated_by'] = $data['updated_by'] ?? Auth::id();

            /** @var Booking $booking */
            $booking = Booking::create($data);

            foreach ($requirements as $requirement) {
                $booking->bookingRequirements()->create($requirement);
            }

            $booking->load(['bookingRequirements.roomType']);

            $this->folios->createFolioForBooking($booking);

            return $booking;
        });
    }

    public function addRequirement(Booking $booking, array $data): BookingRequirement
    {
        /** @var BookingRequirement $requirement */
        $requirement = $booking->bookingRequirements()->create($data);
        $this->updateBookingAssignmentStatus($booking);

        return $requirement->load('roomType');
    }

    public function updateRequirement(BookingRequirement $requirement, array $data): BookingRequirement
    {
        $requirement->update($data);
        $this->updateBookingAssignmentStatus($requirement->booking);

        return $requirement->refresh()->load('roomType');
    }

    public function deleteRequirement(BookingRequirement $requirement): void
    {
        $booking = $requirement->booking;
        $requirement->delete();
        $this->updateBookingAssignmentStatus($booking);
    }

    public function updateBooking(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data): Booking {
            $requirements = Arr::pull($data, 'requirements', null);
            $data['updated_by'] = $data['updated_by'] ?? Auth::id();

            $this->validateTimeChange($booking, $data);

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

    private function validateTimeChange(Booking $booking, array $data): void
    {
        // ── Step 1: compute what changed (no DB access needed) ──────────────
        $newCheckinAt  = isset($data['checkin_at'])  ? Carbon::parse($data['checkin_at'])  : null;
        $newCheckoutAt = isset($data['checkout_at']) ? Carbon::parse($data['checkout_at']) : null;

        if ($newCheckinAt === null && $newCheckoutAt === null) {
            return;
        }

        $effectiveCheckin  = $newCheckinAt  ?? $booking->checkin_at;
        $effectiveCheckout = $newCheckoutAt ?? $booking->checkout_at;
        $checkinChanged    = $newCheckinAt  !== null && ! $newCheckinAt->eq($booking->checkin_at);
        $checkoutChanged   = $newCheckoutAt !== null && ! $newCheckoutAt->eq($booking->checkout_at);

        if (! $checkinChanged && ! $checkoutChanged) {
            return;
        }

        // ── Step 2: pre-lock fail-fast on static state ──────────────────────
        if ($booking->status === BookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'checkin_at' => 'Booking đã hủy. Không thể chỉnh thời gian lưu trú.',
            ]);
        }

        // ── Step 3: pre-lock stay state checks (fresh reads, no locks yet) ───
        // These cover the case where no active assignments remain (e.g. all checked
        // out) but the booking still has stays with an actual_checkout_at.
        // They are re-confirmed under locks in step 6 for concurrency safety.
        $hasCheckedOutPreLock = Stay::where('booking_id', $booking->id)
            ->whereNotNull('actual_checkout_at')
            ->exists();
        if ($hasCheckedOutPreLock) {
            throw ValidationException::withMessages([
                'checkin_at' => 'Booking đã trả phòng. Không thể chỉnh thời gian lưu trú.',
            ]);
        }

        if ($checkinChanged) {
            $hasCheckedInPreLock = Stay::where('booking_id', $booking->id)
                ->whereNotNull('actual_checkin_at')
                ->exists();
            if ($hasCheckedInPreLock) {
                throw ValidationException::withMessages([
                    'checkin_at' => 'Booking đã nhận phòng. Chỉ có thể điều chỉnh thời gian trả phòng dự kiến.',
                ]);
            }
        }

        // ── Step 4: identify candidate assignment IDs with a minimal query ───
        $candidates = RoomAssignment::where('booking_id', $booking->id)
            ->whereIn('status', [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn])
            ->get(['id', 'room_id']);

        $candidateIds = $candidates->pluck('id')->all();
        $roomIds      = $candidates->pluck('room_id')->unique()->sort()->values()->all();

        // ── Step 5: acquire locks — Room → Stay → RA ─────────────────────────
        // Room lock (room_id asc) matches RoomAssignmentService::assignRooms, so a
        // concurrent assignRooms on the same rooms must wait for this transaction.
        if ($roomIds !== []) {
            Room::whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get();
        }

        // Stay before RA — consistent with StayService::checkIn / checkOut (Stay → RA)
        // to avoid opposing lock directions on the same row pair.
        // Lock ALL booking stays, not only active candidates: a concurrent checkout can
        // move the assignment to CHECKED_OUT before the candidate query and still must
        // block any booking time change.
        $lockedStays = Stay::where('booking_id', $booking->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lockedStaysByAssignment = $lockedStays->keyBy('room_assignment_id');

        $activeAssignments = $candidateIds === []
            ? collect()
            : RoomAssignment::whereIn('id', $candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

        // ── Step 6: ALL business validation on freshly locked data ───────────
        // Re-confirm stay checkout state with locked data.
        if ($lockedStays->contains(fn (Stay $stay): bool => $stay->actual_checkout_at !== null)) {
            throw ValidationException::withMessages([
                'checkin_at' => 'Booking đã trả phòng. Không thể chỉnh thời gian lưu trú.',
            ]);
        }

        // Re-confirm stay checkin state with locked data.
        if ($checkinChanged && $lockedStays->contains(fn (Stay $stay): bool => $stay->actual_checkin_at !== null)) {
            throw ValidationException::withMessages([
                'checkin_at' => 'Booking đã nhận phòng. Chỉ có thể điều chỉnh thời gian trả phòng dự kiến.',
            ]);
        }

        if ($activeAssignments->isEmpty()) {
            return;
        }

        // Re-check assignment statuses from locked rows.  A concurrent release or
        // checkout may have changed a status between the candidate query and the lock.
        foreach ($activeAssignments as $assignment) {
            if (! in_array($assignment->status, [AssignmentStatus::Assigned, AssignmentStatus::CheckedIn], true)) {
                throw ValidationException::withMessages([
                    'checkin_at' => 'Trạng thái phân phòng đã thay đổi trong khi xử lý. Vui lòng tải lại trang và thử lại.',
                ]);
            }
        }

        // ── Step 6: conflict check with locks held ───────────────────────────
        $conflictField = ($checkoutChanged && ! $checkinChanged) ? 'checkout_at' : 'checkin_at';

        $conflicts          = [];
        $structuredConflicts = [];
        foreach ($activeAssignments as $assignment) {
            $conflictAssignment = $this->rules->findConflictForTimeChange(
                $assignment->room_id,
                $effectiveCheckin,
                $effectiveCheckout,
                $booking->id,
            );

            if ($conflictAssignment !== null) {
                $roomNumber  = $conflictAssignment->room->room_number;
                $bookingCode = $conflictAssignment->booking->booking_code;
                $conflicts[] = "Phòng {$roomNumber} đang được booking {$bookingCode} sử dụng trong khoảng thời gian này.";
                $structuredConflicts[] = [
                    'room_id'       => $conflictAssignment->room_id,
                    'room_number'   => $roomNumber,
                    'room_type'     => $conflictAssignment->room->roomType?->code,
                    'booking_id'    => $conflictAssignment->booking->id,
                    'booking_code'  => $bookingCode,
                    'customer_name' => $conflictAssignment->booking->customer_name,
                    'checkin_at'    => $conflictAssignment->booking->checkin_at?->format('Y-m-d H:i'),
                    'checkout_at'   => $conflictAssignment->booking->checkout_at?->format('Y-m-d H:i'),
                    'status_label'  => $this->assignmentStatusLabel($conflictAssignment),
                    'view_url'      => route('admin.bookings.show', $conflictAssignment->booking->id),
                ];
            }
        }

        if (! empty($conflicts)) {
            session()->flash('booking_time_conflicts', $structuredConflicts);
            throw ValidationException::withMessages([
                $conflictField => "Thời gian mới làm phát sinh xung đột phòng.\n"
                    . implode("\n", $conflicts)
                    . "\nVui lòng đổi thời gian hoặc giải phóng phòng trước khi lưu.",
            ]);
        }

        // ── Step 7: update locked rows ───────────────────────────────────────
        foreach ($activeAssignments as $assignment) {
            $assignment->update([
                'start_at' => $effectiveCheckin,
                'end_at'   => $effectiveCheckout,
            ]);

            $stay = $lockedStaysByAssignment->get($assignment->id);
            if ($stay !== null && $stay->actual_checkout_at === null) {
                $updateData = ['planned_checkout_at' => $effectiveCheckout];
                if ($stay->actual_checkin_at === null) {
                    $updateData['planned_checkin_at'] = $effectiveCheckin;
                }
                $stay->update($updateData);
            }
        }
    }

    private function assignmentStatusLabel(RoomAssignment $assignment): string
    {
        return match ($assignment->status) {
            AssignmentStatus::CheckedIn => $assignment->end_at !== null && $assignment->end_at->isPast()
                ? 'Quá hạn lưu trú'
                : 'Đã nhận phòng',
            AssignmentStatus::Assigned => 'Đã phân phòng',
            default => $assignment->status->value,
        };
    }

    public function cancelBooking(Booking $booking, string $reason): Booking
    {
        return DB::transaction(function () use ($booking, $reason): Booking {
            if (! $this->canCancelNormally($booking)) {
                throw ValidationException::withMessages([
                    'booking' => 'Booking đã có phòng nhận khách, không thể hủy thông thường. Vui lòng xử lý trả phòng hoặc liên hệ quản trị viên.',
                ]);
            }

            $booking->roomAssignments()
                ->whereIn('status', AssignmentStatus::activeValues())
                ->get()
                ->each(fn ($assignment) => $assignment->update([
                    'status' => AssignmentStatus::Released,
                    'released_by' => Auth::id(),
                    'released_at' => now(),
                    'release_reason' => $reason,
                ]));

            $booking->stays()
                ->where('status', StayStatus::Reserved->value)
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

    public function restoreCancelledBooking(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $status = $booking->bookingRequirements()->exists()
                ? BookingStatus::PendingAssignment
                : BookingStatus::Draft;

            $booking->update([
                'status' => $status,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'updated_by' => Auth::id(),
            ]);

            return $booking->refresh();
        });
    }

    public function canCancelNormally(Booking $booking): bool
    {
        return ! $booking->stays()
            ->where('status', StayStatus::CheckedIn->value)
            ->exists();
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
        $remainingBalance = $this->paymentSummary($booking)['remaining_balance'];

        $status = match (true) {
            $checkedOut === $total && $remainingBalance <= 0 => BookingStatus::CheckedOut,
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

    public function paymentSummary(Booking $booking): array
    {
        $requirements = $booking->bookingRequirements()->get(['room_price', 'quantity']);
        $payments     = $booking->bookingPayments()->get(['payment_type', 'amount']);

        $requirementsTotal = (float) $requirements->sum(
            fn (BookingRequirement $requirement): float => (float) $requirement->room_price * (int) $requirement->quantity,
        );

        $folioTotal = $this->folios->getFolioTotal($booking);

        // Transition guard: use folio total when charges have been posted; fall back to
        // requirements total for bookings that have not yet had a room charge posted.
        $totalCharges = $folioTotal > 0.0 ? $folioTotal : $requirementsTotal;

        $totalDeposit    = 0.0;
        $totalPayment    = 0.0;
        $totalRefund     = 0.0;
        $totalAdjustment = 0.0;

        foreach ($payments as $payment) {
            match ($payment->payment_type) {
                PaymentType::Deposit,
                PaymentType::AdditionalDeposit => $totalDeposit += (float) $payment->amount,
                PaymentType::RoomPayment,
                PaymentType::ServicePayment => $totalPayment += (float) $payment->amount,
                PaymentType::Refund    => $totalRefund += (float) $payment->amount,
                PaymentType::Adjustment => $totalAdjustment += (float) $payment->amount,
            };
        }

        $paidTotal  = $totalDeposit + $totalPayment + $totalAdjustment - $totalRefund;
        $balanceDue = $totalCharges - $paidTotal;

        return [
            'total_charges'    => $totalCharges,
            'balance_due'      => $balanceDue,
            'expected_total'   => $totalCharges,   // backward-compat alias
            'total_deposit'    => $totalDeposit,
            'total_payment'    => $totalPayment,
            'total_refund'     => $totalRefund,
            'total_adjustment' => $totalAdjustment,
            'paid_total'       => $paidTotal,
            'remaining_balance'=> $balanceDue,     // backward-compat alias
        ];
    }

    private function generateBookingCode(): string
    {
        do {
            $code = 'BK-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4));
        } while (Booking::where('booking_code', $code)->exists());

        return $code;
    }

    private function applyLikeFilter(Builder $query, array $filters, string $column): void
    {
        if (filled($filters[$column] ?? null)) {
            $query->where($column, 'like', '%'.$filters[$column].'%');
        }
    }

    private function applyStayPeriodOverlapFilter(Builder $query, array $filters): void
    {
        $dateFrom = filled($filters['date_from'] ?? null)
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : null;
        $dateTo = filled($filters['date_to'] ?? null)
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : null;

        if ($dateFrom === null && $dateTo === null) {
            return;
        }

        if ($dateTo !== null) {
            $query->where('checkin_at', '<=', $dateTo);
        }

        if ($dateFrom !== null) {
            $query->where('checkout_at', '>=', $dateFrom);
        }
    }
}
