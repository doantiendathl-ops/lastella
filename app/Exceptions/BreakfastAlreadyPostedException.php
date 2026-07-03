<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

class BreakfastAlreadyPostedException extends RuntimeException
{
    public function render(): RedirectResponse
    {
        return back()->withErrors(['package' => $this->getMessage()]);
    }
}
