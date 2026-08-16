<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\OfferModel;

class OfferAccepted
{
    public function __construct(public OfferModel $offer) {}
}
