<?php

namespace App\Enums;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Offered = 'offered';
    case Booked = 'booked';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
