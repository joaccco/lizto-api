<?php

namespace App\Domain\Providers\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
