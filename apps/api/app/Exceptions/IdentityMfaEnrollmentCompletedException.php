<?php

declare(strict_types=1);

namespace App\Exceptions;

final class IdentityMfaEnrollmentCompletedException extends IdentityProtocolException
{
    public function __construct()
    {
        parent::__construct('A second authentication with the newly enrolled factor is required.');
    }
}
