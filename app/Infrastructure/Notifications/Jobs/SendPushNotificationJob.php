<?php

namespace App\Infrastructure\Notifications\Jobs;

use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Infrastructure\Notifications\Contracts\FcmTransportInterface;
use App\Infrastructure\Persistence\Eloquent\UserDeviceModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly int $userId,
        public readonly array $messageData
    ) {}

    public function handle(FcmTransportInterface $fcmTransport): void
    {
        $user = UserModel::find($this->userId);
        if (!$user) {
            Log::warning("SendPushNotificationJob: User ID {$this->userId} not found.");
            return;
        }

        $message = PushNotificationMessage::fromArray($this->messageData);

        $activeDevices = UserDeviceModel::where('user_id', $this->userId)
            ->whereNull('revoked_at')
            ->get();

        if ($activeDevices->isEmpty()) {
            return;
        }

        foreach ($activeDevices as $device) {
            $result = $fcmTransport->send($device->device_token, $message);

            if ($result->isSuccess()) {
                $device->update(['last_active_at' => now()]);
            } elseif ($result->isInvalidToken()) {
                Log::info("Revoking dead FCM device token {$device->device_token} for user ID {$this->userId}.");
                $device->update(['revoked_at' => now()]);
            } elseif ($result->isTransientError()) {
                Log::warning("Transient FCM error for token {$device->device_token}: {$result->errorMessage}");
                throw new \RuntimeException("FCM Transient Error: {$result->errorMessage}");
            } elseif ($result->isMissingCredentials()) {
                Log::warning("FCM Push Notification dispatch skipped: Missing credentials.");
                return;
            }
        }
    }
}
