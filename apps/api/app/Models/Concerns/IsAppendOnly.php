<?php

namespace App\Models\Concerns;

use DomainException;

trait IsAppendOnly
{
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
