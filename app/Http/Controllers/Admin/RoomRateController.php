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
            'status' => $rate->status?->label(),
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Giá phòng',
            'baseUrl' => '/room-rates',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'room_type', 'label' => 'Loại phòng'],
                ['key' => 'valid_from', 'label' => 'Hiệu lực từ', 'sortable' => true],
                ['key' => 'valid_to', 'label' => 'Hiệu lực đến', 'sortable' => true],
                ['key' => 'overnight_price', 'label' => 'Qua đêm', 'sortable' => true],
                ['key' => 'hourly_price', 'label' => 'Theo giờ'],
                ['key' => 'status', 'label' => 'Trạng thái', 'sortable' => true],
            ],
            'filterFields' => [
                ['name' => 'room_type_id', 'label' => 'Loại phòng', 'type' => 'select', 'options' => $this->roomTypeOptions()],
                ['name' => 'status', 'label' => 'Trạng thái', 'type' => 'select', 'options' => RateStatus::options()],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RoomRate::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo giá phòng', '/room-rates', 'post'));
    }

    public function store(StoreRoomRateRequest $request): RedirectResponse
    {
        $this->authorize('create', RoomRate::class);
        $this->roomRates->create($request->validated());

        return redirect('/room-rates')->with('success', 'Đã tạo giá phòng.');
    }

    public function edit(RoomRate $roomRate): Response
    {
        $this->authorize('update', $roomRate);

        return Inertia::render('Admin/CrudForm', $this->formProps('Sửa giá phòng', "/room-rates/{$roomRate->id}", 'put', [
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

        return redirect('/room-rates')->with('success', 'Đã cập nhật giá phòng.');
    }

    public function destroy(RoomRate $roomRate): RedirectResponse
    {
        $this->authorize('delete', $roomRate);
        $this->roomRates->delete($roomRate);

        return redirect('/room-rates')->with('success', 'Đã xóa giá phòng.');
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
                ['name' => 'room_type_id', 'label' => 'Loại phòng', 'type' => 'select', 'required' => true, 'options' => $this->roomTypeOptions()],
                ['name' => 'valid_from', 'label' => 'Hiệu lực từ', 'type' => 'date', 'required' => true],
                ['name' => 'valid_to', 'label' => 'Hiệu lực đến', 'type' => 'date'],
                ['name' => 'overnight_price', 'label' => 'Giá qua đêm', 'type' => 'number', 'required' => true],
                ['name' => 'hourly_price', 'label' => 'Giá theo giờ', 'type' => 'number', 'required' => true],
                ['name' => 'extra_adult_price', 'label' => 'Người lớn thêm', 'type' => 'number', 'required' => true],
                ['name' => 'extra_child_price', 'label' => 'Trẻ em thêm', 'type' => 'number', 'required' => true],
                ['name' => 'early_checkin_price', 'label' => 'Nhận phòng sớm', 'type' => 'number', 'required' => true],
                ['name' => 'late_checkout_price', 'label' => 'Trả phòng muộn', 'type' => 'number', 'required' => true],
                ['name' => 'status', 'label' => 'Trạng thái', 'type' => 'select', 'required' => true, 'options' => RateStatus::options()],
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
