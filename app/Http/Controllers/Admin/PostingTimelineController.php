<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\PostingTimelineService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostingTimelineController extends Controller
{
    public function show(Request $request, Booking $booking, PostingTimelineService $service): Response
    {
        abort_unless($request->user()?->can('folio.view'), 403);

        $booking->load('folio');

        $includeVoided = $request->user()?->can('charge.void') ?? false;

        $timeline = $booking->folio !== null
            ? $service->forFolio($booking->folio, $includeVoided)
            : [];

        return Inertia::render('Admin/Booking/PostingTimeline', [
            'booking' => [
                'id'            => $booking->id,
                'booking_code'  => $booking->booking_code,
                'customer_name' => $booking->customer_name,
                'status'        => $booking->status->value,
            ],
            'timeline'      => $timeline,
            'includeVoided' => $includeVoided,
            'folio_status'  => $booking->folio?->status->value,
        ]);
    }
}
