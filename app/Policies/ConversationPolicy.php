<?php

namespace App\Policies;

use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;

class ConversationPolicy
{
    /**
     * Determine whether the user can view the conversation messages.
     */
    public function view(UserModel $user, ConversationModel $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    /**
     * Determine whether the user can send a message in the conversation.
     */
    public function sendMessage(UserModel $user, ConversationModel $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    private function isParticipant(UserModel $user, ConversationModel $conversation): bool
    {
        if ((int) $conversation->client_id === (int) $user->id) {
            return true;
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($providerProfile && (int) $conversation->provider_id === (int) $providerProfile->id) {
            return true;
        }

        return false;
    }
}
