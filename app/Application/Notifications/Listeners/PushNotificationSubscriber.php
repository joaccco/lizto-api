<?php

namespace App\Application\Notifications\Listeners;

use App\Application\Notifications\Services\PushNotificationService;
use App\Domain\Notifications\DTOs\PushNotificationMessage;
use App\Domain\Offers\Events\MessageSent;
use App\Domain\Offers\Events\OfferAccepted;
use App\Domain\Offers\Events\OfferCreated;
use App\Domain\Offers\Events\OfferRejected;
use App\Domain\Works\Events\FinalQuoteConfirmed;
use App\Domain\Works\Events\FinalQuoteRejected;
use App\Domain\Works\Events\FinalQuoteSubmitted;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class PushNotificationSubscriber
{
    public function __construct(
        protected PushNotificationService $pushService
    ) {}

    public function handleOfferCreated(OfferCreated $event): void
    {
        try {
            $offer = $event->offer;
            $client = $offer->serviceRequest?->client;
            if (!$client) {
                return;
            }

            $providerName = $offer->provider?->user?->name ?? 'Un profesional';

            $message = new PushNotificationMessage(
                eventType: 'offer_created',
                title: 'Nueva propuesta recibida',
                body: "{$providerName} te ha enviado una oferta para tu solicitud.",
                targetScreen: 'OFFER_DETAIL',
                targetParams: [
                    'offer_id' => $offer->uuid,
                    'service_request_id' => $offer->serviceRequest?->uuid,
                ]
            );

            $this->pushService->sendToUser($client, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleOfferCreated error: " . $e->getMessage());
        }
    }

    public function handleOfferAccepted(OfferAccepted $event): void
    {
        try {
            $offer = $event->offer;
            $providerUser = $offer->provider?->user;
            if (!$providerUser) {
                return;
            }

            $clientName = $offer->serviceRequest?->client?->name ?? 'El cliente';

            $message = new PushNotificationMessage(
                eventType: 'offer_accepted',
                title: '¡Oferta aceptada!',
                body: "{$clientName} ha aceptado tu propuesta de trabajo.",
                targetScreen: 'WORK_DETAIL',
                targetParams: [
                    'offer_id' => $offer->uuid,
                    'service_request_id' => $offer->serviceRequest?->uuid,
                ]
            );

            $this->pushService->sendToUser($providerUser, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleOfferAccepted error: " . $e->getMessage());
        }
    }

    public function handleOfferRejected(OfferRejected $event): void
    {
        try {
            $offer = $event->offer;
            $providerUser = $offer->provider?->user;
            if (!$providerUser) {
                return;
            }

            $clientName = $offer->serviceRequest?->client?->name ?? 'El cliente';

            $message = new PushNotificationMessage(
                eventType: 'offer_rejected',
                title: 'Oferta no aceptada',
                body: "{$clientName} ha rechazado la propuesta enviada.",
                targetScreen: 'OFFER_DETAIL',
                targetParams: [
                    'offer_id' => $offer->uuid,
                    'service_request_id' => $offer->serviceRequest?->uuid,
                ]
            );

            $this->pushService->sendToUser($providerUser, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleOfferRejected error: " . $e->getMessage());
        }
    }

    public function handleFinalQuoteSubmitted(FinalQuoteSubmitted $event): void
    {
        try {
            $work = $event->work;
            $client = $work->client;
            if (!$client) {
                return;
            }

            $providerName = $work->provider?->user?->name ?? 'El profesional';

            $message = new PushNotificationMessage(
                eventType: 'final_quote_submitted',
                title: 'Presupuesto final listo',
                body: "{$providerName} te envió el presupuesto para el servicio.",
                targetScreen: 'WORK_DETAIL',
                targetParams: [
                    'work_id' => $work->uuid,
                ]
            );

            $this->pushService->sendToUser($client, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleFinalQuoteSubmitted error: " . $e->getMessage());
        }
    }

    public function handleFinalQuoteConfirmed(FinalQuoteConfirmed $event): void
    {
        try {
            $work = $event->work;
            $providerUser = $work->provider?->user;
            if (!$providerUser) {
                return;
            }

            $clientName = $work->client?->name ?? 'El cliente';

            $message = new PushNotificationMessage(
                eventType: 'final_quote_confirmed',
                title: 'Presupuesto confirmado',
                body: "{$clientName} ha aceptado el presupuesto final del trabajo.",
                targetScreen: 'WORK_DETAIL',
                targetParams: [
                    'work_id' => $work->uuid,
                ]
            );

            $this->pushService->sendToUser($providerUser, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleFinalQuoteConfirmed error: " . $e->getMessage());
        }
    }

    public function handleFinalQuoteRejected(FinalQuoteRejected $event): void
    {
        try {
            $work = $event->work;
            $providerUser = $work->provider?->user;
            if (!$providerUser) {
                return;
            }

            $clientName = $work->client?->name ?? 'El cliente';

            $message = new PushNotificationMessage(
                eventType: 'final_quote_rejected',
                title: 'Presupuesto rechazado',
                body: "{$clientName} ha rechazado el presupuesto final del trabajo.",
                targetScreen: 'WORK_DETAIL',
                targetParams: [
                    'work_id' => $work->uuid,
                ]
            );

            $this->pushService->sendToUser($providerUser, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleFinalQuoteRejected error: " . $e->getMessage());
        }
    }

    public function handleMessageSent(MessageSent $event): void
    {
        try {
            $msg = $event->message;
            $conversation = $msg->conversation;
            if (!$conversation) {
                return;
            }

            $senderId = (int) $msg->sender_id;
            $clientId = (int) $conversation->client_id;
            $providerUserId = (int) ($conversation->provider?->user_id ?? 0);

            if ($senderId === $clientId) {
                $recipientUserId = $providerUserId;
                $senderName = $conversation->client?->name ?? 'El cliente';
            } else {
                $recipientUserId = $clientId;
                $senderName = $conversation->provider?->user?->name ?? 'El profesional';
            }

            if ($recipientUserId <= 0) {
                return;
            }

            $message = new PushNotificationMessage(
                eventType: 'new_chat_message',
                title: $senderName,
                body: 'Te envió un nuevo mensaje.',
                targetScreen: 'CONVERSATION',
                targetParams: [
                    'conversation_id' => $conversation->uuid,
                    'work_id' => $conversation->work?->uuid,
                ]
            );

            $this->pushService->sendToUser($recipientUserId, $message);
        } catch (\Throwable $e) {
            Log::error("PushNotificationSubscriber handleMessageSent error: " . $e->getMessage());
        }
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            OfferCreated::class => 'handleOfferCreated',
            OfferAccepted::class => 'handleOfferAccepted',
            OfferRejected::class => 'handleOfferRejected',
            FinalQuoteSubmitted::class => 'handleFinalQuoteSubmitted',
            FinalQuoteConfirmed::class => 'handleFinalQuoteConfirmed',
            FinalQuoteRejected::class => 'handleFinalQuoteRejected',
            MessageSent::class => 'handleMessageSent',
        ];
    }
}
