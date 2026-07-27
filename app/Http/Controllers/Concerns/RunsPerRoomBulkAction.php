<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Room;
use Illuminate\Validation\ValidationException;
use Throwable;

trait RunsPerRoomBulkAction
{
    /**
     * Runs $action independently for every room ID and collects a success/failure result per
     * room. Never trusts the frontend selection — each room is re-fetched and $action is
     * expected to re-validate/lock it itself (see HousekeepingService / RoomService callers).
     * One room's failure never rolls back or hides another room's success.
     *
     * @param array<int, int> $roomIds
     */
    private function runPerRoom(array $roomIds, callable $action): array
    {
        $rooms = Room::whereIn('id', $roomIds)->get()->keyBy('id');

        $succeeded = [];
        $failed = [];

        foreach ($roomIds as $roomId) {
            $room = $rooms->get($roomId);

            if ($room === null) {
                $failed[] = ['room_id' => $roomId, 'room_number' => null, 'reason' => 'Không tìm thấy phòng.'];
                continue;
            }

            try {
                $action($room);
                $succeeded[] = ['room_id' => $room->id, 'room_number' => $room->room_number];
            } catch (ValidationException $e) {
                $failed[] = ['room_id' => $room->id, 'room_number' => $room->room_number, 'reason' => collect($e->errors())->flatten()->first() ?? 'Không hợp lệ.'];
            } catch (Throwable $e) {
                $failed[] = ['room_id' => $room->id, 'room_number' => $room->room_number, 'reason' => 'Có lỗi xảy ra.'];
            }
        }

        return [
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }
}
