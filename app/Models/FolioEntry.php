<?php

namespace App\Models;

use App\Enums\ChargeType;
use Database\Factories\FolioEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioEntry extends Model
{
    /** @use HasFactory<FolioEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'folio_id',
        'posting_key',
        'charge_type',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'entry_date',
        'posted_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'charge_type' => ChargeType::class,
            'quantity'    => 'decimal:2',
            'unit_price'  => 'decimal:2',
            'amount'      => 'decimal:2',
            'entry_date'  => 'date',
            'voided_at'   => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
