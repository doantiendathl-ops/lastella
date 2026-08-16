<?php

namespace App\Enums;

/**
 * Unified Services & Requests (docs/yeucaumoi.txt, Section 10/11).
 * Operational lifecycle — entirely separate from billing. A Service marked
 * COMPLETED does not stop PER_NIGHT billing; billing correctness lives in
 * FolioEntry.posting_key (see UnifiedServicePostingJob), never here.
 */
enum ServiceFulfillmentStatus: string
{
    case Created = 'CREATED';
    case Confirmed = 'CONFIRMED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Đã tạo',
            self::Confirmed => 'Đã xác nhận',
            self::Completed => 'Đã hoàn thành',
            self::Cancelled => 'Đã hủy',
        };
    }

    /** Valid forward transitions from this status. Cancelled is reachable from Created/Confirmed only, never from Completed. */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::Created => [self::Confirmed, self::Completed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStatuses(), true);
    }

    /** Whether PER_NIGHT posting should still consider this row eligible. Only Cancelled stops billing. */
    public function isBillable(): bool
    {
        return $this !== self::Cancelled;
    }
}
