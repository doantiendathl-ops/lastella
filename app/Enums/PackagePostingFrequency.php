<?php

namespace App\Enums;

/**
 * Registry of how often a package posts a charge. Only entries returned by
 * implemented() have a real Night Audit posting path today.
 */
enum PackagePostingFrequency: string
{
    case PerNight = 'PER_NIGHT';
    case Once = 'ONCE';

    public function label(): string
    {
        return match ($this) {
            self::PerNight => 'Mỗi đêm (Night Audit)',
            self::Once => 'Một lần (khi check-in/check-out)',
        };
    }

    /** @return array<self> */
    public static function implemented(): array
    {
        return [self::PerNight];
    }

    public function isImplemented(): bool
    {
        return in_array($this, self::implemented(), true);
    }

    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label(), 'implemented' => $case->isImplemented()],
            self::cases(),
        );
    }
}
