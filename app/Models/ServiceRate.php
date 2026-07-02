<?php

namespace App\Models;

use App\Enums\ChargeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRate extends Model
{
    protected $fillable = [
        'name',
        'charge_type',
        'unit_price',
        'effective_from',
        'unit_label',
        'tax_rate',
        'gl_account_code',
        'is_active',
        'display_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price'     => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
            'tax_rate'       => 'decimal:4',
            'is_active'      => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->whereDate('effective_from', '<=', $date);
    }
}
