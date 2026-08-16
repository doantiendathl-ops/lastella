<?php

namespace App\Models;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceFulfillmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — one booking-side
 * "service transaction". suggested_price/actual_price/price_override_reason
 * are a permanent snapshot taken at creation time (Section 6) — never
 * recomputed when Service's canonical price changes later.
 */
class BookingService extends Model
{
    protected $fillable = [
        'booking_id',
        'service_id',
        'room_assignment_id',
        'quantity',
        'billing_mode_selected',
        'suggested_price',
        'actual_price',
        'price_override_reason',
        'fulfillment_status',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'completed_by',
        'completed_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'billing_mode_selected' => ServiceBillingMode::class,
            'suggested_price' => 'decimal:2',
            'actual_price' => 'decimal:2',
            'fulfillment_status' => ServiceFulfillmentStatus::class,
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function roomAssignment(): BelongsTo
    {
        return $this->belongsTo(RoomAssignment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** True when the configured price differs from the snapshot suggested price (drives price_override_reason requirement). */
    public function isPriceOverridden(): bool
    {
        return bccomp((string) $this->actual_price, (string) $this->suggested_price, 2) !== 0;
    }
}
