<?php

namespace App\Domain\Offers\Events;

use App\Infrastructure\Persistence\Eloquent\MessageModel;

class MessageSent
{
    public function __construct(public MessageModel $message) {}
}
