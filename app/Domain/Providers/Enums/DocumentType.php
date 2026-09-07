<?php

namespace App\Domain\Providers\Enums;

enum DocumentType: string
{
    case DniFront = 'dni_front';
    case DniBack = 'dni_back';
    case ProfessionalLicense = 'professional_license';
    case Certificate = 'certificate';
    case Identity = 'identity';
    case Passport = 'passport';
    case DriverLicense = 'driver_license';
    case Selfie = 'selfie';
    case Other = 'other';
}
