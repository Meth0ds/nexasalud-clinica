<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Signals that a newly enrolled MFA factor requires a fresh authentication before use.
 */
final class IdentityMfaEnrollmentCompletedException extends IdentityProtocolException
{
    /**
     * Create the safe reauthentication-required exception.
     */
    public function __construct()
    {
        parent::__construct('A second authentication with the newly enrolled factor is required.');
    }
}
