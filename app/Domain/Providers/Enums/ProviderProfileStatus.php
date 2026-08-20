<?php

namespace App\Domain\Providers\Enums;

enum ProviderProfileStatus: string
{
    case Draft = 'draft';
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
