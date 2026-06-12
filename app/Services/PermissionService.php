<?php

namespace App\Services;

use App\Repositories\Eloquent\PermissionRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Permission\Models\Permission;

class PermissionService
{
    public function __construct(private readonly PermissionRepository $permissions)
    {
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->permissions->paginate($filters);
    }

    public function create(array $data): Permission
    {
        $data['guard_name'] = 'web';

        /** @var Permission $permission */
        $permission = $this->permissions->create($data);

        return $permission;
    }

    public function update(Permission $permission, array $data): Permission
    {
        $data['guard_name'] = 'web';

        /** @var Permission $permission */
        $permission = $this->permissions->update($permission, $data);

        return $permission;
    }

    public function delete(Permission $permission): void
    {
        $this->permissions->delete($permission);
    }
}
