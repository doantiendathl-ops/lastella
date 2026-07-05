<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\Room;
use App\Services\RoomAvailabilityCheckerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomAvailabilityController extends Controller
{
    public function __construct(private readonly RoomAvailabilityCheckerService $checker)
    {
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('room_availability.view'), 403);

        $startAt = $request->query('start_at') ?: Carbon::today()->setHour(14)->setMinute(0)->setSecond(0)->format('Y-m-d H:i');
        $endAt = $request->query('end_at') ?: Carbon::tomorrow()->setHour(12)->setMinute(0)->setSecond(0)->format('Y-m-d H:i');

        $validator = validator(
            ['start_at' => $startAt, 'end_at' => $endAt],
            [
                'start_at' => ['required', 'date'],
                'end_at' => ['required', 'date', 'after:start_at'],
            ],
        );

        if ($validator->fails()) {
            $startAt = Carbon::today()->setHour(14)->setMinute(0)->setSecond(0)->format('Y-m-d H:i');
            $endAt = Carbon::tomorrow()->setHour(12)->setMinute(0)->setSecond(0)->format('Y-m-d H:i');
        }

        $roomIds = Room::query()->pluck('id')->all();

        return Inertia::render('Admin/RoomAvailability/Index', [
            'availability'         => $this->checker->check($startAt, $endAt),
            'filters'              => ['start_at' => $startAt, 'end_at' => $endAt],
            'pendingRequestCounts' => BookingSpecialRequest::pendingCountByRoom($roomIds),
            'can' => [
                'viewBooking' => $request->user()?->can('viewAny', Booking::class) ?? false,
            ],
        ]);
    }
}
