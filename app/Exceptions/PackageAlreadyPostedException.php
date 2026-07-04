<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

class PackageAlreadyPostedException extends RuntimeException
{
    public function render(): RedirectResponse
    {
        return back()->withErrors(['package' => $this->getMessage()]);
    }
}
