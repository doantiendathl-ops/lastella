<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the daily folio number sequence exceeds 999 999.
 * ADR-6: overflow guard ensures the 6-digit zero-padded format is never
 * violated. System-only: no render() as this indicates an ops anomaly.
 */
class FolioNumberOverflowException extends RuntimeException
{
    public function __construct(string $date)
    {
        parent::__construct("Chuỗi số folio trong ngày {$date} đã đạt giới hạn tối đa (999 999).");
    }
}
