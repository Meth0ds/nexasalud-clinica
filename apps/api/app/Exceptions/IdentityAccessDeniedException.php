<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Signals an expected identity-provider denial that should not be reported as an application failure.
 */
class IdentityAccessDeniedException extends RuntimeException implements ShouldntReport
{
    /**
     * Create a safe identity-access-denied exception.
     */
    public function __construct()
    {
        parent::__construct('Identity access was denied.');
    }
}
