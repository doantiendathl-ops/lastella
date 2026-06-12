<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'company_name', 'value' => 'Lastella Hotel', 'type' => 'string', 'group' => 'company', 'is_public' => true],
            ['key' => 'company_phone', 'value' => '+66 00 000 0000', 'type' => 'string', 'group' => 'company', 'is_public' => true],
            ['key' => 'company_address', 'value' => 'Bangkok, Thailand', 'type' => 'string', 'group' => 'company', 'is_public' => true],
            ['key' => 'default_checkin_time', 'value' => '14:00', 'type' => 'time', 'group' => 'stay', 'is_public' => true],
            ['key' => 'default_checkout_time', 'value' => '12:00', 'type' => 'time', 'group' => 'stay', 'is_public' => true],
            ['key' => 'currency', 'value' => 'THB', 'type' => 'string', 'group' => 'localization', 'is_public' => true],
            ['key' => 'timezone', 'value' => 'Asia/Bangkok', 'type' => 'string', 'group' => 'localization', 'is_public' => true],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                [
                    'value' => ['value' => $setting['value']],
                    'type' => $setting['type'],
                    'group' => $setting['group'],
                    'is_public' => $setting['is_public'],
                ],
            );
        }
    }
}
