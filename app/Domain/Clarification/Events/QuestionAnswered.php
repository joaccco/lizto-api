<?php

namespace App\Domain\Clarification\Events;

use App\Infrastructure\Persistence\Eloquent\RequestAnswerModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;

class QuestionAnswered
{
    public function __construct(
        public ServiceRequestModel $request,
        public RequestAnswerModel $answer
    ) {}
}
