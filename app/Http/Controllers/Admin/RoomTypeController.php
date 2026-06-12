<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreRoomTypeRequest;
use App\Http\Requests\Admin\UpdateRoomTypeRequest;
use App\Models\RoomType;
use App\Services\RoomTypeService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoomTypeController extends Controller
{
    public function __construct(private readonly RoomTypeService $roomTypes)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', RoomType::class);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Loại phòng',
            'baseUrl' => '/room-types',
            'items' => $this->roomTypes->paginate($request->validated()),
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'code', 'label' => 'Mã', 'sortable' => true],
                ['key' => 'name', 'label' => 'Tên', 'sortable' => true],
                ['key' => 'standard_adults', 'label' => 'Người lớn chuẩn', 'sortable' => true],
                ['key' => 'max_adults', 'label' => 'Người lớn tối đa', 'sortable' => true],
                ['key' => 'free_children', 'label' => 'Trẻ em miễn phí'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RoomType::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo loại phòng', '/room-types', 'post'));
    }

    public function store(StoreRoomTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', RoomType::class);
        $this->roomTypes->create($request->validated());

        return redirect('/room-types')->with('success', 'Đã tạo loại phòng.');
    }

    public function edit(RoomType $roomType): Response
    {
        $this->authorize('update', $roomType);

        return Inertia::render('Admin/CrudForm', $this->formProps('Sửa loại phòng', "/room-types/{$roomType->id}", 'put', $roomType->only([
            'code',
            'name',
            'description',
            'standard_adults',
            'max_adults',
            'free_children',
        ])));
    }

    public function update(UpdateRoomTypeRequest $request, RoomType $roomType): RedirectResponse
    {
        $this->authorize('update', $roomType);
        $this->roomTypes->update($roomType, $request->validated());

        return redirect('/room-types')->with('success', 'Đã cập nhật loại phòng.');
    }

    public function destroy(RoomType $roomType): RedirectResponse
    {
        $this->authorize('delete', $roomType);
        $this->roomTypes->delete($roomType);

        return redirect('/room-types')->with('success', 'Đã xóa loại phòng.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/room-types',
            'values' => $values,
            'fields' => [
                ['name' => 'code', 'label' => 'Mã', 'type' => 'text', 'required' => true],
                ['name' => 'name', 'label' => 'Tên', 'type' => 'text', 'required' => true],
                ['name' => 'description', 'label' => 'Mô tả', 'type' => 'textarea'],
                ['name' => 'standard_adults', 'label' => 'Người lớn tiêu chuẩn', 'type' => 'number', 'required' => true],
                ['name' => 'max_adults', 'label' => 'Người lớn tối đa', 'type' => 'number', 'required' => true],
                ['name' => 'free_children', 'label' => 'Trẻ em miễn phí', 'type' => 'number', 'required' => true],
            ],
        ];
    }
}
