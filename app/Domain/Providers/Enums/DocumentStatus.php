<?php

namespace App\Domain\Providers\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
