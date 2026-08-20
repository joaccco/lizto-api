<?php

namespace App\Domain\Ratings\Events;

use App\Infrastructure\Persistence\Eloquent\RatingModel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReviewSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public RatingModel $rating) {}
}
