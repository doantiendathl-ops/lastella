<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when voidFolioOnCancellation() is called but the folio still has
 * non-voided entries. Folio remains OPEN; caller decides how to surface this.
 * ADR-36: cancellation void must not silently overwrite active charges —
 * the front-end must guide staff to void entries before cancelling.
 * System-only: no render() because this surfaces via controller error handling.
 */
class FolioHasActiveEntriesException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Folio còn mục phí chưa hủy. Vui lòng hủy hết mục phí trước khi hủy đặt phòng.'
        );
    }
}
