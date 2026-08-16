<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\ConversationModel;

class ContactInfoDetected
{
    public function __construct(
        public ConversationModel $conversation,
        public int|string $senderId,
        public string $text
    ) {}
}
