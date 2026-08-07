<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Enums\BookingStatus;
use App\Enums\ChargeType;
use App\Enums\PaymentType;
use App\Enums\StayStatus;
use App\Exceptions\OutstandingBalanceException;
use App\Exceptions\RequirementLockedAfterRoomChargeException;
use App\Exceptions\RequirementReferencedByAssignmentException;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingRequirement;
use App\Models\Folio;
use App\Models\FolioEntry;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\SpecialRequestService;

class BookingService
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly RoomAvailabilityRuleService $rules,
        private readonly SpecialRequestService $specialRequests,
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

    /**
     * Room Demand/Room Board Unification M1: locks Booking first, then locks
     * every existing requirement line of the same room_type, before creating
     * the new line — canonical order Booking → BookingRequirement (id asc).
     * The room_type lock closes a duplicate-merge-key race against the
     * future Room-Board-first allocation path (M2/M3), which reads-then-
     * decides whether to reuse or create a line for the same room_type.
     * Business behaviour is unchanged: this still always creates a new line,
     * never merges — only the locking is new.
     */
    public function addRequirement(Booking $booking, array $data): BookingRequirement
    {
        return DB::transaction(function () use ($booking, $data): BookingRequirement {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            BookingRequirement::where('booking_id', $lockedBooking->id)
                ->where('room_type_id', $data['room_type_id'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            /** @var BookingRequirement $requirement */
            $requirement = $lockedBooking->bookingRequirements()->create($data);
            $this->updateBookingAssignmentStatus($lockedBooking);

            return $requirement->load('roomType');
        });
    }

    /**
     * Room Demand/Room Board Unification M1: locks Booking, then re-queries
     * and locks the requirement row itself (the pre-transaction $requirement
     * instance is never written to directly), before running the existing
     * Folio room-charge guard and updating. Canonical order Booking →
     * BookingRequirement, matching addRequirement()/deleteRequirement().
     */
    public function updateRequirement(BookingRequirement $requirement, array $data): BookingRequirement
    {
        return DB::transaction(function () use ($requirement, $data): BookingRequirement {
            $lockedBooking = Booking::whereKey($requirement->booking_id)->lockForUpdate()->firstOrFail();

            $lockedRequirement = BookingRequirement::whereKey($requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequirement->booking_id !== $lockedBooking->id) {
                throw ValidationException::withMessages([
                    'requirement' => 'Yêu cầu phòng không thuộc booking này.',
                ]);
            }

            if ($this->hasActiveRoomCharge($lockedBooking)) {
                throw new RequirementLockedAfterRoomChargeException();
            }

            $lockedRequirement->update($data);
            $this->updateBookingAssignmentStatus($lockedBooking);

            return $lockedRequirement->refresh()->load('roomType');
        });
    }

    /**
     * Room Demand/Room Board Unification M3: whether this booking's folio has
     * any unvoided ROOM charge entry — the exact condition
     * RequirementLockedAfterRoomChargeException guards against. Extracted out
     * of updateRequirement() (behaviour unchanged, same query, same lock) so
     * the Room-Board-first excess-allocation logic in RoomAssignmentService
     * can consult the SAME guard condition up front, instead of duplicating
     * the query or discovering the lock only via a thrown exception. This is
     * booking-wide, not per-requirement-line: FolioEntry has no
     * booking_requirement_id column, so once any Room charge exists anywhere
     * on the booking's folio, every requirement line is equally locked.
     * MUST be called from within an existing DB::transaction() — the
     * lockForUpdate() here does not open one of its own.
     */
    public function hasActiveRoomCharge(Booking $booking): bool
    {
        $folioId = $booking->folio?->id;

        if ($folioId === null) {
            return false;
        }

        return FolioEntry::where('folio_id', $folioId)
            ->where('charge_type', ChargeType::Room->value)
            ->whereNull('voided_at')
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Room Demand/Room Board Unification M1: locks Booking, then the
     * requirement row, then guards against hard-deleting a requirement that
     * any RoomAssignment (any status, including Released) still references
     * via booking_requirement_id — restrictOnDelete() on that column is the
     * database-level backstop for this same rule (Architecture Review
     * REVISION 3, Product Owner Decision #13/Mục V).
     */
    public function deleteRequirement(BookingRequirement $requirement): void
    {
        DB::transaction(function () use ($requirement): void {
            $lockedBooking = Booking::whereKey($requirement->booking_id)->lockForUpdate()->firstOrFail();

            $lockedRequirement = BookingRequirement::whereKey($requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequirement->booking_id !== $lockedBooking->id) {
                throw ValidationException::withMessages([
                    'requirement' => 'Yêu cầu phòng không thuộc booking này.',
                ]);
            }

            if (RoomAssignment::where('booking_requirement_id', $lockedRequirement->id)->exists()) {
                throw new RequirementReferencedByAssignmentException();
            }

            $lockedRequirement->delete();
            $this->updateBookingAssignmentStatus($lockedBooking);
        });
    }

    /**
     * Final Gap Closure (M5, Mục IV scope extension — approved) —
     * Architecture Gap: `UpdateBookingRequest` structurally allows
     * `status` to be set to any `BookingStatus`, including terminal states,
     * through this fully generic method — confirmed reachable (no live UI
     * form ever sends it, but the route/controller/FormRequest chain
     * accepts it from any caller holding `booking.update`, and it was
     * empirically verified to write `NO_SHOW` with zero locking and zero
     * business validation before this fix). This carries the exact same
     * unlocked-terminal-write defect `cancelBooking()` had.
     *
     * Fix is intentionally narrow — only a request that actually transitions
     * status INTO an assignment-blocking state (isTerminal() or
     * PartiallyCheckedOut, the same set every assignment guard in this
     * codebase already uses) locks Booking, and it does so BEFORE
     * validateTimeChange() (which itself locks Room/Stay/RoomAssignment,
     * never Booking) — preserving the canonical Booking-first order used
     * everywhere else, rather than reversing it. A request that never
     * touches `status`, or that changes it to a non-blocking value, takes
     * no new lock at all — this is deliberately NOT a blanket lock on the
     * whole generic update. No business rule changes: no assignment is
     * released, no folio is voided here — this only closes the race window
     * for whichever concurrent assignment path could interleave with the
     * status write.
     */
    public function updateBooking(Booking $booking, array $data): Booking
    {
        return DB::transaction(function () use ($booking, $data): Booking {
            $requirements = Arr::pull($data, 'requirements', null);
            $data['updated_by'] = $data['updated_by'] ?? Auth::id();

            if (isset($data['status'])) {
                $newStatus = $data['status'] instanceof BookingStatus
                    ? $data['status']
                    : BookingStatus::from($data['status']);

                if ($newStatus !== $booking->status
                    && ($newStatus->isTerminal() || $newStatus === BookingStatus::PartiallyCheckedOut)) {
                    $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
                }
            }

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

    /**
     * Final Gap Closure (M5, Blocker B Mục III) — Architecture Gap Closure:
     * every other Booking-mutating method in this codebase
     * (assignRoomsFromRoomBoard, bulkReleaseAssignments, checkIn, checkOut,
     * addRequirement, updateRequirement) locks Booking first; this one
     * never did, so it could race unlocked against a concurrent assignment
     * transaction and leave `status=Cancelled` coexisting with an active
     * RoomAssignment created after cancellation had already "won" — exactly
     * what Race Case 16 reproduced. The fix is ONLY a locking-timing change:
     * every check/read/write below now operates on `$lockedBooking`
     * (freshly re-fetched under the lock, never the possibly-stale instance
     * passed in) instead of `$booking` — no business rule, check, or write
     * order is altered.
     */
    public function cancelBooking(Booking $booking, string $reason): Booking
    {
        return DB::transaction(function () use ($booking, $reason): Booking {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (! $this->canCancelNormally($lockedBooking)) {
                throw ValidationException::withMessages([
                    'booking' => 'Booking đã có phòng nhận khách, không thể hủy thông thường. Vui lòng xử lý trả phòng hoặc liên hệ quản trị viên.',
                ]);
            }

            $lockedBooking->roomAssignments()
                ->whereIn('status', AssignmentStatus::activeValues())
                ->get()
                ->each(fn ($assignment) => $assignment->update([
                    'status' => AssignmentStatus::Released,
                    'released_by' => Auth::id(),
                    'released_at' => now(),
                    'release_reason' => $reason,
                ]));

            $lockedBooking->stays()
                ->where('status', StayStatus::Reserved->value)
                ->get()
                ->each(fn ($stay) => $stay->update([
                    'status' => StayStatus::Cancelled,
                ]));

            $lockedBooking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancellation_reason' => $reason,
                'updated_by' => Auth::id(),
            ]);

            // Phase 4.1: auto-cancel all pending/acknowledged special requests.
            // autoCancelForBooking is a single UPDATE — no extra locks, no financial tables.
            $this->specialRequests->autoCancelForBooking($lockedBooking, Auth::id());

            // ADR-36: void the folio when booking is cancelled.
            // If the folio has active entries, FolioHasActiveEntriesException is thrown
            // and the entire transaction rolls back — the booking stays active.
            $folio = $lockedBooking->folio;
            if ($folio !== null) {
                $this->folios->voidFolioOnCancellation($folio);
            }

            return $lockedBooking->refresh();
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

        $checkedIn  = $stays->where('status', StayStatus::CheckedIn)->count();
        $checkedOut = $stays->where('status', StayStatus::CheckedOut)->count();

        // ADR-39: CheckedOut transition is handled exclusively by finaliseBookingCheckout.
        // updateBookingStayStatus only drives intermediate transitions.
        $status = match (true) {
            $checkedOut > 0       => BookingStatus::PartiallyCheckedOut,
            $checkedIn === $total => BookingStatus::CheckedIn,
            $checkedIn > 0        => BookingStatus::PartiallyCheckedIn,
            default               => $booking->status,
        };

        $booking->update([
            'status' => $status,
            'updated_by' => Auth::id(),
        ]);

        return $booking->refresh();
    }

    /**
     * ADR-39/ADR-48: MUST be called within an existing DB::transaction. Does NOT open its own.
     * ADR-48: Caller MUST hold Booking::lockForUpdate() on $booking before calling.
     * ADR-40: Outstanding balance throws OutstandingBalanceException and rolls back the entire transaction.
     * ADR-41: Folio is auto-closed atomically; caller acquires Folio lock here.
     */
    public function finaliseBookingCheckout(Booking $booking, ?User $user = null): void
    {
        /** @var User|null $actingUser */
        $actingUser = $user ?? Auth::user();

        $folio = $booking->folio()->first();

        if ($folio !== null) {
            // ADR-48: canonical lock order — Booking (held by caller) → Folio.
            $lockedFolio = Folio::lockForUpdate()->findOrFail($folio->id);

            $totalCharges = $this->folios->getFolioTotal($booking);

            // ADR-46: current read of all payments under lock — serialises against concurrent INSERT (OI-7).
            $payments = BookingPayment::where('booking_id', $booking->id)
                ->lockForUpdate()
                ->get(['payment_type', 'amount']);

            $paidTotal = 0.0;
            foreach ($payments as $payment) {
                $paidTotal += match ($payment->payment_type) {
                    PaymentType::Deposit,
                    PaymentType::AdditionalDeposit,
                    PaymentType::RoomPayment,
                    PaymentType::ServicePayment,
                    PaymentType::Adjustment => (float) $payment->amount,
                    PaymentType::Refund     => -1.0 * (float) $payment->amount,
                };
            }

            $balanceDue = (float) bcsub((string) $totalCharges, (string) $paidTotal, 2);

            // ADR-40: outstanding balance is a hard block — throws and rolls back checkout.
            if ($balanceDue > 0) {
                throw new OutstandingBalanceException($balanceDue);
            }

            // ADR-41: auto-close folio atomically under the Folio lock already acquired.
            $this->folios->autoCloseFolio($lockedFolio, $actingUser);
        }

        // Terminal status — Booking lock already held by caller (ADR-48).
        $booking->update([
            'status'     => BookingStatus::CheckedOut,
            'updated_by' => $actingUser?->id ?? Auth::id(),
        ]);
    }

    public function paymentSummary(Booking $booking): array
    {
        $payments = $booking->bookingPayments()->get(['payment_type', 'amount']);

        $totalCharges = $this->folios->getFolioTotal($booking);

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
