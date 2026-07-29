<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Signals missing or unauthorized tenant context without reporting it as an application fault.
 */
final class TenantAccessDeniedException extends RuntimeException implements ShouldntReport
{
    /**
     * Create a safe tenant-access-denied exception.
     */
    public function __construct()
    {
        parent::__construct('No active tenant context is available.');
    }
}
