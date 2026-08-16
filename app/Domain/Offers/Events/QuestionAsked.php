<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\OfferQuestionModel;

class QuestionAsked
{
    public function __construct(public OfferQuestionModel $question) {}
}
