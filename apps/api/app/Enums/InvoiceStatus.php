<?php

namespace App\Enums;

/**
 * Defines the financial lifecycle state of a tenant-scoped invoice.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Voided = 'voided';
}
