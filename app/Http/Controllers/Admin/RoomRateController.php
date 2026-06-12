<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreRoomRateRequest;
use App\Http\Requests\Admin\UpdateRoomRateRequest;
use App\Models\RoomRate;
use App\Models\RoomType;
use App\Services\RoomRateService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomRateController extends Controller
{
    public function __construct(private readonly RoomRateService $roomRates)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', RoomRate::class);

        $items = $this->roomRates->paginate($request->validated())->through(fn (RoomRate $rate): array => [
            'id' => $rate->id,
            'room_type' => $rate->roomType?->code,
            'valid_from' => $rate->valid_from?->toDateString(),
            'valid_to' => $rate->valid_to?->toDateString(),
            'overnight_price' => $rate->overnight_price,
            'hourly_price' => $rate->hourly_price,
            'status' => $rate->status?->value,
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Room Rates',
            'baseUrl' => '/room-rates',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'room_type', 'label' => 'Room Type'],
                ['key' => 'valid_from', 'label' => 'Valid From', 'sortable' => true],
                ['key' => 'valid_to', 'label' => 'Valid To', 'sortable' => true],
                ['key' => 'overnight_price', 'label' => 'Overnight', 'sortable' => true],
                ['key' => 'hourly_price', 'label' => 'Hourly'],
                ['key' => 'status', 'label' => 'Status', 'sortable' => true],
            ],
            'filterFields' => [
                ['name' => 'room_type_id', 'label' => 'Room Type', 'type' => 'select', 'options' => $this->roomTypeOptions()],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => RateStatus::options()],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RoomRate::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create Room Rate', '/room-rates', 'post'));
    }

    public function store(StoreRoomRateRequest $request): RedirectResponse
    {
        $this->authorize('create', RoomRate::class);
        $this->roomRates->create($request->validated());

        return redirect('/room-rates')->with('success', 'Room rate created.');
    }

    public function edit(RoomRate $roomRate): Response
    {
        $this->authorize('update', $roomRate);

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit Room Rate', "/room-rates/{$roomRate->id}", 'put', [
            'room_type_id' => $roomRate->room_type_id,
            'valid_from' => $roomRate->valid_from?->toDateString(),
            'valid_to' => $roomRate->valid_to?->toDateString(),
            'overnight_price' => $roomRate->overnight_price,
            'hourly_price' => $roomRate->hourly_price,
            'extra_adult_price' => $roomRate->extra_adult_price,
            'extra_child_price' => $roomRate->extra_child_price,
            'early_checkin_price' => $roomRate->early_checkin_price,
            'late_checkout_price' => $roomRate->late_checkout_price,
            'status' => $roomRate->status?->value,
        ]));
    }

    public function update(UpdateRoomRateRequest $request, RoomRate $roomRate): RedirectResponse
    {
        $this->authorize('update', $roomRate);
        $this->roomRates->update($roomRate, $request->validated());

        return redirect('/room-rates')->with('success', 'Room rate updated.');
    }

    public function destroy(RoomRate $roomRate): RedirectResponse
    {
        $this->authorize('delete', $roomRate);
        $this->roomRates->delete($roomRate);

        return redirect('/room-rates')->with('success', 'Room rate deleted.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/room-rates',
            'values' => $values,
            'fields' => [
                ['name' => 'room_type_id', 'label' => 'Room Type', 'type' => 'select', 'required' => true, 'options' => $this->roomTypeOptions()],
                ['name' => 'valid_from', 'label' => 'Valid From', 'type' => 'date', 'required' => true],
                ['name' => 'valid_to', 'label' => 'Valid To', 'type' => 'date'],
                ['name' => 'overnight_price', 'label' => 'Overnight Price', 'type' => 'number', 'required' => true],
                ['name' => 'hourly_price', 'label' => 'Hourly Price', 'type' => 'number', 'required' => true],
                ['name' => 'extra_adult_price', 'label' => 'Extra Adult', 'type' => 'number', 'required' => true],
                ['name' => 'extra_child_price', 'label' => 'Extra Child', 'type' => 'number', 'required' => true],
                ['name' => 'early_checkin_price', 'label' => 'Early Check-in', 'type' => 'number', 'required' => true],
                ['name' => 'late_checkout_price', 'label' => 'Late Checkout', 'type' => 'number', 'required' => true],
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => RateStatus::options()],
            ],
        ];
    }

    private function roomTypeOptions(): array
    {
        return RoomType::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (RoomType $type): array => [
            'value' => $type->id,
            'label' => "{$type->code} - {$type->name}",
        ])->values()->all();
    }
}
