<?php

declare(strict_types=1);

namespace App\Enums;

enum CommunicationContentClass: string
{
    case Administrative = 'administrative';
    case ClinicalLink = 'clinical_link';
}
