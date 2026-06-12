<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_public' => 'boolean',
        ];
    }

    public function getTypedValueAttribute(): mixed
    {
        $value = $this->value['value'] ?? null;

        return match ($this->type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'time', 'string' => $value,
            default => $value,
        };
    }
}
