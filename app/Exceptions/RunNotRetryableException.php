<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

class RunNotRetryableException extends RuntimeException
{
    public function render(): RedirectResponse
    {
        return back()->withErrors(['run' => $this->getMessage()]);
    }
}
