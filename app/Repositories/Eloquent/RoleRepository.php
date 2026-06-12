<?php

namespace App\Repositories\Eloquent;

use Spatie\Permission\Models\Role;

class RoleRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Role::class;
    }

    protected function with(): array
    {
        return ['permissions'];
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
