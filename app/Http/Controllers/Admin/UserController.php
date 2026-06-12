<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function index(IndexRequest $request): Response
    {
        $this->authorize('viewAny', User::class);

        $items = $this->users->paginate($request->validated())->through(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
            'roles_list' => $user->roles->pluck('name')->join(', '),
            'created_at' => $user->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('Admin/CrudIndex', $this->indexProps('Users', '/users', $items, $request->validated(), [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'email', 'label' => 'Email', 'sortable' => true],
            ['key' => 'roles_list', 'label' => 'Roles'],
        ]));
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Admin/CrudForm', $this->formProps('Create User', '/users', 'post', $this->fields()));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);
        $this->users->create($request->validated());

        return redirect('/users')->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);
        $user->load('roles');

        return Inertia::render('Admin/CrudForm', $this->formProps('Edit User', "/users/{$user->id}", 'put', $this->fields(false), [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
        ]));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);
        $this->users->update($user, $request->validated());

        return redirect('/users')->with('success', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);
        $this->users->delete($user);

        return redirect('/users')->with('success', 'User deleted.');
    }

    private function fields(bool $requiresPassword = true): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => $requiresPassword],
            ['name' => 'password_confirmation', 'label' => 'Confirm Password', 'type' => 'password', 'required' => $requiresPassword],
            ['name' => 'roles', 'label' => 'Roles', 'type' => 'multiselect', 'options' => $this->roleOptions()],
        ];
    }

    private function roleOptions(): array
    {
        return Role::query()->orderBy('name')->pluck('name')->map(fn (string $name): array => [
            'value' => $name,
            'label' => $name,
        ])->values()->all();
    }

    private function indexProps(string $title, string $baseUrl, mixed $items, array $filters, array $columns): array
    {
        return compact('title', 'baseUrl', 'items', 'filters', 'columns') + ['canCreate' => true];
    }

    private function formProps(string $title, string $action, string $method, array $fields, array $values = []): array
    {
        return compact('title', 'action', 'method', 'fields', 'values') + ['cancelUrl' => '/users'];
    }
}
