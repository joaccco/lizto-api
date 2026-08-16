<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\OfferModel;

class OfferCountered
{
    public function __construct(public OfferModel $offer) {}
}
