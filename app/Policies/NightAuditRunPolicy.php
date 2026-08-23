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

    /**
     * "Tính lại" (Phần 2) reuses the existing night_audit.run permission
     * rather than introducing a new one — recalculating is, from a
     * permissions standpoint, the same trust level as triggering a run in
     * the first place; no admin org has ever needed to grant one without
     * the other.
     */
    public function recalculate(User $user, NightAuditRun $run): bool
    {
        return $user->can('night_audit.run');
    }
}
