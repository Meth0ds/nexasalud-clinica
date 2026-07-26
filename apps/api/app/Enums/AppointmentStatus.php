<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Scheduled => [self::Confirmed, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow => [],
        }, true);
    }

    public function blocksAvailability(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Confirmed,
            self::CheckedIn,
            self::InProgress,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
