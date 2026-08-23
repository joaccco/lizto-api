<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderDashboardController extends Controller
{
    use \App\Traits\ResolvesByUuid;

    public function __construct(protected \App\Application\Offers\Actions\AcceptOfferAction $acceptOfferAction) {}

    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:available,busy,unavailable',
        ]);

        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if ($providerProfile) {
            $providerProfile->update([
                'availability_status' => $request->status,
            ]);
        }

        return response()->json([
            'data' => [
                'status' => $request->status,
            ],
            'message' => 'Disponibilidad actualizada.',
        ]);
    }

    public function workRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            return response()->json(['data' => []]);
        }

        $categoryIds = $providerProfile->categories()->pluck('category_id')->filter()->toArray();

        $requests = ServiceRequestModel::query()
            ->where(function ($query) use ($providerProfile, $categoryIds) {
                $query->whereHas('matchSession.cards', function ($q) use ($providerProfile) {
                    $q->where('provider_id', $providerProfile->id);
                })
                ->orWhereHas('works', function ($q) use ($providerProfile) {
                    $q->where('provider_id', $providerProfile->id);
                });
                if (!empty($categoryIds)) {
                    $query->orWhereIn('category_id', $categoryIds);
                }
            })
            ->with(['category', 'client', 'works' => function ($q) use ($providerProfile) {
                $q->where('provider_id', $providerProfile->id)->with('conversation');
            }])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $items = $requests->map(function ($sr) use ($user) {
            $work = $sr->works->first();
            $effectiveStatus = $work ? $work->status->value : ($sr->status instanceof \BackedEnum ? $sr->status->value : $sr->status);
            $locationData = \App\Domain\Location\Services\LocationPresenter::present($sr, $user);

            return array_merge([
                'id' => $sr->uuid,
                'work_id' => $work?->uuid,
                'conversation_id' => $work?->conversation?->uuid,
                'category' => $sr->category ? $sr->category->name : 'Servicio general',
                'category_slug' => $sr->category ? $sr->category->slug : 'general',
                'raw_prompt' => $sr->raw_prompt,
                'client_name' => $sr->client ? explode(' ', $sr->client->name)[0] : 'Cliente',
                'urgency' => $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : $sr->urgency,
                'status' => $effectiveStatus,
                'estimated_duration_min' => $work?->estimated_duration_min ?? 60,
                'created_at' => $sr->created_at?->toISOString(),
            ], $locationData);
        });

        return response()->json(['data' => $items]);
    }

    public function agenda(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            return response()->json(['data' => []]);
        }

        $works = \App\Infrastructure\Persistence\Eloquent\WorkModel::query()
            ->where('provider_id', $providerProfile->id)
            ->with(['serviceRequest.category', 'client'])
            ->orderBy('scheduled_at')
            ->get();

        $events = $works->map(function ($work) {
            $scheduledAt = $work->scheduled_at ?? $work->created_at;

            return [
                'id' => $work->uuid,
                'work_id' => $work->uuid,
                'client_name' => $work->client ? $work->client->name : 'Cliente',
                'client_email' => $work->client ? $work->client->email : '',
                'job_type' => $work->serviceRequest?->raw_prompt ?? 'Servicio agendado',
                'category' => $work->serviceRequest?->category?->name ?? 'Servicio general',
                'address' => $work->work_address ?? 'Domicilio del cliente',
                'status' => $work->status->value,
                'scheduled_at' => $scheduledAt?->toISOString(),
                'day' => (int) $scheduledAt?->format('j'),
                'month' => (int) $scheduledAt?->format('n'),
                'year' => (int) $scheduledAt?->format('Y'),
                'time' => $scheduledAt?->format('H:i') ?? '09:00',
                'estimated_duration_min' => $work->estimated_duration_min ?? 60,
                'agreed_price' => $work->agreed_price,
            ];
        });

        return response()->json(['data' => $events]);
    }

    public function confirmWorkRequest(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'estimated_duration_min' => 'nullable|integer',
            'scheduled_at' => 'nullable|date',
        ]);

        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $id);
        if (!$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('respond', $serviceRequest);

        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$providerProfile) {
            return response()->json(['message' => 'Perfil de proveedor no encontrado.'], 404);
        }

        $matchCard = \App\Infrastructure\Persistence\Eloquent\MatchCardModel::whereHas('matchSession', function ($q) use ($serviceRequest) {
            $q->where('service_request_id', $serviceRequest->id);
        })
        ->where('provider_id', $providerProfile->id)
        ->first();

        if (!$matchCard) {
            \Illuminate\Support\Facades\Log::warning("MatchCard not found for provider confirmation", [
                'service_request_id' => $serviceRequest->id,
                'provider_id' => $providerProfile->id,
            ]);
            return response()->json([
                'message' => 'No se encontró la tarjeta de emparejamiento para esta solicitud.',
            ], 422);
        }

        $estimatedDuration = (int) $request->input('estimated_duration_min', 60);
        $scheduledAtInput = $request->input('scheduled_at') ? \Carbon\Carbon::parse($request->input('scheduled_at')) : now();

        $offer = \App\Infrastructure\Persistence\Eloquent\OfferModel::firstOrCreate(
            [
                'service_request_id' => $serviceRequest->id,
                'provider_id' => $providerProfile->id,
            ],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'status' => \App\Domain\Offers\Enums\OfferStatus::Pending,
                'proposed_price' => $request->input('proposed_price', 15000.00),
                'currency_code' => 'ARS',
                'estimated_duration_min' => $estimatedDuration,
                'proposed_start_at' => $scheduledAtInput,
            ]
        );

        if ($request->filled('estimated_duration_min') || $request->filled('scheduled_at')) {
            $offer->update([
                'estimated_duration_min' => $estimatedDuration,
                'proposed_start_at' => $scheduledAtInput,
            ]);
        }

        try {
            $result = $this->acceptOfferAction->execute($offer, $matchCard);

            if ($request->filled('scheduled_at')) {
                $result['work']->update(['scheduled_at' => $scheduledAtInput]);
            }

            return response()->json([
                'message' => 'Trabajo confirmado.',
                'data' => [
                    'id' => $serviceRequest->uuid,
                    'work_id' => $result['work']->uuid,
                    'conversation_id' => $result['conversation']->uuid,
                    'status' => 'confirmed',
                    'estimated_duration_min' => $result['work']->estimated_duration_min,
                    'scheduled_at' => $result['work']->scheduled_at?->toISOString(),
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }

    public function declineWorkRequest(string $id): JsonResponse
    {
        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $id);
        if (!$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('respond', $serviceRequest);

        $serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled);

        return response()->json([
            'message' => 'Solicitud declinada.',
            'data' => ['id' => $id, 'status' => 'declined'],
        ]);
    }
}
