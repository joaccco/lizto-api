<?php

namespace App\Application\Works\Actions;

use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Support\Str;

class CreateWorkAction
{
    public function execute(OfferModel $offer, ?int $matchCardId = null): WorkModel
    {
        $serviceRequest = $offer->serviceRequest;

        return WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'match_card_id' => $matchCardId,
            'client_id' => $serviceRequest->client_id,
            'provider_id' => $offer->provider_id,
            'status' => WorkStatus::Confirmed,
            'currency' => $offer->currency_code ?? 'ARS',
            'scheduled_at' => $offer->proposed_start_at,
            'estimated_duration_min' => $offer->estimated_duration_min ?? 60,
            'work_lat' => $serviceRequest->location_lat,
            'work_lng' => $serviceRequest->location_lng,
            'work_address' => $serviceRequest->location_address,
        ]);
    }
}
