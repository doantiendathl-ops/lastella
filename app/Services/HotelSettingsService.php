<?php

namespace App\Services;

use App\Models\HotelSetting;
use Illuminate\Support\Facades\Cache;

class HotelSettingsService
{
    private const CACHE_KEY = 'hotel_settings_all';
    private const CACHE_TTL = 600; // 10 minutes

    public static array $defaults = [
        'business_date_offset_hours'     => ['value' => '6',     'type' => 'int',    'description' => 'Số giờ sau nửa đêm để chuyển ngày kế toán (Business Date)'],
        'night_audit_window_start'       => ['value' => '22:00', 'type' => 'time',   'description' => 'Thời gian bắt đầu cửa sổ Kiểm toán đêm (HH:MM)'],
        'night_audit_window_end'         => ['value' => '06:00', 'type' => 'time',   'description' => 'Thời gian kết thúc cửa sổ Kiểm toán đêm (HH:MM, ngày hôm sau)'],
        'night_audit_require_sequential' => ['value' => 'true',  'type' => 'bool',   'description' => 'Yêu cầu ngày kế toán trước phải hoàn thành trước khi chạy'],
        'late_checkout_grace_minutes'    => ['value' => '30',    'type' => 'int',    'description' => 'Số phút ân hạn sau giờ trả phòng dự kiến trước khi tính phí'],
        'early_checkin_grace_minutes'    => ['value' => '30',    'type' => 'int',    'description' => 'Số phút ân hạn trước giờ nhận phòng dự kiến, không tính phí'],
        'currency_code'                  => ['value' => 'VND',   'type' => 'string', 'description' => 'Mã tiền tệ ISO 4217'],
        'currency_precision'             => ['value' => '0',     'type' => 'int',    'description' => 'Số chữ số thập phân hiển thị tiền tệ'],
        'audit_window_days'              => ['value' => '7',     'type' => 'int',    'description' => 'Số ngày tối đa cho phép kích hoạt Night Audit thủ công so với ngày kế toán hiện tại'],
        'city_tax_enabled'               => ['value' => 'false', 'type' => 'bool',   'description' => 'Tự động ghi thuế du lịch cho mọi khách đang lưu trú trong Kiểm toán đêm'],
        'city_tax_quantity'              => ['value' => '1',     'type' => 'int',    'description' => 'Số đơn vị thuế du lịch mỗi đêm (mặc định 1; tăng nếu khách sạn áp theo số phòng)'],
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            return $default ?? (self::$defaults[$key]['value'] ?? null);
        }

        return $this->cast($all[$key]['value'], $all[$key]['type']);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['true', '1', 'yes'], true);
    }

    public function set(string $key, mixed $value, int $updatedBy): void
    {
        HotelSetting::updateOrCreate(
            ['key' => $key],
            [
                'value'      => (string) $value,
                'value_type' => self::$defaults[$key]['type'] ?? 'string',
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ],
        );

        Cache::forget(self::CACHE_KEY);
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): array {
            $rows = HotelSetting::all()->keyBy('key');

            $result = [];
            foreach (self::$defaults as $key => $meta) {
                $row = $rows->get($key);
                $result[$key] = [
                    'value'       => $row?->value ?? $meta['value'],
                    'type'        => $row?->value_type ?? $meta['type'],
                    'description' => $row?->description ?? $meta['description'],
                    'updated_by'  => $row?->updatedBy?->name,
                    'updated_at'  => $row?->updated_at?->format('Y-m-d H:i'),
                ];
            }

            return $result;
        });
    }

    private function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int'  => (int) $value,
            'bool' => in_array(strtolower((string) $value), ['true', '1', 'yes'], true),
            default => $value,
        };
    }
}
