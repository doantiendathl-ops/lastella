<?php

namespace App\Models;

use App\Enums\FolioStatus;
use Database\Factories\FolioFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folio extends Model
{
    /** @use HasFactory<FolioFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'folio_number',
        'currency_code',
        'status',
        'note',
        'created_by',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'status'    => FolioStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function folioEntries(): HasMany
    {
        return $this->hasMany(FolioEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', FolioStatus::Open->value);
    }
}
