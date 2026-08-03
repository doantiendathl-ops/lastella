<?php

namespace App\Models;

use App\Enums\PackageCalculationStrategy;
use App\Enums\PackagePostingFrequency;
use App\Enums\PackageQuantityMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ServicePackage extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'charge_type',
        'calculation_strategy',
        'quantity_mode',
        'default_quantity',
        'unit_label',
        'posting_frequency',
        'is_active',
        'is_bookable',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'default_quantity' => 'integer',
            'is_active'        => 'boolean',
            'is_bookable'      => 'boolean',
            'display_order'    => 'integer',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ServicePackageRate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_bookable', true);
    }

    /**
     * Milestone-1 domain invariant: a package must never be saved with a
     * strategy/quantity-mode/posting-frequency the posting engine cannot
     * actually execute yet. This is a defense-in-depth guard — Milestone 2's
     * admin form is expected to validate the same rule before it ever
     * reaches the model.
     */
    protected static function booted(): void
    {
        static::saving(function (ServicePackage $package): void {
            $strategy = PackageCalculationStrategy::tryFrom((string) $package->calculation_strategy);
            if ($strategy === null || ! $strategy->isImplemented()) {
                throw new InvalidArgumentException(
                    "calculation_strategy [{$package->calculation_strategy}] is not implemented yet."
                );
            }

            $quantityMode = PackageQuantityMode::tryFrom((string) $package->quantity_mode);
            if ($quantityMode === null || ! $quantityMode->isImplemented()) {
                throw new InvalidArgumentException(
                    "quantity_mode [{$package->quantity_mode}] is not implemented yet."
                );
            }

            $frequency = PackagePostingFrequency::tryFrom((string) $package->posting_frequency);
            if ($frequency === null || ! $frequency->isImplemented()) {
                throw new InvalidArgumentException(
                    "posting_frequency [{$package->posting_frequency}] is not implemented yet."
                );
            }

            if (! self::isQuantityModeCompatible($strategy, $quantityMode)) {
                throw new InvalidArgumentException(
                    "quantity_mode [{$quantityMode->value}] is not compatible with calculation_strategy [{$strategy->value}]."
                );
            }
        });
    }

    private static function isQuantityModeCompatible(
        PackageCalculationStrategy $strategy,
        PackageQuantityMode $quantityMode,
    ): bool {
        return match ($strategy) {
            PackageCalculationStrategy::OncePerStayPerNight => $quantityMode === PackageQuantityMode::None,
            PackageCalculationStrategy::ManualQuantityPerNight => $quantityMode === PackageQuantityMode::ManualInput,
            default => false,
        };
    }
}
