<?php

namespace App\Domain\Clarification\Events;

use App\Infrastructure\Persistence\Eloquent\ServiceBriefModel;

class BriefConfirmed
{
    public function __construct(public ServiceBriefModel $brief) {}
}
