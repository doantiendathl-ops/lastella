<?php

namespace App\Exceptions;

use RuntimeException;

class FinalCheckoutConfirmationRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Final checkout requires confirmation that all booking charges have been reviewed.');
    }
}
