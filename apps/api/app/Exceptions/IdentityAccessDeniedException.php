<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class IdentityAccessDeniedException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('Identity access was denied.');
    }
}
