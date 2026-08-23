<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Models\Stay;
use Database\Factories\FolioEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolioEntry extends Model
{
    /** @use HasFactory<FolioEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'folio_id',
        'night_audit_run_id',
        'stay_id',
        'posting_key',
        'posting_source',
        'charge_type',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'entry_date',
        'posted_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'charge_type'  => ChargeType::class,
            'quantity'     => 'decimal:2',
            'unit_price'   => 'decimal:2',
            'amount'       => 'decimal:2',
            'entry_date'   => 'date',
            'voided_at'    => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function nightAuditRun(): BelongsTo
    {
        return $this->belongsTo(NightAuditRun::class);
    }

    /**
     * User request (2026-08-22/23 chat) — "cửa sổ chờ xác nhận 24h": true
     * for a Night Audit posting that has been confirmed (either the whole
     * run was confirmed at the next run, or this specific stay's rows were
     * sealed early at checkout). A row with no night_audit_run_id at all
     * (manual charge, or a system charge outside the Night Audit family —
     * late checkout fee, early check-in fee, checkout inspection) is NEVER
     * "finalized" in this sense; its own posting_key rule (see
     * FolioService::voidEntry()) governs it unchanged.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
