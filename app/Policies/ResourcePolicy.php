<?php

namespace App\Policies;

use App\Models\Resource;
use App\Models\User;

class ResourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rooms.manage');
    }

    public function view(User $user, Resource $resource): bool
    {
        return $user->can('rooms.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('rooms.manage');
    }

    public function update(User $user, Resource $resource): bool
    {
        return $user->can('rooms.manage');
    }

    public function delete(User $user, Resource $resource): bool
    {
        return $user->can('rooms.manage');
    }

    public function restore(User $user, Resource $resource): bool
    {
        return $user->can('rooms.manage');
    }
}
