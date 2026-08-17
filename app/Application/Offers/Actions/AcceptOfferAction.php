<?php

namespace App\Application\Offers\Actions;

use App\Application\Works\Actions\CreateWorkAction;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Events\OfferAccepted;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptOfferAction
{
    public function __construct(protected CreateWorkAction $createWorkAction) {}

    public function execute(OfferModel $offer, ?MatchCardModel $providedMatchCard = null): array
    {
        return DB::transaction(function () use ($offer, $providedMatchCard) {
            $serviceRequest = ServiceRequestModel::where('id', $offer->service_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($offer->status === OfferStatus::Accepted || $offer->status->value === 'accepted') {
                throw new \DomainException('Esta oferta ya fue aceptada previamente.', 409);
            }

            // 1. Guard against multiple active accepted offers
            $existingAccepted = OfferModel::where('service_request_id', $serviceRequest->id)
                ->where('status', OfferStatus::Accepted->value)
                ->exists();

            if ($existingAccepted) {
                throw new \DomainException('Esta solicitud ya tiene una oferta aceptada activa.', 409);
            }

            // 2. Resolve MatchCard if not explicitly provided
            $matchCard = $providedMatchCard;
            if (!$matchCard) {
                $matchCard = MatchCardModel::whereHas('matchSession', function ($q) use ($serviceRequest) {
                    $q->where('service_request_id', $serviceRequest->id);
                })
                ->where('provider_id', $offer->provider_id)
                ->first();
            }

            // 3. Update Offer status
            $offer->update(['status' => OfferStatus::Accepted]);

            // 4. Update MatchCard status if present
            if ($matchCard) {
                $matchCard->update([
                    'card_status' => 'accepted',
                    'decided_at' => now(),
                ]);
            }

            // 5. Update ServiceRequest status
            if ($serviceRequest->status->canTransitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::ProviderSelected)) {
                $serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::ProviderSelected);
            }

            // 6. Create Work via CreateWorkAction with resolved match_card_id
            $work = $this->createWorkAction->execute($offer, $matchCard?->id);

            if ($offer->pricing_mode === 'requires_visit' && (empty($offer->proposed_price) || $offer->proposed_price <= 0)) {
                $work->update([
                    'status' => \App\Domain\Works\Enums\WorkStatus::PendingDiagnosisQuote,
                ]);
                event(new \App\Domain\Works\Events\DiagnosisVisitRequested($work));
            }

            // 7. Create Conversation automatically
            $conversation = ConversationModel::firstOrCreate(
                [
                    'work_id' => $work->id,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'service_request_id' => $serviceRequest->id,
                    'client_id' => $serviceRequest->client_id,
                    'provider_id' => $offer->provider_id,
                    'offer_id' => $offer->id,
                ]
            );

            event(new OfferAccepted($offer));

            return [
                'offer' => $offer,
                'work' => $work,
                'conversation' => $conversation,
                'match_card' => $matchCard,
            ];
        });
    }
}
