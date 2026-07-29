<?php

namespace App\Http\Controllers\Api\V1\Matching;

use App\Application\Matching\Actions\RunMatchingAction;
use App\Domain\Matching\Enums\CardStatus;
use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatchCardResource;
use App\Http\Resources\MatchSessionResource;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MatchSessionController extends Controller
{
    public function createSession(Request $request, string $uuid, RunMatchingAction $action): JsonResponse
    {
        $user = $request->user();

        $serviceRequest = ServiceRequestModel::where('uuid', $uuid)
            ->where('client_id', $user->id)
            ->firstOrFail();

        $results = $action->execute($serviceRequest);

        // Delete existing session if exists to recreate
        if ($serviceRequest->matchSession) {
            $serviceRequest->matchSession->delete();
        }

        $session = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'status' => 'active',
            'total_shown' => 0,
        ]);

        foreach ($results as $index => $item) {
            MatchCardModel::create([
                'match_session_id' => $session->id,
                'provider_id' => $item['provider']->id,
                'rank_position' => $index + 1,
                'score_total' => $item['score_total'],
                'score_breakdown' => $item['score_breakdown'],
                'snapshot' => $item['snapshot'],
                'card_status' => 'pending',
            ]);
        }

        $serviceRequest->update(['status' => 'matching_active']);

        $session->load(['cards' => function ($q) {
            $q->orderBy('rank_position')->with('provider.user');
        }]);

        return response()->json([
            'data' => (new MatchSessionResource($session))->resolve(),
        ], 201);
    }

    public function accept(Request $request, string $uuid, int $cardId): JsonResponse
    {
        $session = MatchSessionModel::where('uuid', $uuid)
            ->firstOrFail();

        $serviceRequest = $session->serviceRequest()->firstOrFail();

        if ($serviceRequest->client_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $card = MatchCardModel::where('id', $cardId)
            ->where('match_session_id', $session->id)
            ->firstOrFail();

        $card->update([
            'card_status' => CardStatus::Accepted->value,
            'shown_at'    => $card->shown_at ?? now(),
            'decided_at'  => now(),
        ]);

        $session->increment('total_shown');

        $serviceRequest->update([
            'status' => RequestStatus::ProviderSelected->value,
        ]);

        return response()->json([
            'data' => [
                'card_id'     => $card->id,
                'card_status' => $card->card_status,
                'provider_id' => $card->provider_id,
                'decided_at'  => $card->decided_at,
            ],
            'message' => 'Profesional seleccionado correctamente.',
        ]);
    }

    public function reject(Request $request, string $uuid, int $cardId): JsonResponse
    {
        $session = MatchSessionModel::where('uuid', $uuid)
            ->firstOrFail();

        $serviceRequest = $session->serviceRequest()->firstOrFail();

        if ($serviceRequest->client_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $card = MatchCardModel::where('id', $cardId)
            ->where('match_session_id', $session->id)
            ->first();

        if (!$card) {
            return response()->json([
                'message' => 'Card no encontrada.',
                'data'    => null,
            ], 404);
        }

        $card->update([
            'card_status' => CardStatus::Rejected->value,
            'decided_at'  => now(),
        ]);

        $session->increment('total_shown');

        $nextCard = MatchCardModel::where('match_session_id', $session->id)
            ->where('card_status', CardStatus::Pending->value)
            ->orderBy('rank_position')
            ->first();

        return response()->json([
            'data' => [
                'rejected_card_id' => $card->id,
                'next_card'        => $nextCard ? [
                    'card_id'       => $nextCard->id,
                    'rank_position' => $nextCard->rank_position,
                    'provider_id'   => $nextCard->provider_id,
                    'score_total'   => $nextCard->score_total,
                    'snapshot'      => $nextCard->snapshot,
                ] : null,
                'has_more' => $nextCard !== null,
            ],
            'message' => $nextCard
                ? 'Card descartada.'
                : 'No hay más profesionales disponibles.',
        ]);
    }

    public function recover(Request $request, string $uuid, int $cardId): JsonResponse
    {
        $session = MatchSessionModel::where('uuid', $uuid)
            ->whereHas('serviceRequest', function ($q) use ($request) {
                $q->where('client_id', $request->user()->id);
            })
            ->with(['serviceRequest', 'cards'])
            ->firstOrFail();

        $card = MatchCardModel::where('match_session_id', $session->id)
            ->where('id', $cardId)
            ->where('card_status', 'rejected')
            ->first();

        if (!$card) {
            $card = MatchCardModel::where('match_session_id', $session->id)
                ->where('card_status', 'rejected')
                ->orderByDesc('decided_at')
                ->firstOrFail();
        }

        $card->update([
            'card_status' => 'recovered',
            'decided_at'  => null,
        ]);

        return response()->json([
            'data' => new MatchCardResource($card->load('provider.user')),
            'message' => 'Tarjeta recuperada.',
        ]);
    }
}
