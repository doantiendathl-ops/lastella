<?php

namespace App\Policies;

use App\Models\Floor;
use App\Models\User;

class FloorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rooms.manage');
    }

    public function view(User $user, Floor $floor): bool
    {
        return $user->can('rooms.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('rooms.manage');
    }

    public function update(User $user, Floor $floor): bool
    {
        return $user->can('rooms.manage');
    }

    public function delete(User $user, Floor $floor): bool
    {
        return $user->can('rooms.manage') && ! $floor->rooms()->exists();
    }

    public function restore(User $user, Floor $floor): bool
    {
        return $user->can('rooms.manage');
    }
}
