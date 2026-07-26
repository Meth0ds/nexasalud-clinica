<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

class IdentityProtocolException extends RuntimeException implements ShouldntReport
{
    public function __construct(Throwable|string|null $previous = null)
    {
        parent::__construct(
            is_string($previous) && $previous !== '' ? $previous : 'The identity response could not be verified.',
            previous: $previous instanceof Throwable ? $previous : null,
        );
    }
}
