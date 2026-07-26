<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class IdentityUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The identity provider is unavailable.', previous: $previous);
    }

    /**
     * @return array{identity_failure: string}
     */
    public function context(): array
    {
        return ['identity_failure' => 'upstream_unavailable'];
    }
}
