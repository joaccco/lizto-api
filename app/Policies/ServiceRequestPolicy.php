<?php

namespace App\Policies;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;

class ServiceRequestPolicy
{
    public function view(UserModel $user, ServiceRequestModel $serviceRequest): bool
    {
        if ($serviceRequest->client_id === $user->id) {
            return true;
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$providerProfile) {
            return false;
        }

        return $serviceRequest->works()->where('provider_id', $providerProfile->id)->exists();
    }

    public function cancel(UserModel $user, ServiceRequestModel $serviceRequest): bool
    {
        return $serviceRequest->client_id === $user->id;
    }

    public function respond(UserModel $user, ServiceRequestModel $serviceRequest): bool
    {
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$providerProfile) {
            return false;
        }

        $hasCard = $serviceRequest->matchSession()
            ->whereHas('cards', function ($q) use ($providerProfile) {
                $q->where('provider_id', $providerProfile->id);
            })->exists();

        if ($hasCard) {
            return true;
        }

        $hasWork = $serviceRequest->works()->where('provider_id', $providerProfile->id)->exists();
        if ($hasWork) {
            return true;
        }

        if ($serviceRequest->category_id) {
            return $providerProfile->categories()
                ->where('category_id', $serviceRequest->category_id)
                ->where('is_active', true)
                ->exists();
        }

        return false;
    }
}
