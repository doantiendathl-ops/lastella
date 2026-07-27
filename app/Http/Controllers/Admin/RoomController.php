<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreRoomRequest;
use App\Http\Requests\Admin\UpdateRoomRequest;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\RoomService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function __construct(private readonly RoomService $rooms)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', Room::class);

        $filters = $request->validated();

        $rooms = Room::query()
            ->with(['roomType', 'floor'])
            ->when($filters['floor_id'] ?? null, fn ($q, $v) => $q->where('floor_id', $v))
            ->when($filters['room_type_id'] ?? null, fn ($q, $v) => $q->where('room_type_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('room_number', 'like', "%{$v}%"))
            ->get();

        $maintenanceStatuses = [RoomStatus::OutOfOrder, RoomStatus::OutOfService];

        $floors = Floor::query()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Floor $floor) use ($rooms, $maintenanceStatuses): array {
                $floorRooms = $rooms->where('floor_id', $floor->id)->sortBy('room_number')->values();

                return [
                    'id' => $floor->id,
                    'code' => $floor->code,
                    'name' => $floor->name,
                    'rooms' => $floorRooms->map(fn (Room $room): array => [
                        'id' => $room->id,
                        'room_number' => $room->room_number,
                        'floor_id' => $room->floor_id,
                        'room_type' => $room->roomType?->code,
                        'status' => $room->status->value,
                        'status_label' => $room->status->label(),
                        'is_maintenance' => in_array($room->status, $maintenanceStatuses, true),
                        'is_eligible_for_bulk' => ! in_array($room->status, $maintenanceStatuses, true),
                    ])->values(),
                ];
            })
            ->filter(fn (array $floor): bool => count($floor['rooms']) > 0)
            ->values();

        return Inertia::render('Admin/Rooms/Index', [
            'floors' => $floors,
            'filters' => $filters,
            'floorOptions' => $this->floorOptions(),
            'roomTypeOptions' => $this->roomTypeOptions(),
            'statusOptions' => RoomStatus::options(),
            'can' => [
                'create' => $request->user()->can('create', Room::class),
                'bulkUpdate' => $request->user()->can('bulkUpdate', Room::class),
                'maintenance' => $request->user()->can('room.maintenance'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Room::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo phòng', '/rooms', 'post'));
    }

    public function store(StoreRoomRequest $request): RedirectResponse
    {
        $this->authorize('create', Room::class);
        $this->rooms->create($request->validated());

        return redirect('/rooms')->with('success', 'Đã tạo phòng.');
    }

    public function edit(Room $room): Response
    {
        $this->authorize('update', $room);

        return Inertia::render('Admin/CrudForm', $this->formProps('Sửa phòng', "/rooms/{$room->id}", 'put', [
            'floor_id' => $room->floor_id,
            'room_type_id' => $room->room_type_id,
            'room_number' => $room->room_number,
            'status' => $room->status?->value,
            'bed_configuration' => $room->bed_configuration,
            'notes' => $room->notes,
        ]));
    }

    public function update(UpdateRoomRequest $request, Room $room): RedirectResponse
    {
        $this->authorize('update', $room);
        $this->rooms->update($room, $request->validated());

        return redirect('/rooms')->with('success', 'Đã cập nhật phòng.');
    }

    public function destroy(Room $room): RedirectResponse
    {
        $this->authorize('delete', $room);
        $this->rooms->delete($room);

        return redirect('/rooms')->with('success', 'Đã xóa phòng.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/rooms',
            'values' => $values,
            'fields' => [
                ['name' => 'room_number', 'label' => 'Số phòng', 'type' => 'text', 'required' => true],
                ['name' => 'floor_id', 'label' => 'Tầng', 'type' => 'select', 'required' => true, 'options' => $this->floorOptions()],
                ['name' => 'room_type_id', 'label' => 'Loại phòng', 'type' => 'select', 'required' => true, 'options' => $this->roomTypeOptions()],
                ['name' => 'status', 'label' => 'Trạng thái', 'type' => 'select', 'required' => true, 'options' => RoomStatus::options()],
                ['name' => 'bed_configuration', 'label' => 'Cấu hình giường', 'type' => 'json'],
                ['name' => 'notes', 'label' => 'Ghi chú', 'type' => 'textarea'],
            ],
        ];
    }

    private function floorOptions(): array
    {
        return Floor::query()->orderBy('sort_order')->get(['id', 'code', 'name'])->map(fn (Floor $floor): array => [
            'value' => $floor->id,
            'label' => "{$floor->code} - {$floor->name}",
        ])->values()->all();
    }

    private function roomTypeOptions(): array
    {
        return RoomType::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (RoomType $type): array => [
            'value' => $type->id,
            'label' => "{$type->code} - {$type->name}",
        ])->values()->all();
    }
}
