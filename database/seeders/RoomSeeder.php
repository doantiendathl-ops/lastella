<?php

namespace Database\Seeders;

use App\Enums\ResourceType;
use App\Enums\RoomStatus;
use App\Models\Floor;
use App\Models\Resource;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Authoritative room map sourced from "So do phong.xlsx".
     * Each entry: room_number => [floor_code, room_type_code, status]
     * Room 101 is under maintenance (OutOfOrder).
     */
    private const ROOM_MAP = [
        // Basement 1 — 7 rooms
        '101' => ['floor' => 'B1', 'type' => 'TWIN',       'status' => RoomStatus::OutOfOrder],
        '102' => ['floor' => 'B1', 'type' => 'TRIP',       'status' => RoomStatus::VacantClean],
        '103' => ['floor' => 'B1', 'type' => 'TWIN',       'status' => RoomStatus::VacantClean],
        '104' => ['floor' => 'B1', 'type' => 'TWIN',       'status' => RoomStatus::VacantClean],
        '105' => ['floor' => 'B1', 'type' => 'TWIN',       'status' => RoomStatus::VacantClean],
        '106' => ['floor' => 'B1', 'type' => 'FAMILY',     'status' => RoomStatus::VacantClean],
        '107' => ['floor' => 'B1', 'type' => 'FAMILY',     'status' => RoomStatus::VacantClean],

        // Floor 2 — 5 rooms (all Twin)
        '201' => ['floor' => '2', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '202' => ['floor' => '2', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '203' => ['floor' => '2', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '204' => ['floor' => '2', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '205' => ['floor' => '2', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],

        // Floor 3 — 9 rooms (Twin/Double/Twin/Twin/Twin/TripFamily/Twin/Twin/Twin)
        '301' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '302' => ['floor' => '3', 'type' => 'DOUBLE',      'status' => RoomStatus::VacantClean],
        '303' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '304' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '305' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '306' => ['floor' => '3', 'type' => 'TRIP_FAMILY', 'status' => RoomStatus::VacantClean],
        '307' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '308' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '309' => ['floor' => '3', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],

        // Floor 4 — 9 rooms (Twin/Double/Twin/Twin/Twin/TripFamily/Twin/Twin/Twin)
        '401' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '402' => ['floor' => '4', 'type' => 'DOUBLE',      'status' => RoomStatus::VacantClean],
        '403' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '404' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '405' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '406' => ['floor' => '4', 'type' => 'TRIP_FAMILY', 'status' => RoomStatus::VacantClean],
        '407' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '408' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '409' => ['floor' => '4', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],

        // Floor 5 — 9 rooms (Twin/Double/Twin/Twin/Twin/TripFamily/Twin/Twin/Twin)
        '501' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '502' => ['floor' => '5', 'type' => 'DOUBLE',      'status' => RoomStatus::VacantClean],
        '503' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '504' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '505' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '506' => ['floor' => '5', 'type' => 'TRIP_FAMILY', 'status' => RoomStatus::VacantClean],
        '507' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '508' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '509' => ['floor' => '5', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],

        // Floor 6 — 9 rooms (Twin/Double/Twin/Twin/Twin/TripFamily/Twin/Twin/Twin)
        '601' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '602' => ['floor' => '6', 'type' => 'DOUBLE',      'status' => RoomStatus::VacantClean],
        '603' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '604' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '605' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '606' => ['floor' => '6', 'type' => 'TRIP_FAMILY', 'status' => RoomStatus::VacantClean],
        '607' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '608' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '609' => ['floor' => '6', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],

        // Floor 7 — 9 rooms (Twin/Double/Twin/Twin/Twin/TripFamily/Twin/Twin/Twin)
        '701' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '702' => ['floor' => '7', 'type' => 'DOUBLE',      'status' => RoomStatus::VacantClean],
        '703' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '704' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '705' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '706' => ['floor' => '7', 'type' => 'TRIP_FAMILY', 'status' => RoomStatus::VacantClean],
        '707' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '708' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],
        '709' => ['floor' => '7', 'type' => 'TWIN',        'status' => RoomStatus::VacantClean],

        // Floor 8 — 2 rooms (both Trip)
        '801' => ['floor' => '8', 'type' => 'TRIP',        'status' => RoomStatus::VacantClean],
        '802' => ['floor' => '8', 'type' => 'TRIP',        'status' => RoomStatus::VacantClean],
    ];

    public function run(): void
    {
        $roomTypes = RoomType::query()->get()->keyBy('code');
        $floors    = Floor::query()->get()->keyBy('code');

        foreach (self::ROOM_MAP as $roomNumber => $config) {
            $floorCode    = $config['floor'];
            $roomTypeCode = $config['type'];
            $status       = $config['status'];

            $floor    = $floors->get($floorCode);
            $roomType = $roomTypes->get($roomTypeCode);

            $resource = Resource::updateOrCreate(
                ['code' => "RM-{$roomNumber}"],
                [
                    'type'        => ResourceType::Room,
                    'name'        => "Room {$roomNumber}",
                    'description' => "Hotel room {$roomNumber}",
                    'is_active'   => true,
                    'metadata'    => [
                        'floor_code'     => $floorCode,
                        'room_type_code' => $roomTypeCode,
                    ],
                ],
            );

            Room::updateOrCreate(
                ['room_number' => $roomNumber],
                [
                    'resource_id'       => $resource->id,
                    'floor_id'          => $floor->id,
                    'room_type_id'      => $roomType->id,
                    'status'            => $status,
                    'bed_configuration' => self::bedConfiguration($roomTypeCode),
                    'notes'             => (string) $roomNumber === '101' ? 'Under maintenance' : null,
                ],
            );
        }
    }

    public static function bedConfiguration(string $roomTypeCode): array
    {
        return match ($roomTypeCode) {
            'TWIN' => [
                'label' => 'Twin',
                'beds'  => [
                    ['quantity' => 2, 'size_meters' => 1.2],
                ],
            ],
            'DOUBLE' => [
                'label' => 'Double',
                'beds'  => [
                    ['quantity' => 1, 'size_meters' => 1.8],
                ],
            ],
            'TRIP' => [
                'label' => 'Trip',
                'beds'  => [
                    ['quantity' => 3, 'size_meters' => 1.2],
                ],
            ],
            'FAMILY' => [
                'label' => 'Family',
                'beds'  => [
                    ['quantity' => 4, 'size_meters' => 1.2],
                ],
            ],
            'TRIP_FAMILY' => [
                'label' => 'Trip Family',
                'beds'  => [
                    ['quantity' => 1, 'size_meters' => 1.5],
                    ['quantity' => 1, 'size_meters' => 1.2],
                ],
            ],
            default => [
                'label' => 'Custom',
                'beds'  => [],
            ],
        };
    }
}
