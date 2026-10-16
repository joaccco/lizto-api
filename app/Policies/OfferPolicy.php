<?php

namespace App\Policies;

use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;

class OfferPolicy
{
    /**
     * Determine whether the user can accept the offer.
     * Only the client who owns the service request can accept.
     */
    public function accept(UserModel $user, OfferModel $offer): bool
    {
        return $offer->serviceRequest !== null
            && (int) $offer->serviceRequest->client_id === (int) $user->id;
    }

    /**
     * Determine whether the user can reject the offer.
     * Only the client who owns the service request can reject.
     */
    public function reject(UserModel $user, OfferModel $offer): bool
    {
        return $offer->serviceRequest !== null
            && (int) $offer->serviceRequest->client_id === (int) $user->id;
    }

    /**
     * Determine whether the user can counter-offer.
     * Only the client owner of the service request or the offering provider can counter.
     */
    public function counter(UserModel $user, OfferModel $offer): bool
    {
        if ($offer->serviceRequest && (int) $offer->serviceRequest->client_id === (int) $user->id) {
            return true;
        }

        $provider = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($provider && (int) $offer->provider_id === (int) $provider->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can ask a question on the offer.
     * Either party of the offer negotiation can ask a question.
     */
    public function askQuestion(UserModel $user, OfferModel $offer): bool
    {
        if ($offer->serviceRequest && (int) $offer->serviceRequest->client_id === (int) $user->id) {
            return true;
        }

        $provider = ProviderProfileModel::where('user_id', $user->id)->first();
        if ($provider && (int) $offer->provider_id === (int) $provider->id) {
            return true;
        }

        return false;
    }
}
