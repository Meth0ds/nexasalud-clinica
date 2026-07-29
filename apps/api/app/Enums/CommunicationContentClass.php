<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Classifies patient communication content for consent and delivery controls.
 */
enum CommunicationContentClass: string
{
    case Administrative = 'administrative';
    case ClinicalLink = 'clinical_link';
}
