<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Services\RoleService;
use App\Support\PermissionLabels;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $items = $this->roles->paginate($request->validated())->through(fn (Role $role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions_count' => $role->permissions->count(),
            'created_at' => $role->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Vai trò',
            'baseUrl' => '/roles',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'name', 'label' => 'Tên', 'sortable' => true],
                ['key' => 'permissions_count', 'label' => 'Quyền'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Tạo vai trò', '/roles', 'post', []));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);
        $this->roles->create($request->validated());

        return redirect('/roles')->with('success', 'Đã tạo vai trò.');
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);
        $role->load('permissions');

        return Inertia::render('Admin/CrudForm', $this->formProps('Sửa vai trò', "/roles/{$role->id}", 'put', [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
        ]));
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);
        $this->roles->update($role, $request->validated());

        return redirect('/roles')->with('success', 'Đã cập nhật vai trò.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);
        $this->roles->delete($role);

        return redirect('/roles')->with('success', 'Đã xóa vai trò.');
    }

    private function formProps(string $title, string $action, string $method, array $values): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/roles',
            'values' => $values,
            'fields' => [
                ['name' => 'name', 'label' => 'Tên', 'type' => 'text', 'required' => true],
                ['name' => 'permissions', 'label' => 'Quyền', 'type' => 'multiselect', 'options' => $this->permissionOptions()],
            ],
        ];
    }

    private function permissionOptions(): array
    {
        // User request (2026-08-19 chat) — Vietnamese label on the
        // checkbox, `value` stays the raw slug so submission/authorization
        // are unaffected. Sort by the LABEL (not the raw slug) so the list
        // groups sensibly for a human picking checkboxes, not alphabetically
        // by English dot-notation.
        return Permission::query()->pluck('name')
            ->map(fn (string $name): array => [
                'value' => $name,
                'label' => PermissionLabels::forSlug($name),
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }
}
