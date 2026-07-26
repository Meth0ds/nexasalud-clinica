<?php

namespace App\Enums;

enum ConsentStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
