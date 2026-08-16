<?php

namespace App\Domain\Offers\Events;

class ContactInfoBlocked
{
    public function __construct(
        public int|string $userId,
        public string $text
    ) {}
}
