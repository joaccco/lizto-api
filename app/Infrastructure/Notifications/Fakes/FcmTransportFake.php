<?php

namespace App\Infrastructure\Notifications\Fakes;

use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Infrastructure\Notifications\Contracts\FcmTransportInterface;
use App\Infrastructure\Notifications\ValueObjects\FcmSendResult;

class FcmTransportFake implements FcmTransportInterface
{
    protected array $sentMessages = [];
    protected array $invalidTokens = [];
    protected array $transientErrorTokens = [];

    public function markTokenAsInvalid(string $token): void
    {
        $this->invalidTokens[] = $token;
    }

    public function markTokenAsTransientError(string $token): void
    {
        $this->transientErrorTokens[] = $token;
    }

    public function send(string $deviceToken, PushNotificationMessage $message): FcmSendResult
    {
        $credentialsFile = config('services.fcm.credentials_file');
        $credentialsJson = config('services.fcm.credentials_json');
        $projectId = config('services.fcm.project_id');

        if (empty($credentialsFile) && empty($credentialsJson) && empty($projectId)) {
            return FcmSendResult::missingCredentials();
        }

        if (in_array($deviceToken, $this->invalidTokens, true)) {
            return FcmSendResult::invalidToken('UNREGISTERED: Requested entity was not found.');
        }

        if (in_array($deviceToken, $this->transientErrorTokens, true)) {
            return FcmSendResult::transientError('HTTP 500 Internal Server Error / Timeout');
        }

        $this->sentMessages[] = [
            'token' => $deviceToken,
            'message' => $message,
        ];

        return FcmSendResult::success('projects/lizto/messages/fake_id_' . count($this->sentMessages));
    }

    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function hasSentToToken(string $token): bool
    {
        foreach ($this->sentMessages as $item) {
            if ($item['token'] === $token) {
                return true;
            }
        }
        return false;
    }
}
