<?php

namespace App\Policies;

use App\Models\RoomAssignment;
use App\Models\User;

class RoomAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rooms.manage') || $user->can('room.assign') || $user->can('room.unassign');
    }

    public function view(User $user, RoomAssignment $roomAssignment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('room.assign');
    }

    public function update(User $user, RoomAssignment $roomAssignment): bool
    {
        return $user->can('room.assign');
    }

    public function release(User $user, RoomAssignment $roomAssignment): bool
    {
        return $user->can('room.unassign');
    }
}
