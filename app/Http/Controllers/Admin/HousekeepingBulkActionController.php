<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HousekeepingAssignmentStatus;
use App\Http\Controllers\Concerns\RunsPerRoomBulkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkHousekeepingActionRequest;
use App\Http\Requests\Admin\BulkHousekeepingTransitionRequest;
use App\Http\Requests\Admin\BulkMarkCleaningRequest;
use App\Models\HousekeepingAssignment;
use App\Models\Room;
use App\Services\HousekeepingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class HousekeepingBulkActionController extends Controller
{
    use RunsPerRoomBulkAction;

    public function __construct(private readonly HousekeepingService $housekeeping)
    {
    }

    /**
     * Room Operations Simplification: bulk "Đánh dấu sạch" / "Đánh dấu bẩn" —
     * the only two bulk actions the simplified Housekeeping board exposes.
     * Each room is re-validated independently by HousekeepingService (maintenance
     * rooms rejected, Occupied rooms only flip cleaning_status) — see runPerRoom().
     */
    public function bulkMarkClean(BulkMarkCleaningRequest $request): JsonResponse
    {
        return response()->json($this->runPerRoom($request->validated()['room_ids'], function (Room $room) use ($request): void {
            $this->housekeeping->markClean($room, $request->user());
        }));
    }

    public function bulkMarkDirty(BulkMarkCleaningRequest $request): JsonResponse
    {
        return response()->json($this->runPerRoom($request->validated()['room_ids'], function (Room $room) use ($request): void {
            $this->housekeeping->markDirty($room, $request->user());
        }));
    }

    /**
     * Bulk "Chờ dọn" — assigns the acting user to each selected VacantDirty room
     * with no active assignment yet (reuses HousekeepingService::assignRoom()).
     */
    public function bulkAssign(BulkHousekeepingActionRequest $request): JsonResponse
    {
        return response()->json($this->runPerRoom($request->validated()['room_ids'], function (Room $room) use ($request): void {
            $this->housekeeping->assignRoom(room: $room, assignee: null, actor: $request->user());
        }));
    }

    /**
     * Bulk "Đang dọn" — starts cleaning on each selected room's active Pending assignment.
     * A room without a pending assignment (state changed since page load, or never assigned)
     * fails independently with a clear reason — never silently skipped or overwritten.
     */
    public function bulkStart(BulkHousekeepingTransitionRequest $request): JsonResponse
    {
        return response()->json($this->runPerRoom($request->validated()['room_ids'], function (Room $room) use ($request): void {
            $assignment = $this->activeAssignmentOrFail($room, HousekeepingAssignmentStatus::Pending);
            $this->housekeeping->startCleaning($assignment, $request->user());
        }));
    }

    /**
     * Bulk "Đã xong" — completes cleaning on each selected room's active InProgress assignment.
     */
    public function bulkComplete(BulkHousekeepingTransitionRequest $request): JsonResponse
    {
        return response()->json($this->runPerRoom($request->validated()['room_ids'], function (Room $room) use ($request): void {
            $assignment = $this->activeAssignmentOrFail($room, HousekeepingAssignmentStatus::InProgress);
            $this->housekeeping->completeCleaning($assignment, $request->user());
        }));
    }

    private function activeAssignmentOrFail(Room $room, HousekeepingAssignmentStatus $expected): HousekeepingAssignment
    {
        $assignment = $room->activeHousekeepingAssignment()->first();

        if ($assignment === null || $assignment->status !== $expected) {
            throw ValidationException::withMessages([
                'assignment' => 'Trạng thái dọn phòng đã thay đổi, vui lòng tải lại trang.',
            ]);
        }

        return $assignment;
    }
}
