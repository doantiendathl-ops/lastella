<?php

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function view(User $user, Setting $setting): bool
    {
        return $user->can('settings.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('settings.manage');
    }

    public function update(User $user, ?Setting $setting = null): bool
    {
        return $user->can('settings.manage');
    }

    public function delete(User $user, Setting $setting): bool
    {
        return $user->can('settings.manage');
    }

    public function restore(User $user, Setting $setting): bool
    {
        return $user->can('settings.manage');
    }
}
