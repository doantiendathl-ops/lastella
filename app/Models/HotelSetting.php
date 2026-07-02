<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelSetting extends Model
{
    public $timestamps = false;

    protected $table = 'hotel_settings';

    protected $fillable = [
        'key',
        'value',
        'value_type',
        'description',
        'updated_by',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
