<?php

namespace App\Services;

use App\Models\Setting;
use App\Repositories\Eloquent\SettingRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SettingService
{
    public const MANAGED_KEYS = [
        'company_name' => ['type' => 'string', 'group' => 'company', 'is_public' => true],
        'company_phone' => ['type' => 'string', 'group' => 'company', 'is_public' => true],
        'company_address' => ['type' => 'string', 'group' => 'company', 'is_public' => true],
        'default_checkin_time' => ['type' => 'time', 'group' => 'stay', 'is_public' => true],
        'default_checkout_time' => ['type' => 'time', 'group' => 'stay', 'is_public' => true],
        'currency' => ['type' => 'string', 'group' => 'localization', 'is_public' => true],
        'timezone' => ['type' => 'string', 'group' => 'localization', 'is_public' => true],
    ];

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->settings->paginate($filters);
    }

    public function values(): array
    {
        return Setting::query()
            ->whereIn('key', array_keys(self::MANAGED_KEYS))
            ->get()
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->typed_value])
            ->all();
    }

    public function updateManaged(array $data): void
    {
        foreach (self::MANAGED_KEYS as $key => $meta) {
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => ['value' => $data[$key] ?? null],
                    'type' => $meta['type'],
                    'group' => $meta['group'],
                    'is_public' => $meta['is_public'],
                ],
            );
        }
    }

    public function create(array $data): Setting
    {
        $data['value'] = $this->normalizeValue($data['value'] ?? null, $data['type']);

        /** @var Setting $setting */
        $setting = $this->settings->create($data);

        return $setting;
    }

    public function update(Setting $setting, array $data): Setting
    {
        $data['value'] = $this->normalizeValue($data['value'] ?? null, $data['type']);

        /** @var Setting $setting */
        $setting = $this->settings->update($setting, $data);

        return $setting;
    }

    public function delete(Setting $setting): void
    {
        $this->settings->delete($setting);
    }

    private function normalizeValue(mixed $value, string $type): array
    {
        if ($type === 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        if ($type === 'integer') {
            $value = (int) $value;
        }

        if ($type === 'json' && is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return ['value' => $value];
    }
}
