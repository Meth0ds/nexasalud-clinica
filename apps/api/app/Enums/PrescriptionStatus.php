<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Signed = 'signed';
    case Sent = 'sent';
    case Cancelled = 'cancelled';

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

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
