<?php

namespace App\Enums;

/**
 * Defines the generation, availability and retention states of a clinical document.
 */
enum ClinicalDocumentStatus: string
{
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Voided = 'voided';
    case Expired = 'expired';
}
