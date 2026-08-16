<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\OfferQuestionModel;

class QuestionAnswered
{
    public function __construct(public OfferQuestionModel $question) {}
}
