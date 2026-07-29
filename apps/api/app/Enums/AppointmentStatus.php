<?php

namespace App\Enums;

/**
 * Defines the permitted lifecycle states for a clinical appointment.
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Determine whether this appointment status may transition to the requested next state.
     */
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

    /**
     * Determine whether this status reserves patient, clinician or room availability.
     */
    public function blocksAvailability(): bool
    {
        return in_array($this, [
            self::Scheduled,
            self::Confirmed,
            self::CheckedIn,
            self::InProgress,
        ], true);
    }

    /**
     * Return all persisted appointment-status values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
