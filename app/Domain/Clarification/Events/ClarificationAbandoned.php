<?php

namespace App\Domain\Clarification\Events;

use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;

class ClarificationAbandoned
{
    public function __construct(public ServiceRequestModel $request) {}
}
