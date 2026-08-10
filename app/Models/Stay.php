<?php

namespace App\Models;

use App\Enums\StayStatus;
use Database\Factories\StayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Stay extends Model
{
    /** @use HasFactory<StayFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'room_assignment_id',
        'room_id',
        'planned_checkin_at',
        'planned_checkout_at',
        'actual_checkin_at',
        'actual_checkout_at',
        'status',
        'checked_in_by',
        'checked_out_by',
        'note',
        'inspection_skipped_at',
        'inspection_skipped_by',
        'inspection_skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'planned_checkin_at' => 'datetime',
            'planned_checkout_at' => 'datetime',
            'actual_checkin_at' => 'datetime',
            'actual_checkout_at' => 'datetime',
            'status' => StayStatus::class,
            'inspection_skipped_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function roomAssignment(): BelongsTo
    {
        return $this->belongsTo(RoomAssignment::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function specialRequests(): HasMany
    {
        return $this->hasMany(BookingSpecialRequest::class);
    }

    public function stayEvents(): HasMany
    {
        return $this->hasMany(StayEvent::class);
    }

    public function checkoutInspection(): HasOne
    {
        return $this->hasOne(CheckoutInspection::class);
    }

    /**
     * Canonical checkout-inspection status source — originally lived as
     * BookingController::inspectionStatusFor(), moved here (Daily Room
     * Operations Board Mục XXIV) so the Booking Detail screen and the Room
     * Operations Board read the exact same logic instead of two independent
     * copies. Returns 'completed' | 'draft' | 'skipped' | 'none'.
     */
    public function inspectionStatus(): string
    {
        if ($this->checkoutInspection?->status === \App\Enums\CheckoutInspectionStatus::Completed) {
            return 'completed';
        }

        if ($this->checkoutInspection !== null) {
            return 'draft';
        }

        if ($this->inspection_skipped_at !== null) {
            return 'skipped';
        }

        return 'none';
    }

    public function inspectionSkippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspection_skipped_by');
    }
}
