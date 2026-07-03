<?php

namespace App\Models;

use Database\Factories\NightAuditRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NightAuditRun extends Model
{
    /** @use HasFactory<NightAuditRunFactory> */
    use HasFactory;
    protected $fillable = [
        'business_date',
        'status',
        'stays_processed',
        'entries_posted',
        'entries_skipped',
        'run_by',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'business_date'  => 'date:Y-m-d',
            'started_at'     => 'datetime',
            'completed_at'   => 'datetime',
        ];
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function bookingLogs(): HasMany
    {
        return $this->hasMany(NightAuditBookingLog::class, 'run_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isRunning(): bool
    {
        return $this->status === 'RUNNING';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'COMPLETED';
    }

    public function isFailed(): bool
    {
        return $this->status === 'FAILED';
    }
}
