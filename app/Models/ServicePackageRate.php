<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePackageRate extends Model
{
    protected $fillable = [
        'service_package_id',
        'unit_price',
        'effective_from',
        'is_active',
        'tax_rate',
        'gl_account_code',
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

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
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
