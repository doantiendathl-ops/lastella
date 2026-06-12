<?php

namespace App\Policies;

use App\Models\RoomType;
use App\Models\User;

class RoomTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('room_types.manage');
    }

    public function view(User $user, RoomType $roomType): bool
    {
        return $user->can('room_types.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('room_types.manage');
    }

    public function update(User $user, RoomType $roomType): bool
    {
        return $user->can('room_types.manage');
    }

    public function delete(User $user, RoomType $roomType): bool
    {
        return $user->can('room_types.manage') && ! $roomType->rooms()->exists();
    }

    public function restore(User $user, RoomType $roomType): bool
    {
        return $user->can('room_types.manage');
    }
}
