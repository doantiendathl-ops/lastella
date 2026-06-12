<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreSettingRequest;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', Setting::class);

        $items = $this->settings->paginate($request->validated())->through(fn (Setting $setting): array => [
            'id' => $setting->id,
            'key' => $setting->key,
            'value' => is_array($setting->typed_value) ? json_encode($setting->typed_value) : $setting->typed_value,
            'type' => $this->typeLabel($setting->type),
            'group' => $setting->group,
            'is_public' => $setting->is_public ? 'Có' : 'Không',
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Cài đặt',
            'baseUrl' => '/settings',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'key', 'label' => 'Khóa', 'sortable' => true],
                ['key' => 'value', 'label' => 'Giá trị'],
                ['key' => 'type', 'label' => 'Kiểu'],
                ['key' => 'group', 'label' => 'Nhóm', 'sortable' => true],
                ['key' => 'is_public', 'label' => 'Công khai'],
            ],
            'filterFields' => [
                ['name' => 'group', 'label' => 'Nhóm', 'type' => 'text'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Setting::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo cài đặt', '/settings', 'post'));
    }

    public function store(StoreSettingRequest $request): RedirectResponse
    {
        $this->authorize('create', Setting::class);
        $this->settings->create($request->validated());

        return redirect('/settings')->with('success', 'Đã tạo cài đặt.');
    }

    public function edit(Setting $setting): Response
    {
        $this->authorize('update', $setting);

        return Inertia::render('Admin/CrudForm', $this->formProps('Sửa cài đặt', "/settings/{$setting->id}", 'put', [
            'key' => $setting->key,
            'value' => is_array($setting->typed_value) ? json_encode($setting->typed_value, JSON_PRETTY_PRINT) : $setting->typed_value,
            'type' => $setting->type,
            'group' => $setting->group,
            'is_public' => $setting->is_public,
        ]));
    }

    public function update(UpdateSettingRequest $request, Setting $setting): RedirectResponse
    {
        $this->authorize('update', $setting);
        $this->settings->update($setting, $request->validated());

        return redirect('/settings')->with('success', 'Đã cập nhật cài đặt.');
    }

    public function destroy(Setting $setting): RedirectResponse
    {
        $this->authorize('delete', $setting);
        $this->settings->delete($setting);

        return redirect('/settings')->with('success', 'Đã xóa cài đặt.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/settings',
            'values' => $values,
            'fields' => [
                ['name' => 'key', 'label' => 'Khóa', 'type' => 'text', 'required' => true],
                ['name' => 'value', 'label' => 'Giá trị', 'type' => 'textarea'],
                ['name' => 'type', 'label' => 'Kiểu', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'string', 'label' => 'Chuỗi'],
                    ['value' => 'integer', 'label' => 'Số nguyên'],
                    ['value' => 'boolean', 'label' => 'Đúng/Sai'],
                    ['value' => 'time', 'label' => 'Thời gian'],
                    ['value' => 'json', 'label' => 'JSON'],
                ]],
                ['name' => 'group', 'label' => 'Nhóm', 'type' => 'text', 'required' => true],
                ['name' => 'is_public', 'label' => 'Công khai', 'type' => 'checkbox'],
            ],
        ];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'string' => 'Chuỗi',
            'integer' => 'Số nguyên',
            'boolean' => 'Đúng/Sai',
            'time' => 'Thời gian',
            'json' => 'JSON',
            default => $type,
        };
    }
}
