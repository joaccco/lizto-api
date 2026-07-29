<?php

namespace App\Http\Controllers\Api\V1\Matching;

use App\Application\Matching\Actions\RunMatchingAction;
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
        $user = $request->user();

        $session = MatchSessionModel::where('uuid', $uuid)->firstOrFail();
        if ($session->serviceRequest->client_id !== $user->id) {
            abort(403, 'Unauthorized access to session.');
        }

        $card = MatchCardModel::where('match_session_id', $session->id)
            ->where('id', $cardId)
            ->firstOrFail();

        $card->update([
            'card_status' => 'accepted',
            'shown_at' => $card->shown_at ?? now(),
            'decided_at' => now(),
        ]);

        $session->increment('total_shown');
        $session->serviceRequest->update(['status' => 'provider_selected']);

        $card->load('provider.user');

        return response()->json([
            'data' => (new MatchCardResource($card))->resolve(),
            'message' => 'Tarjeta aceptada.',
        ], 200);
    }

    public function reject(Request $request, string $uuid, int $cardId): JsonResponse
    {
        $user = $request->user();

        $session = MatchSessionModel::where('uuid', $uuid)->firstOrFail();
        if ($session->serviceRequest->client_id !== $user->id) {
            abort(403, 'Unauthorized access to session.');
        }

        $card = MatchCardModel::where('match_session_id', $session->id)
            ->where('id', $cardId)
            ->firstOrFail();

        $card->update([
            'card_status' => 'rejected',
            'decided_at' => now(),
        ]);

        $session->increment('total_shown');

        $nextCard = MatchCardModel::where('match_session_id', $session->id)
            ->where('card_status', 'pending')
            ->orderBy('rank_position')
            ->with('provider.user')
            ->first();

        return response()->json([
            'data' => [
                'rejected_card_id' => $card->id,
                'next_card' => $nextCard ? (new MatchCardResource($nextCard))->resolve() : null,
            ],
            'message' => 'Tarjeta rechazada.',
        ], 200);
    }

    public function recover(Request $request, string $uuid, int $cardId): JsonResponse
    {
        $user = $request->user();

        $session = MatchSessionModel::where('uuid', $uuid)->firstOrFail();
        if ($session->serviceRequest->client_id !== $user->id) {
            abort(403, 'Unauthorized access to session.');
        }

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
            'decided_at' => null,
        ]);

        $card->load('provider.user');

        return response()->json([
            'data' => (new MatchCardResource($card))->resolve(),
            'message' => 'Tarjeta recuperada.',
        ], 200);
    }
}
