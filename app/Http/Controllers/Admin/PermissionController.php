<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StorePermissionRequest;
use App\Http\Requests\Admin\UpdatePermissionRequest;
use App\Services\PermissionService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', Permission::class);

        return Inertia::render('Admin/CrudIndex', [
            'title' => 'Permissions',
            'baseUrl' => '/permissions',
            'items' => $this->permissions->paginate($request->validated()),
            'filters' => $request->validated(),
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'sortable' => true],
                ['key' => 'guard_name', 'label' => 'Guard'],
            ],
            'canCreate' => true,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Permission::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create Permission', '/permissions', 'post'));
    }

    public function store(StorePermissionRequest $request): RedirectResponse
    {
        $this->authorize('create', Permission::class);
        $this->permissions->create($request->validated());

        return redirect('/permissions')->with('success', 'Permission created.');
    }

    public function edit(Permission $permission): Response
    {
        $this->authorize('update', $permission);

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit Permission', "/permissions/{$permission->id}", 'put', [
            'name' => $permission->name,
        ]));
    }

    public function update(UpdatePermissionRequest $request, Permission $permission): RedirectResponse
    {
        $this->authorize('update', $permission);
        $this->permissions->update($permission, $request->validated());

        return redirect('/permissions')->with('success', 'Permission updated.');
    }

    public function destroy(Permission $permission): RedirectResponse
    {
        $this->authorize('delete', $permission);
        $this->permissions->delete($permission);

        return redirect('/permissions')->with('success', 'Permission deleted.');
    }

    private function formProps(string $title, string $action, string $method, array $values = []): array
    {
        return [
            'title' => $title,
            'action' => $action,
            'method' => $method,
            'cancelUrl' => '/permissions',
            'values' => $values,
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ],
        ];
    }
}
