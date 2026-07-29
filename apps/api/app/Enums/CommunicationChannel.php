<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Defines the supported channels for consent-aware patient communications.
 */
enum CommunicationChannel: string
{
    case Portal = 'portal';
    case Sms = 'sms';
    case Email = 'email';

    /**
     * Determine whether this delivery channel requires an approved external template.
     */
    public function requiresTemplate(): bool
    {
        return $this !== self::Portal;
    }
}
