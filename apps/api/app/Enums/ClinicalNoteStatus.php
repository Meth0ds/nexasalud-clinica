<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Defines whether an append-only clinical note remains a draft or has been signed.
 */
enum ClinicalNoteStatus: string
{
    case Draft = 'draft';
    case Signed = 'signed';
}
