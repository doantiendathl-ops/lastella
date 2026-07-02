<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NightAuditBookingLog extends Model
{
    protected $fillable = [
        'run_id',
        'booking_id',
        'stay_id',
        'job_class',
        'result',
        'posting_key',
        'message',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(NightAuditRun::class, 'run_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }
}
