<?php

namespace App\Enums;

/**
 * Defines the lifecycle state of an appointment waitlist request.
 */
enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Offered = 'offered';
    case Booked = 'booked';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /**
     * Return all persisted waitlist-status values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
