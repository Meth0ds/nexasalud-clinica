<?php

namespace App\Models\Concerns;

use DomainException;

/**
 * Prevents changes and deletion of clinical records that must remain historically intact.
 */
trait IsAppendOnly
{
    /**
     * Register model events that reject update and deletion attempts.
     */
    protected static function bootIsAppendOnly(): void
    {
        static::updating(function (): never {
            throw new DomainException('Append-only clinical records cannot be updated.');
        });

        static::deleting(function (): never {
            throw new DomainException('Append-only clinical records cannot be deleted.');
        });
    }
}
