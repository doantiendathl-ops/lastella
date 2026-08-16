<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Additive price history row for a Service (docs/yeucaumoi.txt Section 5).
 * Never updated in place once created — a price change always inserts a
 * new row (see ServicePricingResolver / ServiceCatalogController).
 */
class ServicePrice extends Model
{
    protected $fillable = [
        'service_id',
        'unit_price',
        'effective_from',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEffectiveOn(Builder $query, string $businessDate): Builder
    {
        return $query->whereDate('effective_from', '<=', $businessDate);
    }
}
