<?php

namespace App\Services\Posting;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\NightAuditRun;
use App\Models\Stay;
use App\Models\User;
use Carbon\Carbon;

final class PostingContext
{
    public function __construct(
        public readonly Booking $booking,
        public readonly Folio $folio,
        public readonly Carbon $businessDate,
        public readonly ?Stay $stay = null,
        public readonly ?User $postedBy = null,
        // User request (2026-08-22/23 chat) — "cửa sổ chờ xác nhận 24h": set
        // ONLY when this posting comes from NightAuditPipeline::run() (the
        // scheduled/manual Night Audit sweep). Every posting job stamps this
        // onto the FolioEntry it creates (night_audit_run_id) so the row
        // stays voidable until FolioService::voidEntry()'s relaxed guard
        // considers it finalized. Deliberately null for postings OUTSIDE the
        // sweep (e.g. StayService::checkIn()'s own immediate room-charge
        // post, the late-checkout/early-checkin fee jobs) — those keep the
        // ORIGINAL immediate-immutable behavior unchanged; this feature is
        // scoped to Night Audit RUN postings only, not every automated post.
        public readonly ?NightAuditRun $nightAuditRun = null,
    ) {}
}
