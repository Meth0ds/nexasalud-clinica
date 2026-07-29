<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use InvalidArgumentException;
use RuntimeException;

/**
 * Signals that the user must explicitly choose among multiple authorized tenant contexts.
 */
final class TenantSelectionRequiredException extends RuntimeException implements ShouldntReport
{
    /**
     * Create an exception for a supported tenant or clinic context-selection requirement.
     */
    public function __construct(private readonly string $selection)
    {
        if (! in_array($selection, ['tenant', 'clinic'], true)) {
            throw new InvalidArgumentException('Unsupported tenant context selection.');
        }

        parent::__construct('An explicit tenant context selection is required.');
    }

    /**
     * Return the context dimension that requires an explicit selection.
     */
    public function selection(): string
    {
        return $this->selection;
    }
}
