<?php

namespace App\Infrastructure\Notifications\Contracts;

use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Infrastructure\Notifications\ValueObjects\FcmSendResult;

interface FcmTransportInterface
{
    public function send(string $deviceToken, PushNotificationMessage $message): FcmSendResult;
}
