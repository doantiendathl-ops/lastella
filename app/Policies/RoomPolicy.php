<?php

namespace App\Policies;

use App\Models\Room;
use App\Models\User;

class RoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rooms.manage') || $user->can('housekeeping.view');
    }

    public function view(User $user, Room $room): bool
    {
        return $user->can('rooms.manage') || $user->can('housekeeping.view');
    }

    public function create(User $user): bool
    {
        return $user->can('rooms.manage');
    }

    public function update(User $user, Room $room): bool
    {
        return $user->can('rooms.manage');
    }

    public function delete(User $user, Room $room): bool
    {
        return $user->can('rooms.manage');
    }

    public function restore(User $user, Room $room): bool
    {
        return $user->can('rooms.manage');
    }
}
