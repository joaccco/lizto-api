<?php

namespace App\Policies;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;

class WorkPolicy
{
    public function complete(UserModel $user, WorkModel $work): bool
    {
        if ($work->client_id === $user->id) {
            return true;
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($providerProfile && $work->provider_id === $providerProfile->id) {
            return true;
        }

        return false;
    }

    public function cancel(UserModel $user, WorkModel $work): bool
    {
        if ($work->client_id === $user->id) {
            return true;
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($providerProfile && $work->provider_id === $providerProfile->id) {
            return true;
        }

        return false;
    }

    public function rate(UserModel $user, WorkModel $work): bool
    {
        return $work->client_id === $user->id;
    }
}
