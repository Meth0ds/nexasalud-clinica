<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

/**
 * Signals an invalid or unverifiable identity-provider response without disclosing sensitive details.
 */
class IdentityProtocolException extends RuntimeException implements ShouldntReport
{
    /**
     * Create a safe identity-protocol exception from an optional message or prior throwable.
     */
    public function __construct(Throwable|string|null $previous = null)
    {
        parent::__construct(
            is_string($previous) && $previous !== '' ? $previous : 'The identity response could not be verified.',
            previous: $previous instanceof Throwable ? $previous : null,
        );
    }
}
