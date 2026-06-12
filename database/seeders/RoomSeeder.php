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
    private const INVENTORY = [
        'B1' => ['101', '102', '103', '104', '105', '106', '107'],
        '2' => ['201', '202', '203', '204', '205'],
        '3' => ['301', '302', '303', '304', '305', '306', '307', '308', '309'],
        '4' => ['401', '402', '403', '404', '405', '406', '407', '408', '409'],
        '5' => ['501', '502', '503', '504', '505', '506', '507', '508', '509'],
        '6' => ['601', '602', '603', '604', '605', '606', '607', '608', '609'],
        '7' => ['701', '702', '703', '704', '705', '706', '707', '708', '709'],
        '8' => ['801', '802'],
    ];

    private const ROOM_TYPE_SEQUENCE = [
        'TWIN',
        'DOUBLE',
        'TRIP',
        'FAMILY',
        'TRIP_FAMILY',
    ];

    public function run(): void
    {
        $roomTypes = RoomType::query()->whereIn('code', self::ROOM_TYPE_SEQUENCE)->get()->keyBy('code');

        $index = 0;

        foreach (self::INVENTORY as $floorCode => $roomNumbers) {
            $floor = Floor::where('code', $floorCode)->firstOrFail();

            foreach ($roomNumbers as $roomNumber) {
                $roomTypeCode = self::ROOM_TYPE_SEQUENCE[$index % count(self::ROOM_TYPE_SEQUENCE)];
                $roomType = $roomTypes->get($roomTypeCode);

                $resource = Resource::updateOrCreate(
                    ['code' => "RM-{$roomNumber}"],
                    [
                        'type' => ResourceType::Room,
                        'name' => "Room {$roomNumber}",
                        'description' => "Hotel room {$roomNumber}",
                        'is_active' => true,
                        'metadata' => [
                            'floor_code' => $floorCode,
                            'room_type_code' => $roomTypeCode,
                        ],
                    ],
                );

                Room::updateOrCreate(
                    ['room_number' => $roomNumber],
                    [
                        'resource_id' => $resource->id,
                        'floor_id' => $floor->id,
                        'room_type_id' => $roomType->id,
                        'status' => RoomStatus::VacantClean,
                        'bed_configuration' => self::bedConfiguration($roomTypeCode),
                        'notes' => null,
                    ],
                );

                $index++;
            }
        }
    }

    public static function bedConfiguration(string $roomTypeCode): array
    {
        return match ($roomTypeCode) {
            'TWIN' => [
                'label' => 'Twin',
                'beds' => [
                    ['quantity' => 2, 'size_meters' => 1.2],
                ],
            ],
            'DOUBLE' => [
                'label' => 'Double',
                'beds' => [
                    ['quantity' => 1, 'size_meters' => 1.8],
                ],
            ],
            'TRIP' => [
                'label' => 'Trip',
                'beds' => [
                    ['quantity' => 3, 'size_meters' => 1.2],
                ],
            ],
            'FAMILY' => [
                'label' => 'Family',
                'beds' => [
                    ['quantity' => 4, 'size_meters' => 1.2],
                ],
            ],
            'TRIP_FAMILY' => [
                'label' => 'Trip Family',
                'beds' => [
                    ['quantity' => 1, 'size_meters' => 1.2],
                    ['quantity' => 1, 'size_meters' => 1.5],
                ],
            ],
            default => [
                'label' => 'Custom',
                'beds' => [],
            ],
        };
    }
}
