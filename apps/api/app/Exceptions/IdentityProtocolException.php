<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

class IdentityProtocolException extends RuntimeException implements ShouldntReport
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The identity response could not be verified.', previous: $previous);
    }
}
