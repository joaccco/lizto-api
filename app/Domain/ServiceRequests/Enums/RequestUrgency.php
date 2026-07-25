<?php

namespace App\Domain\ServiceRequests\Enums;

enum RequestUrgency: string
{
    case Immediate = 'immediate';
    case Today = 'today';
    case Scheduled = 'scheduled';
    case Flexible = 'flexible';
}
