<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CleaningPriority;
use App\Enums\CleaningReason;
use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\AssignHousekeepingRequest;
use App\Http\Requests\Housekeeping\CompleteCleaningRequest;
use App\Http\Requests\Housekeeping\InspectionRequest;
use App\Http\Requests\Housekeeping\OutOfOrderRequest;
use App\Http\Requests\Housekeeping\ReleaseFromOutOfOrderRequest;
use App\Http\Requests\Housekeeping\StartCleaningRequest;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
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

        $rooms = Room::with(['roomType', 'floor', 'activeHousekeepingAssignment.assignedTo'])
            ->get()
            ->map(fn (Room $room): array => [
                'id'               => $room->id,
                'room_number'      => $room->room_number,
                'status'           => $room->status->value,
                'status_label'     => $room->status->label(),
                'last_cleaned_at'  => $room->last_cleaned_at?->toDateTimeString(),
                'active_assignment' => $room->activeHousekeepingAssignment === null ? null : [
                    'id'          => $room->activeHousekeepingAssignment->id,
                    'status'      => $room->activeHousekeepingAssignment->status->value,
                    'assigned_to' => $room->activeHousekeepingAssignment->assignedTo?->name,
                    'priority'    => $room->activeHousekeepingAssignment->priority->value,
                ],
            ])
            ->values();

        return Inertia::render('Admin/Housekeeping/Index', [
            'rooms' => $rooms,
            'can'   => [
                'assign'       => $request->user()->can('assign', HousekeepingAssignment::class),
                'updateStatus' => $request->user()->can('updateStatus', HousekeepingAssignment::class),
                'inspect'      => $request->user()->can('inspect', HousekeepingAssignment::class),
                'maintenance'  => $request->user()->can('maintenance', HousekeepingAssignment::class),
            ],
        ]);
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
