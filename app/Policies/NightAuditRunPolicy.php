<?php

namespace App\Policies;

use App\Models\NightAuditRun;
use App\Models\User;

class NightAuditRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('night_audit.view');
    }

    public function view(User $user, NightAuditRun $run): bool
    {
        return $user->can('night_audit.view');
    }

    public function run(User $user): bool
    {
        return $user->can('night_audit.run');
    }

    public function trigger(User $user): bool
    {
        return $user->can('night_audit.run');
    }

    public function retry(User $user, NightAuditRun $run): bool
    {
        return $user->can('night_audit.run');
    }
}
