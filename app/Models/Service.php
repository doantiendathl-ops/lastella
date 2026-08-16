<?php

namespace App\Models;

use App\Enums\ServiceBillingMode;
use App\Enums\ServiceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt) — the single Service
 * Catalog record. `code` is a human-readable identifier only — no runtime
 * business logic in this system may compare against it as a literal
 * string (Section 25); scope/billing_mode/quantity_enabled/is_chargeable
 * are the actual configuration business logic reads.
 */
class Service extends Model
{
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'description',
        'is_chargeable',
        'scope',
        'billing_mode',
        'quantity_enabled',
        'default_quantity',
        'unit_label',
        'fulfillment_required',
        'is_active',
        'is_bookable',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_chargeable' => 'boolean',
            'scope' => ServiceScope::class,
            'billing_mode' => ServiceBillingMode::class,
            'quantity_enabled' => 'boolean',
            'default_quantity' => 'integer',
            'fulfillment_required' => 'boolean',
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ServicePrice::class);
    }

    public function bookingServices(): HasMany
    {
        return $this->hasMany(BookingService::class);
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

    /** Resolves the price in effect for the given business date, latest-created wins on ties. Canonical resolver is ServicePricingResolver — call that, not this, from business logic that needs to reuse the same rule everywhere. */
    public function currentPrice(string $businessDate): ?ServicePrice
    {
        return $this->prices()
            ->active()
            ->effectiveOn($businessDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A service is "used" once it has any price history or has ever been
     * attached to a booking_services row. Mirrors ServicePackage::hasBeenUsed().
     */
    public function hasBeenUsed(): bool
    {
        return $this->prices()->exists() || $this->bookingServices()->exists();
    }

    /**
     * Defense-in-depth guard (mirrors ServicePackage::booted()): identity
     * fields that the posting/enrollment engine depends on to interpret
     * already-existing booking_services rows become immutable once used.
     * The admin Controller/FormRequest are expected to enforce the same
     * rule before it ever reaches the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Service $service): void {
            if (! $service->exists) {
                return;
            }

            $lockedFields = ['scope', 'billing_mode', 'quantity_enabled', 'is_chargeable'];
            $changedLockedFields = array_intersect($lockedFields, array_keys($service->getDirty()));

            if ($changedLockedFields !== [] && $service->hasBeenUsed()) {
                throw new InvalidArgumentException(
                    'Cannot change ['.implode(', ', $changedLockedFields).'] after the service has been used (has prices or booking_services history).'
                );
            }
        });
    }
}
