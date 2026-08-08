<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

class PackageNotEnrollableException extends RuntimeException
{
    public function render(): RedirectResponse
    {
        return back()->withErrors(['package_key' => $this->getMessage()]);
    }
}
