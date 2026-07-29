<?php

namespace App\Enums;

/**
 * Defines the controlled clinical lifecycle states for a prescription.
 */
enum PrescriptionStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Signed = 'signed';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

    /**
     * Determine whether this prescription status may transition to the requested next state.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::PendingReview, self::Cancelled],
            self::PendingReview => [self::Draft, self::Signed, self::Cancelled],
            self::Signed => [self::Sent, self::Cancelled],
            self::Sent => [self::Cancelled],
            self::Cancelled => [],
        }, true);
    }

    /**
     * Determine whether the prescription may still have its medication details edited.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Return all persisted prescription-status values.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
