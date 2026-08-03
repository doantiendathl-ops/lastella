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
     * A package is "used" once it has any price history or has ever been
     * enrolled on a booking (booking_package_flags.package_key). Deliberately
     * does not query FolioEntry by name/description — package identity is
     * tracked via code/rates only.
     */
    public function hasBeenUsed(): bool
    {
        return $this->rates()->exists()
            || BookingPackageFlag::where('package_key', $this->getOriginal('code') ?? $this->code)->exists();
    }

    /** Resolves the rate in effect for the given business date, latest-created wins on ties. */
    public function currentRate(string $businessDate): ?ServicePackageRate
    {
        return $this->rates()
            ->active()
            ->effectiveOn($businessDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Milestone-1 domain invariant: a package must never be saved with a
     * strategy/quantity-mode/posting-frequency the posting engine cannot
     * actually execute yet. Milestone 2 adds: once a package has been used
     * (has rates or booking_package_flags history), its identity fields
     * (code/charge_type/calculation_strategy/quantity_mode/posting_frequency)
     * become immutable — this is a defense-in-depth guard; the admin
     * Controller/FormRequest are expected to enforce the same rule before it
     * ever reaches the model.
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

            if (! $strategy->isCompatibleWith($quantityMode)) {
                throw new InvalidArgumentException(
                    "quantity_mode [{$quantityMode->value}] is not compatible with calculation_strategy [{$strategy->value}]."
                );
            }

            if (! $package->exists) {
                return;
            }

            $lockedFields = ['code', 'charge_type', 'calculation_strategy', 'quantity_mode', 'posting_frequency'];
            $changedLockedFields = array_intersect($lockedFields, array_keys($package->getDirty()));

            if ($changedLockedFields !== [] && $package->hasBeenUsed()) {
                throw new InvalidArgumentException(
                    'Cannot change ['.implode(', ', $changedLockedFields).'] after the package has been used (has rates or booking_package_flags history).'
                );
            }
        });
    }
}
