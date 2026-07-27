<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutInspectionItem extends Model
{
    protected $fillable = [
        'checkout_inspection_id',
        'product_service_id',
        'product_code_snapshot',
        'product_name_snapshot',
        'unit_snapshot',
        'unit_price_snapshot',
        'free_quantity',
        'actual_quantity',
        'chargeable_quantity',
        'line_total',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_snapshot' => 'decimal:2',
            'free_quantity' => 'integer',
            'actual_quantity' => 'integer',
            'chargeable_quantity' => 'integer',
            'line_total' => 'decimal:2',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(CheckoutInspection::class, 'checkout_inspection_id');
    }

    public function productService(): BelongsTo
    {
        return $this->belongsTo(ProductService::class);
    }
}
