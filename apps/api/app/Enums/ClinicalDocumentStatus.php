<?php

namespace App\Enums;

enum ClinicalDocumentStatus: string
{
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Voided = 'voided';
    case Expired = 'expired';
}
