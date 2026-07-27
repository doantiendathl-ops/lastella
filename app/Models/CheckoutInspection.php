<?php

namespace App\Models;

use App\Enums\CheckoutInspectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckoutInspection extends Model
{
    protected $fillable = [
        'booking_id',
        'stay_id',
        'room_id',
        'folio_id',
        'status',
        'total_amount',
        'note',
        'created_by',
        'completed_by',
        'completed_at',
        'posted_at',
        'posting_batch_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => CheckoutInspectionStatus::class,
            'total_amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutInspectionItem::class);
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }
}
