<?php

namespace App\Models;

use App\Enums\RequestCategory;
use App\Enums\RequestStatus;
use Database\Factories\BookingSpecialRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BookingSpecialRequest extends Model
{
    /** @use HasFactory<BookingSpecialRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'stay_id',
        'category',
        'request_type',
        'quantity',
        'note',
        'status',
        'requested_by',
        'acknowledged_by',
        'acknowledged_at',
        'fulfilled_by',
        'fulfilled_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'category'        => RequestCategory::class,
            'status'          => RequestStatus::class,
            'quantity'        => 'integer',
            'acknowledged_at' => 'datetime',
            'fulfilled_at'    => 'datetime',
            'cancelled_at'    => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::Pending->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            RequestStatus::Fulfilled->value,
            RequestStatus::Cancelled->value,
        ]);
    }

    /**
     * Count pending/acknowledged requests per room for the Room Board view.
     * Returns a Collection keyed by room_id with integer pending count as value.
     * Skips requests with stay_id = null (not yet attributed to a room).
     */
    public static function pendingCountByRoom(array $roomIds): Collection
    {
        if (empty($roomIds)) {
            return collect();
        }

        return self::query()
            ->join('stays', 'stays.id', '=', 'booking_special_requests.stay_id')
            ->whereIn('booking_special_requests.status', [
                RequestStatus::Pending->value,
                RequestStatus::Acknowledged->value,
            ])
            ->whereIn('stays.room_id', $roomIds)
            ->groupBy('stays.room_id')
            ->select('stays.room_id', DB::raw('COUNT(*) as pending_count'))
            ->pluck('pending_count', 'room_id')
            ->map(fn ($count) => (int) $count);
    }
}
