<?php

namespace App\Domain\Providers\Enums;

enum DocumentType: string
{
    case DniFront = 'dni_front';
    case DniBack = 'dni_back';
    case ProfessionalLicense = 'professional_license';
    case Certificate = 'certificate';
    case Other = 'other';
}
