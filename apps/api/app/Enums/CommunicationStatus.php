<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Defines the workflow state of a patient communication before delivery.
 */
enum CommunicationStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Cancelled = 'cancelled';

    /**
     * Determine whether this communication status may still be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return $this === self::Draft || $this === self::Queued;
    }
}
