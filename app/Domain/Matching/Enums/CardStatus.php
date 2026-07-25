<?php

namespace App\Domain\Matching\Enums;

enum CardStatus: string
{
    case Pending = 'pending';
    case Shown = 'shown';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Recovered = 'recovered';
}
