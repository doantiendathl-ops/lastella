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
            'type' => $setting->type,
            'group' => $setting->group,
            'is_public' => $setting->is_public ? 'Yes' : 'No',
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Settings',
            'baseUrl' => '/settings',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'key', 'label' => 'Key', 'sortable' => true],
                ['key' => 'value', 'label' => 'Value'],
                ['key' => 'type', 'label' => 'Type'],
                ['key' => 'group', 'label' => 'Group', 'sortable' => true],
                ['key' => 'is_public', 'label' => 'Public'],
            ],
            'filterFields' => [
                ['name' => 'group', 'label' => 'Group', 'type' => 'text'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Setting::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create Setting', '/settings', 'post'));
    }

    public function store(StoreSettingRequest $request): RedirectResponse
    {
        $this->authorize('create', Setting::class);
        $this->settings->create($request->validated());

        return redirect('/settings')->with('success', 'Setting created.');
    }

    public function edit(Setting $setting): Response
    {
        $this->authorize('update', $setting);

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit Setting', "/settings/{$setting->id}", 'put', [
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

        return redirect('/settings')->with('success', 'Setting updated.');
    }

    public function destroy(Setting $setting): RedirectResponse
    {
        $this->authorize('delete', $setting);
        $this->settings->delete($setting);

        return redirect('/settings')->with('success', 'Setting deleted.');
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
                ['name' => 'key', 'label' => 'Key', 'type' => 'text', 'required' => true],
                ['name' => 'value', 'label' => 'Value', 'type' => 'textarea'],
                ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'string', 'label' => 'String'],
                    ['value' => 'integer', 'label' => 'Integer'],
                    ['value' => 'boolean', 'label' => 'Boolean'],
                    ['value' => 'time', 'label' => 'Time'],
                    ['value' => 'json', 'label' => 'JSON'],
                ]],
                ['name' => 'group', 'label' => 'Group', 'type' => 'text', 'required' => true],
                ['name' => 'is_public', 'label' => 'Public', 'type' => 'checkbox'],
            ],
        ];
    }
}
