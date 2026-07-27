<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomStatus;
use App\Http\Controllers\Concerns\RunsPerRoomBulkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkRoomOutOfOrderRequest;
use App\Http\Requests\Admin\BulkRoomReleaseRequest;
use App\Models\Room;
use App\Services\HousekeepingService;
use Illuminate\Http\JsonResponse;

class RoomBulkActionController extends Controller
{
    use RunsPerRoomBulkAction;

    public function __construct(private readonly HousekeepingService $housekeeping)
    {
    }

    public function markOutOfOrder(BulkRoomOutOfOrderRequest $request): JsonResponse
    {
        $this->authorize('bulkUpdate', Room::class);

        $data = $request->validated();

        return response()->json($this->runPerRoom($data['room_ids'], function (Room $room) use ($data, $request): void {
            $this->housekeeping->markOutOfOrder($room, $request->user(), $data['reason']);
        }));
    }

    public function release(BulkRoomReleaseRequest $request): JsonResponse
    {
        $this->authorize('bulkUpdate', Room::class);

        $data = $request->validated();
        $target = isset($data['target_status']) ? RoomStatus::from($data['target_status']) : RoomStatus::VacantDirty;

        return response()->json($this->runPerRoom($data['room_ids'], function (Room $room) use ($target, $request): void {
            $this->housekeeping->releaseFromOutOfOrder($room, $request->user(), $target);
        }));
    }

}
