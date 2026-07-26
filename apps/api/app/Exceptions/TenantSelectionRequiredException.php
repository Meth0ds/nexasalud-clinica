<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use InvalidArgumentException;
use RuntimeException;

final class TenantSelectionRequiredException extends RuntimeException implements ShouldntReport
{
    public function __construct(private readonly string $selection)
    {
        if (! in_array($selection, ['tenant', 'clinic'], true)) {
            throw new InvalidArgumentException('Unsupported tenant context selection.');
        }

        parent::__construct('An explicit tenant context selection is required.');
    }

    public function selection(): string
    {
        return $this->selection;
    }
}
