<?php

namespace App\Domain\Clarification\Events;

use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;

class ClarificationCompleted
{
    public function __construct(public ServiceRequestModel $request) {}
}
