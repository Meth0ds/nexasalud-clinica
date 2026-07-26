<?php

declare(strict_types=1);

namespace App\Enums;

enum CommunicationStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Cancelled = 'cancelled';

    public function canBeCancelled(): bool
    {
        return $this === self::Draft || $this === self::Queued;
    }
}
