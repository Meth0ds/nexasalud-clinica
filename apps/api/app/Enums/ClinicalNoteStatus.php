<?php

declare(strict_types=1);

namespace App\Enums;

enum ClinicalNoteStatus: string
{
    case Draft = 'draft';
    case Signed = 'signed';
}
