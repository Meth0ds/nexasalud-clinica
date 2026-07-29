<?php

namespace App\Enums;

/**
 * Defines the legal lifecycle state of a patient consent record.
 */
enum ConsentStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
}
