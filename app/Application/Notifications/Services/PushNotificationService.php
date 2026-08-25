<?php

namespace App\Application\Notifications\Services;

use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Infrastructure\Notifications\Jobs\SendPushNotificationJob;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    public function sendToUser(UserModel|int $user, PushNotificationMessage $message): void
    {
        $userId = $user instanceof UserModel ? $user->id : (int) $user;

        try {
            $projectId = config('services.fcm.project_id');
            $credentialsFile = config('services.fcm.credentials_file');
            $credentialsJson = config('services.fcm.credentials_json');

            if (empty($projectId) && empty($credentialsFile) && empty($credentialsJson)) {
                Log::warning("PushNotificationService: FCM credentials missing in configuration. Notification dispatch skipped for User ID {$userId}.");
                return;
            }

            SendPushNotificationJob::dispatch($userId, $message->toArray());
        } catch (\Throwable $e) {
            Log::error("PushNotificationService exception when enqueueing notification for User ID {$userId}: " . $e->getMessage());
        }
    }
}
