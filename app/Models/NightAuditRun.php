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
        'confirmed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'business_date'  => 'date:Y-m-d',
            'started_at'     => 'datetime',
            'completed_at'   => 'datetime',
            'confirmed_at'   => 'datetime',
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

    public function folioEntries(): HasMany
    {
        return $this->hasMany(FolioEntry::class);
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

    /**
     * User request (2026-08-22/23 chat) — "cửa sổ chờ xác nhận 24h": distinct
     * from status (PENDING here means "chưa CHẠY" — an unrelated, pre-existing
     * meaning; deliberately NOT reusing that word for this). A COMPLETED run
     * is confirmed once confirmed_at is set — by
     * NightAuditService::confirmPendingRuns() when the NEXT run starts.
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Still within its correction window: posted, not yet confirmed. */
    public function isAwaitingConfirmation(): bool
    {
        return $this->isCompleted() && ! $this->isConfirmed();
    }
}
