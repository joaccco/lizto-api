<?php

namespace App\Domain\Offers\Enums;

enum OfferStatus: string
{
    case Pending = 'pending';
    case Countered = 'countered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
