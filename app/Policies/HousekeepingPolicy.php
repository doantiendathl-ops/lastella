<?php

namespace App\Policies;

use App\Models\User;

/**
 * Class-level authorization only. Every method takes just a User — none of these
 * abilities are checked against a specific HousekeepingAssignment/Room instance.
 * Registered against HousekeepingAssignment::class in AppServiceProvider so
 * `$user->can('assign', HousekeepingAssignment::class)` resolves here.
 */
class HousekeepingPolicy
{
    public function view(User $user): bool
    {
        return $user->can('housekeeping.view');
    }

    public function assign(User $user): bool
    {
        return $user->can('housekeeping.assign');
    }

    public function updateStatus(User $user): bool
    {
        return $user->can('room.status.update');
    }

    public function inspect(User $user): bool
    {
        return $user->can('room.inspect');
    }

    public function maintenance(User $user): bool
    {
        return $user->can('room.maintenance');
    }
}
