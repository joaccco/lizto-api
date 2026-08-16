<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\OfferModel;

class OfferCreated
{
    public function __construct(public OfferModel $offer) {}
}
