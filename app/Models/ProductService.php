<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Enums\ProductServiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductService extends Model
{
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'type',
        'unit',
        'price',
        'free_quantity_default',
        'use_in_checkout_inspection',
        'can_add_to_booking',
        'is_active',
        'sort_order',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductServiceType::class,
            'price' => 'decimal:2',
            'free_quantity_default' => 'integer',
            'use_in_checkout_inspection' => 'boolean',
            'can_add_to_booking' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductServiceCategory::class, 'category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeUsableInCheckoutInspection($query)
    {
        return $query->where('is_active', true)->where('use_in_checkout_inspection', true);
    }

    public function scopeAddableToBooking($query)
    {
        return $query->where('is_active', true)->where('can_add_to_booking', true);
    }

    /**
     * Maps this product's category to the fixed accounting ChargeType bucket used by
     * folio_entries — single source of truth, reused by both checkout-inspection posting
     * and the "add product/service to booking" folio charge flow.
     */
    public function resolveChargeType(): ChargeType
    {
        return match ($this->category?->code) {
            'MINIBAR' => ChargeType::Minibar,
            'FOOD_BEVERAGE' => ChargeType::FoodBeverage,
            'LAUNDRY' => ChargeType::Laundry,
            'TRANSPORT' => ChargeType::Transport,
            'DAMAGE' => ChargeType::Damage,
            default => ChargeType::Other,
        };
    }
}
