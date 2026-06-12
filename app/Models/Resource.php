<?php

namespace App\Models;

use App\Enums\ResourceType;
use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'code',
        'name',
        'description',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => ResourceType::class,
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function room(): HasOne
    {
        return $this->hasOne(Room::class);
    }
}
