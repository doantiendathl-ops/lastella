<?php

namespace App\Services;

use App\Repositories\Eloquent\RoleRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Role;

class RoleService
{
    public function __construct(private readonly RoleRepository $roles)
    {
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->roles->paginate($filters);
    }

    public function create(array $data): Role
    {
        $permissions = Arr::pull($data, 'permissions', []);
        $data['guard_name'] = 'web';

        /** @var Role $role */
        $role = $this->roles->create($data);
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }

    public function update(Role $role, array $data): Role
    {
        $permissions = Arr::pull($data, 'permissions', []);
        $data['guard_name'] = 'web';

        /** @var Role $role */
        $role = $this->roles->update($role, $data);
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }

    public function delete(Role $role): void
    {
        $this->roles->delete($role);
    }
}
