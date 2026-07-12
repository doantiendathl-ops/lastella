<?php

namespace App\Models;

use App\Enums\StayEventType;
use Database\Factories\StayEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StayEvent extends Model
{
    /** @use HasFactory<StayEventFactory> */
    use HasFactory;

    protected $attributes = [
        'metadata' => '[]',
    ];

    protected $fillable = [
        'stay_id',
        'event_type',
        'actor_id',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => StayEventType::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
