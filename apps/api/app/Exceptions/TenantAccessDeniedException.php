<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class TenantAccessDeniedException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('No active tenant context is available.');
    }
}
