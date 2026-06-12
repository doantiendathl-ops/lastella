<?php

namespace App\Repositories\Eloquent;

use Spatie\Permission\Models\Permission;

class PermissionRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Permission::class;
    }

    protected function searchable(): array
    {
        return ['name'];
    }

    protected function sortable(): array
    {
        return ['name', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }
}
