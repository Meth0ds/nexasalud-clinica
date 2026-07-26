<?php

declare(strict_types=1);

namespace App\Enums;

enum CommunicationChannel: string
{
    case Portal = 'portal';
    case Sms = 'sms';
    case Email = 'email';

    public function requiresTemplate(): bool
    {
        return $this !== self::Portal;
    }
}
