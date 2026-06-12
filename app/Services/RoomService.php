<?php

namespace App\Services;

use App\Enums\ResourceType;
use App\Models\Resource;
use App\Models\Room;
use App\Models\RoomType;
use App\Repositories\Eloquent\RoomRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RoomService
{
    public function __construct(private readonly RoomRepository $rooms)
    {
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->rooms->paginate($filters);
    }

    public function create(array $data): Room
    {
        return DB::transaction(function () use ($data): Room {
            $roomType = RoomType::findOrFail($data['room_type_id']);
            $roomNumber = $data['room_number'];

            $resource = Resource::create([
                'type' => ResourceType::Room,
                'code' => "RM-{$roomNumber}",
                'name' => "Room {$roomNumber}",
                'description' => "Hotel room {$roomNumber}",
                'is_active' => true,
                'metadata' => [
                    'floor_id' => $data['floor_id'],
                    'room_type_id' => $roomType->id,
                    'room_type_code' => $roomType->code,
                ],
            ]);

            $data['resource_id'] = $resource->id;
            $data['bed_configuration'] = $data['bed_configuration'] ?? self::bedConfiguration($roomType->code);

            /** @var Room $room */
            $room = $this->rooms->create($data);

            return $room->load(['floor', 'roomType', 'resource']);
        });
    }

    public function update(Room $room, array $data): Room
    {
        return DB::transaction(function () use ($room, $data): Room {
            $roomType = RoomType::findOrFail($data['room_type_id']);
            $roomNumber = $data['room_number'];

            $room->resource->update([
                'code' => "RM-{$roomNumber}",
                'name' => "Room {$roomNumber}",
                'description' => "Hotel room {$roomNumber}",
                'metadata' => [
                    'floor_id' => $data['floor_id'],
                    'room_type_id' => $roomType->id,
                    'room_type_code' => $roomType->code,
                ],
            ]);

            if (blank(Arr::get($data, 'bed_configuration'))) {
                $data['bed_configuration'] = self::bedConfiguration($roomType->code);
            }

            /** @var Room $room */
            $room = $this->rooms->update($room, $data);

            return $room->load(['floor', 'roomType', 'resource']);
        });
    }

    public function delete(Room $room): void
    {
        DB::transaction(function () use ($room): void {
            $resource = $room->resource;
            $this->rooms->delete($room);
            $resource?->delete();
        });
    }

    public static function bedConfiguration(string $roomTypeCode): array
    {
        return match ($roomTypeCode) {
            'TWIN' => ['label' => 'Twin', 'beds' => [['quantity' => 2, 'size_meters' => 1.2]]],
            'DOUBLE' => ['label' => 'Double', 'beds' => [['quantity' => 1, 'size_meters' => 1.8]]],
            'TRIP' => ['label' => 'Trip', 'beds' => [['quantity' => 3, 'size_meters' => 1.2]]],
            'FAMILY' => ['label' => 'Family', 'beds' => [['quantity' => 4, 'size_meters' => 1.2]]],
            'TRIP_FAMILY' => ['label' => 'Trip Family', 'beds' => [['quantity' => 1, 'size_meters' => 1.2], ['quantity' => 1, 'size_meters' => 1.5]]],
            default => ['label' => 'Custom', 'beds' => []],
        };
    }
}
