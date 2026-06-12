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

        $items = $this->rooms->paginate($request->validated())->through(fn (Room $room): array => [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'floor' => $room->floor?->code,
            'room_type' => $room->roomType?->code,
            'status' => $room->status?->value,
            'resource' => $room->resource?->code,
            'created_at' => $room->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Rooms',
            'baseUrl' => '/rooms',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'room_number', 'label' => 'Room', 'sortable' => true],
                ['key' => 'floor', 'label' => 'Floor'],
                ['key' => 'room_type', 'label' => 'Type'],
                ['key' => 'status', 'label' => 'Status', 'sortable' => true],
                ['key' => 'resource', 'label' => 'Resource'],
            ],
            'filterFields' => [
                ['name' => 'floor_id', 'label' => 'Floor', 'type' => 'select', 'options' => $this->floorOptions()],
                ['name' => 'room_type_id', 'label' => 'Room Type', 'type' => 'select', 'options' => $this->roomTypeOptions()],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => RoomStatus::options()],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Room::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create Room', '/rooms', 'post'));
    }

    public function store(StoreRoomRequest $request): RedirectResponse
    {
        $this->authorize('create', Room::class);
        $this->rooms->create($request->validated());

        return redirect('/rooms')->with('success', 'Room created.');
    }

    public function edit(Room $room): Response
    {
        $this->authorize('update', $room);

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit Room', "/rooms/{$room->id}", 'put', [
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

        return redirect('/rooms')->with('success', 'Room updated.');
    }

    public function destroy(Room $room): RedirectResponse
    {
        $this->authorize('delete', $room);
        $this->rooms->delete($room);

        return redirect('/rooms')->with('success', 'Room deleted.');
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
                ['name' => 'room_number', 'label' => 'Room Number', 'type' => 'text', 'required' => true],
                ['name' => 'floor_id', 'label' => 'Floor', 'type' => 'select', 'required' => true, 'options' => $this->floorOptions()],
                ['name' => 'room_type_id', 'label' => 'Room Type', 'type' => 'select', 'required' => true, 'options' => $this->roomTypeOptions()],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => RoomStatus::options()],
                ['name' => 'bed_configuration', 'label' => 'Bed Configuration', 'type' => 'json'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
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
