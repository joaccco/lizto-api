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

    public function submitFinalQuote(UserModel $user, WorkModel $work): bool
    {
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        return $providerProfile !== null
            && (int) $work->provider_id === (int) $providerProfile->id
            && $providerProfile->isIdentityVerified();
    }

    public function confirmFinalQuote(UserModel $user, WorkModel $work): bool
    {
        return (int) $work->client_id === (int) $user->id;
    }

    public function rejectFinalQuote(UserModel $user, WorkModel $work): bool
    {
        return (int) $work->client_id === (int) $user->id;
    }
}
