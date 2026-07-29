<?php

namespace App\Enums;

/**
 * Defines the processing lifecycle state of an invoice payment.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
