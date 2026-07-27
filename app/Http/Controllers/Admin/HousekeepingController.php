<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Enums\RoomStatus;
use App\Enums\StayStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\AssignHousekeepingRequest;
use App\Http\Requests\Housekeeping\CompleteCleaningRequest;
use App\Http\Requests\Housekeeping\InspectionRequest;
use App\Http\Requests\Housekeeping\MarkCleanRequest;
use App\Http\Requests\Housekeeping\MarkDirtyRequest;
use App\Http\Requests\Housekeeping\OutOfOrderRequest;
use App\Http\Requests\Housekeeping\ReleaseFromOutOfOrderRequest;
use App\Http\Requests\Housekeeping\StartCleaningRequest;
use App\Models\Booking;
use App\Models\BookingSpecialRequest;
use App\Models\Floor;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\HousekeepingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class HousekeepingController extends Controller
{
    public function __construct(private readonly HousekeepingService $housekeeping)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('view', HousekeepingAssignment::class);

        $canViewBooking = $request->user()?->can('viewAny', Booking::class) ?? false;
        $today = today();

        $rooms = Room::with([
            'roomType',
            'floor',
            'activeHousekeepingAssignment.assignedTo',
            'stays' => fn ($q) => $q->whereIn('status', [StayStatus::CheckedIn->value, StayStatus::Reserved->value])
                ->with('booking:id,customer_name')
                ->orderByDesc('planned_checkin_at'),
        ])->get();

        $pendingRequestCounts = BookingSpecialRequest::pendingCountByRoom($rooms->pluck('id')->all());

        $maintenanceStatuses = [RoomStatus::OutOfOrder, RoomStatus::OutOfService];

        $roomRows = $rooms->map(function (Room $room) use ($canViewBooking, $today, $pendingRequestCounts, $maintenanceStatuses): array {
            /** @var Stay|null $currentStay */
            $currentStay = $room->stays->firstWhere('status', StayStatus::CheckedIn) ?? $room->stays->first();

            return [
                'id'               => $room->id,
                'floor_id'         => $room->floor_id,
                'room_number'      => $room->room_number,
                'status'           => $room->status->value,
                'status_label'     => $room->status->label(),
                'cleaning_status'       => $room->normalizedCleaningStatus()->value,
                'cleaning_status_label' => $room->normalizedCleaningStatus()->label(),
                'operational_status_label' => $room->status->operationalLabel(),
                'is_occupied'      => $room->status === RoomStatus::Occupied,
                'is_maintenance'   => in_array($room->status, $maintenanceStatuses, true),
                'is_eligible_for_bulk' => ! in_array($room->status, $maintenanceStatuses, true),
                'last_cleaned_at'  => $room->last_cleaned_at?->toDateTimeString(),
                'active_assignment' => $room->activeHousekeepingAssignment === null ? null : [
                    'id'          => $room->activeHousekeepingAssignment->id,
                    'status'      => $room->activeHousekeepingAssignment->status->value,
                    'assigned_to' => $room->activeHousekeepingAssignment->assignedTo?->name,
                    'priority'    => $room->activeHousekeepingAssignment->priority->value,
                    'updated_at'  => $room->activeHousekeepingAssignment->updated_at?->toDateTimeString(),
                ],
                'guest_name'            => $canViewBooking ? $currentStay?->booking?->customer_name : null,
                'booking_id'            => $canViewBooking ? $currentStay?->booking_id : null,
                'is_checkin_today'      => $currentStay?->planned_checkin_at?->isSameDay($today) ?? false,
                'is_checkout_today'     => $currentStay?->planned_checkout_at?->isSameDay($today) ?? false,
                'special_request_count' => $pendingRequestCounts[$room->id] ?? 0,
            ];
        });

        $floors = Floor::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Floor $floor): array => [
                'id' => $floor->id,
                'code' => $floor->code,
                'name' => $floor->name,
                'rooms' => $roomRows->where('floor_id', $floor->id)->sortBy('room_number')->values(),
            ])
            ->filter(fn (array $f): bool => count($f['rooms']) > 0)
            ->values();

        return Inertia::render('Admin/Housekeeping/Index', [
            'floors' => $floors,
            'can'   => [
                'markCleaning' => $request->user()->can('markCleaning', HousekeepingAssignment::class),
                'assign'       => $request->user()->can('assign', HousekeepingAssignment::class),
                'updateStatus' => $request->user()->can('updateStatus', HousekeepingAssignment::class),
                'inspect'      => $request->user()->can('inspect', HousekeepingAssignment::class),
                'maintenance'  => $request->user()->can('maintenance', HousekeepingAssignment::class),
            ],
        ]);
    }

    public function show(Request $request, Room $room): JsonResponse
    {
        $this->authorize('view', HousekeepingAssignment::class);

        $canViewBooking = $request->user()?->can('viewAny', Booking::class) ?? false;

        $room->load(['roomType', 'floor', 'activeHousekeepingAssignment.assignedTo']);

        $currentStay = Stay::where('room_id', $room->id)
            ->whereIn('status', [StayStatus::CheckedIn->value, StayStatus::Reserved->value])
            ->with(['booking', 'specialRequests' => fn ($q) => $q->latest()->limit(10)])
            ->orderByDesc('planned_checkin_at')
            ->first();

        $recentCleanings = $room->cleaningRecords()
            ->with(['cleanedBy', 'inspectedBy'])
            ->latest('started_at')
            ->limit(5)
            ->get()
            ->map(fn ($record): array => [
                'started_at' => $record->started_at?->toDateTimeString(),
                'completed_at' => $record->completed_at?->toDateTimeString(),
                'cleaned_by' => $record->cleanedBy?->name,
                'reason_label' => $record->reason?->label(),
                'room_status_before' => $record->room_status_before,
                'room_status_after' => $record->room_status_after,
                'cleaning_notes' => $record->cleaning_notes,
                'inspection_result' => $record->inspection_result?->value,
                'inspected_by' => $record->inspectedBy?->name,
                'inspected_at' => $record->inspected_at?->toDateTimeString(),
            ]);

        return response()->json([
            'room' => [
                'id' => $room->id,
                'room_number' => $room->room_number,
                'room_type' => $room->roomType?->name,
                'status' => $room->status->value,
                'status_label' => $room->status->label(),
                'cleaning_status' => $room->normalizedCleaningStatus()->value,
                'cleaning_status_label' => $room->normalizedCleaningStatus()->label(),
                'operational_status_label' => $room->status->operationalLabel(),
                'notes' => $room->notes,
            ],
            'active_assignment' => $room->activeHousekeepingAssignment === null ? null : [
                'status' => $room->activeHousekeepingAssignment->status->value,
                'assigned_to' => $room->activeHousekeepingAssignment->assignedTo?->name,
                'notes' => $room->activeHousekeepingAssignment->notes,
                'updated_at' => $room->activeHousekeepingAssignment->updated_at?->toDateTimeString(),
            ],
            'stay' => $currentStay === null ? null : [
                'guest_name' => $canViewBooking ? $currentStay->booking?->customer_name : null,
                'checked_in_at' => $currentStay->actual_checkin_at?->toDateTimeString(),
                'planned_checkout_at' => $currentStay->planned_checkout_at?->toDateTimeString(),
                'note' => $currentStay->note,
                'special_requests' => $currentStay->specialRequests->map(fn ($r) => [
                    'note' => $r->note,
                    'status' => $r->status->value,
                ]),
            ],
            'recent_cleanings' => $recentCleanings,
        ]);
    }

    public function markClean(MarkCleanRequest $request, Room $room): JsonResponse
    {
        $result = $this->housekeeping->markClean($room, $request->user(), $request->validated()['notes'] ?? null);

        return response()->json($result);
    }

    public function markDirty(MarkDirtyRequest $request, Room $room): JsonResponse
    {
        $result = $this->housekeeping->markDirty($room, $request->user(), $request->validated()['notes'] ?? null);

        return response()->json($result);
    }

    public function assign(AssignHousekeepingRequest $request, Room $room): JsonResponse
    {
        $data = $request->validated();

        $assignment = $this->housekeeping->assignRoom(
            room: $room,
            assignee: isset($data['assigned_to']) ? User::find($data['assigned_to']) : null,
            actor: $request->user(),
            priority: isset($data['priority']) ? CleaningPriority::from($data['priority']) : CleaningPriority::Normal,
            reason: isset($data['reason']) ? CleaningReason::from($data['reason']) : CleaningReason::Checkout,
            notes: $data['notes'] ?? null,
        );

        return response()->json($assignment, 201);
    }

    public function startCleaning(StartCleaningRequest $request, HousekeepingAssignment $assignment): JsonResponse
    {
        $result = $this->housekeeping->startCleaning($assignment, $request->user());

        return response()->json($result);
    }

    public function completeCleaning(CompleteCleaningRequest $request, HousekeepingAssignment $assignment): JsonResponse
    {
        $record = $this->housekeeping->completeCleaning($assignment, $request->user(), $request->validated()['notes'] ?? null);

        return response()->json($record);
    }

    public function passInspection(InspectionRequest $request, Room $room): JsonResponse
    {
        $record = $this->housekeeping->passInspection($room, $request->user(), $request->validated()['notes'] ?? null);

        return response()->json($record);
    }

    public function failInspection(InspectionRequest $request, Room $room): JsonResponse
    {
        $record = $this->housekeeping->failInspection($room, $request->user(), $request->validated()['notes'] ?? null);

        return response()->json($record);
    }

    public function skipInspection(InspectionRequest $request, Room $room): JsonResponse
    {
        $record = $this->housekeeping->skipInspection($room, $request->user(), $request->validated()['notes']);

        return response()->json($record);
    }

    public function markOutOfOrder(OutOfOrderRequest $request, Room $room): JsonResponse
    {
        $result = $this->housekeeping->markOutOfOrder($room, $request->user(), $request->validated()['reason']);

        return response()->json($result);
    }

    public function releaseFromOutOfOrder(ReleaseFromOutOfOrderRequest $request, Room $room): JsonResponse
    {
        $data = $request->validated();
        $targetStatus = isset($data['target_status']) ? RoomStatus::from($data['target_status']) : RoomStatus::VacantDirty;

        $result = $this->housekeeping->releaseFromOutOfOrder($room, $request->user(), $targetStatus);

        return response()->json($result);
    }
}
