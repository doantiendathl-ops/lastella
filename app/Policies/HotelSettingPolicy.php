<?php

namespace App\Policies;

use App\Models\HotelSetting;
use App\Models\User;

class HotelSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hotel_settings.manage');
    }

    public function update(User $user, ?HotelSetting $setting = null): bool
    {
        return $user->can('hotel_settings.manage');
    }
}
