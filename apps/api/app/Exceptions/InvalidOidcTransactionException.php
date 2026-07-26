<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InvalidOidcTransactionException extends RuntimeException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct('The identity transaction is invalid or expired.');
    }
}
