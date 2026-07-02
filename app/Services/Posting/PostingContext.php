<?php

namespace App\Services\Posting;

use App\Models\Booking;
use App\Models\Folio;
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
    ) {}
}
