<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Signals an expired, malformed or replayed OIDC browser transaction without reporting it as a server fault.
 */
class InvalidOidcTransactionException extends RuntimeException implements ShouldntReport
{
    /**
     * Create a safe invalid-OIDC-transaction exception.
     */
    public function __construct()
    {
        parent::__construct('The identity transaction is invalid or expired.');
    }
}
