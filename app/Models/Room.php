<?php

namespace App\Models;

use App\Enums\CleaningStatus;
use App\Enums\HousekeepingAssignmentStatus;
use App\Enums\RoomStatus;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'resource_id',
        'floor_id',
        'room_type_id',
        'room_number',
        'status',
        'cleaning_status',
        'bed_configuration',
        'notes',
        'last_cleaned_at',
    ];

    protected function casts(): array
    {
        return [
            'status'           => RoomStatus::class,
            'cleaning_status'  => CleaningStatus::class,
            'bed_configuration' => 'array',
            'last_cleaned_at'  => 'datetime',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function housekeepingAssignments(): HasMany
    {
        return $this->hasMany(HousekeepingAssignment::class);
    }

    public function cleaningRecords(): HasMany
    {
        return $this->hasMany(CleaningRecord::class);
    }

    public function activeHousekeepingAssignment(): HasOne
    {
        return $this->hasOne(HousekeepingAssignment::class)
            ->whereIn('status', HousekeepingAssignmentStatus::activeValues());
    }

    public function checkoutInspections(): HasMany
    {
        return $this->hasMany(CheckoutInspection::class);
    }

    /**
     * Room Operations Simplification — Final Consistency Review: falls back to
     * RoomStatus::impliedCleaningStatus() (the single centralized mapping) when
     * `cleaning_status` is null (defensive — the backfill migration already sets it
     * on every existing row) so older/unmigrated rows never show a missing
     * cleanliness signal. `cleaning_status` is the source of truth whenever present;
     * this fallback never overrides a persisted value.
     */
    public function normalizedCleaningStatus(): CleaningStatus
    {
        return $this->cleaning_status ?? $this->status->impliedCleaningStatus();
    }

    /**
     * Named isRoomClean()/isRoomDirty() (not isClean()/isDirty()) — those names
     * are already taken by Eloquent's own attribute-change-tracking methods.
     */
    public function isRoomClean(): bool
    {
        return $this->normalizedCleaningStatus() === CleaningStatus::Clean;
    }

    public function isRoomDirty(): bool
    {
        return $this->normalizedCleaningStatus() === CleaningStatus::Dirty;
    }
}
