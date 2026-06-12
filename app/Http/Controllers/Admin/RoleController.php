<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Services\RoleService;
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
            'title' => 'Roles',
            'baseUrl' => '/roles',
            'items' => $items,
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'sortable' => true],
                ['key' => 'permissions_count', 'label' => 'Permissions'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Role::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create Role', '/roles', 'post', []));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $this->authorize('create', Role::class);
        $this->roles->create($request->validated());

        return redirect('/roles')->with('success', 'Role created.');
    }

    public function edit(Role $role): Response
    {
        $this->authorize('update', $role);
        $role->load('permissions');

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit Role', "/roles/{$role->id}", 'put', [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->values(),
        ]));
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorize('update', $role);
        $this->roles->update($role, $request->validated());

        return redirect('/roles')->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->authorize('delete', $role);
        $this->roles->delete($role);

        return redirect('/roles')->with('success', 'Role deleted.');
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
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'permissions', 'label' => 'Permissions', 'type' => 'multiselect', 'options' => $this->permissionOptions()],
            ],
        ];
    }

    private function permissionOptions(): array
    {
        return Permission::query()->orderBy('name')->pluck('name')->map(fn (string $name): array => [
            'value' => $name,
            'label' => $name,
        ])->values()->all();
    }
}
