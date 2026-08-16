<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\OfferModel;

class OfferRejected
{
    public function __construct(public OfferModel $offer, public ?string $reason = null) {}
}
