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

        if ($user && app(\App\Domain\Trust\Services\BanService::class)->isBanned($user)) {
            return response()->json([
                'message' => 'Tu cuenta se encuentra suspendida.',
                'errors' => ['account' => ['Cuenta suspendida.']],
            ], 403);
        }

        if ($user && app(\App\Domain\Trust\Services\BanService::class)->hasActiveRestriction($user, 'cannot_accept_requests')) {
            return response()->json([
                'message' => 'Tu cuenta tiene una restricción activa para recibir solicitudes.',
                'errors' => ['account' => ['Restricción activa.']],
            ], 403);
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            return response()->json(['data' => []]);
        }

        $categoryIds = $providerProfile->categories()->pluck('category_id')->filter()->toArray();

        // Instrumento de diagnostico para pruebas manuales. Lista blanca explicita:
        // cualquier entorno que no sea local o testing NO lo recibe. Falla cerrado.
        $debugEnabled = app()->environment(['local', 'testing']);

        $requests = ServiceRequestModel::query()
            ->whereNotIn('status', [
                \App\Domain\ServiceRequests\Enums\RequestStatus::PendingSurvey,
                \App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled,
                \App\Domain\ServiceRequests\Enums\RequestStatus::Completed,
            ])
            ->when(!empty($categoryIds), function ($q) use ($categoryIds) {
                $q->whereIn('category_id', $categoryIds);
            })
            ->where(function ($query) use ($providerProfile, $categoryIds) {
                $query->whereHas('matchSession.cards', function ($q) use ($providerProfile) {
                    $q->where('provider_id', $providerProfile->id)
                      ->where('card_status', '!=', \App\Domain\Matching\Enums\CardStatus::Rejected);
                })
                ->orWhereHas('works', function ($q) use ($providerProfile) {
                    $q->where('provider_id', $providerProfile->id);
                })
                ->orWhere(function ($q) use ($categoryIds) {
                    if (!empty($categoryIds)) {
                        $q->whereIn('category_id', $categoryIds)
                          ->whereDoesntHave('matchSession.cards')
                          ->whereDoesntHave('works');
                    }
                });
            })
            ->with(['category', 'client', 'works' => function ($q) use ($providerProfile) {
                $q->where('provider_id', $providerProfile->id)->with('conversation');
            }])
            ->when($debugEnabled, function ($q) use ($providerProfile) {
                // Solo en desarrollo: se necesita la tarjeta de matching de ESTE
                // profesional para poder explicar por que aparece cada pedido.
                $q->with(['matchSession.cards' => function ($c) use ($providerProfile) {
                    $c->where('provider_id', $providerProfile->id);
                }]);
            })
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $items = $requests->map(function ($sr) use ($user, $providerProfile, $debugEnabled) {
            $work = $sr->works->first();
            $effectiveStatus = $work ? $work->status->value : ($sr->status instanceof \BackedEnum ? $sr->status->value : $sr->status);
            $locationData = \App\Domain\Location\Services\LocationPresenter::present($sr, $user);

            return array_merge([
                'id' => $sr->uuid,
                'work_id' => $work?->uuid,
                'conversation_id' => $work?->conversation?->uuid,
                'category' => $sr->category ? $sr->category->name : null,
                'category_slug' => $sr->category ? $sr->category->slug : null,
                'raw_prompt' => $sr->raw_prompt,
                'client_name' => $sr->client ? explode(' ', $sr->client->name)[0] : 'Cliente',
                'urgency' => $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : $sr->urgency,
                'status' => $effectiveStatus,
                'estimated_duration_min' => $work?->estimated_duration_min,
                'created_at' => $sr->created_at?->toISOString(),
                'schedule' => static::formatScheduleBlock($sr),
            ], $locationData, static::debugAttribution($sr, $providerProfile, $work, $debugEnabled));
        });

        return response()->json(['data' => $items]);
    }

    /**
     * Por que este pedido aparece en la bandeja de ESTE profesional.
     *
     * La consulta de `workRequests` tiene tres ramas unidas por OR, y sólo dos
     * significan que el pedido es suyo. La tercera son pedidos abiertos de su
     * rubro que todavia no tienen a nadie invitado: aparecen en la bandeja de
     * TODOS los profesionales de ese rubro. Sin esto no hay forma de
     * distinguirlos mirando la pantalla.
     *
     * Solo se emite en local y testing.
     */
    protected static function debugAttribution($sr, $providerProfile, $work, bool $enabled): array
    {
        if (!$enabled) {
            return [];
        }

        $card = $sr->relationLoaded('matchSession') && $sr->matchSession
            ? $sr->matchSession->cards->first()
            : null;

        if ($work) {
            $motivo = 'trabajo_asignado';
            $explicacion = 'Tenés un trabajo asignado en este pedido.';
        } elseif ($card) {
            $motivo = 'tarjeta_de_matching';
            $explicacion = 'Fuiste invitado a este pedido.';
        } else {
            $motivo = 'rubro_abierto';
            $explicacion = 'NO es tuyo: aparece sólo porque es de tu rubro y todavía no hay nadie invitado.';
        }

        return [
            '_debug' => [
                'motivo' => $motivo,
                'explicacion' => $explicacion,
                'es_mio' => $motivo !== 'rubro_abierto',
                'provider_id' => $providerProfile->id,
                'provider_nombre' => $providerProfile->user?->name,
                'card_status' => $card?->card_status instanceof \BackedEnum
                    ? $card->card_status->value
                    : $card?->card_status,
                'request_uuid' => $sr->uuid,
            ],
        ];
    }

    public function agenda(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            return response()->json(['data' => [], 'pending_schedule' => []]);
        }

        $works = \App\Infrastructure\Persistence\Eloquent\WorkModel::query()
            ->where('provider_id', $providerProfile->id)
            ->with(['serviceRequest.category', 'client'])
            ->orderBy('scheduled_at')
            ->get();

        $scheduledWorks = $works->whereNotNull('scheduled_at');
        $unscheduledWorks = $works->whereNull('scheduled_at');

        $calendarEvents = $scheduledWorks->map(function ($work) {
            $scheduledAt = $work->scheduled_at;

            return [
                'id' => $work->uuid,
                'work_id' => $work->uuid,
                'client_name' => $work->client ? $work->client->name : 'Cliente',
                'client_email' => $work->client ? $work->client->email : '',
                'job_type' => $work->serviceRequest?->raw_prompt ?? 'Servicio agendado',
                'category' => $work->serviceRequest?->category?->name ?? null,
                'address' => $work->work_address ?? 'Domicilio del cliente',
                'status' => $work->status->value,
                'scheduled_at' => $scheduledAt?->toISOString(),
                'day' => (int) $scheduledAt->format('j'),
                'month' => (int) $scheduledAt->format('n'),
                'year' => (int) $scheduledAt->format('Y'),
                'time' => $scheduledAt->format('H:i'),
                'estimated_duration_min' => $work->estimated_duration_min,
                'agreed_price' => $work->agreed_price,
                'schedule' => static::formatScheduleBlock($work->serviceRequest),
            ];
        })->values();

        $pendingScheduleEvents = $unscheduledWorks->map(function ($work) {
            return [
                'id' => $work->uuid,
                'work_id' => $work->uuid,
                'client_name' => $work->client ? $work->client->name : 'Cliente',
                'client_email' => $work->client ? $work->client->email : '',
                'job_type' => $work->serviceRequest?->raw_prompt ?? 'Servicio pendiente de coordinar',
                'category' => $work->serviceRequest?->category?->name ?? null,
                'address' => $work->work_address ?? 'Domicilio del cliente',
                'status' => $work->status->value,
                'scheduled_at' => null,
                'day' => null,
                'month' => null,
                'year' => null,
                'time' => 'A coordinar',
                'estimated_duration_min' => $work->estimated_duration_min,
                'agreed_price' => $work->agreed_price,
                'schedule' => static::formatScheduleBlock($work->serviceRequest),
            ];
        })->values();

        return response()->json([
            'data' => $calendarEvents,
            'pending_schedule' => $pendingScheduleEvents,
        ]);
    }

    public static function formatScheduleBlock(?ServiceRequestModel $sr): array
    {
        if (!$sr) {
            return [
                'scheduled_date' => null,
                'window_start' => null,
                'window_end' => null,
                'label' => 'A coordinar',
            ];
        }

        $urgencyVal = $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : (string) $sr->urgency;
        $scheduledDateStr = $sr->scheduled_date
            ? $sr->scheduled_date->format('Y-m-d')
            : ($sr->preferred_datetime ? $sr->preferred_datetime->format('Y-m-d') : null);

        $windowStart = $sr->window_start;
        $windowEnd = $sr->window_end;

        if ($urgencyVal === 'immediate') {
            return [
                'scheduled_date' => null,
                'window_start' => null,
                'window_end' => null,
                'label' => 'Atención inmediata',
            ];
        }

        if ($urgencyVal === 'today') {
            $rangeStr = ($windowStart && $windowEnd) ? " de {$windowStart} a {$windowEnd} hs" : "";
            return [
                'scheduled_date' => $scheduledDateStr,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'label' => "Hoy{$rangeStr}",
            ];
        }

        if ($urgencyVal === 'scheduled') {
            if ($sr->preferred_datetime || $sr->scheduled_date) {
                $dt = $sr->preferred_datetime ?? $sr->scheduled_date;
                $days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                $months = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

                $dayName = $days[(int) $dt->format('w')];
                $dayNum = (int) $dt->format('j');
                $monthName = $months[(int) $dt->format('n')];

                $rangeStr = ($windowStart && $windowEnd) ? " de {$windowStart} a {$windowEnd} hs" : ($dt->format('H:i') !== '00:00' ? " a las {$dt->format('H:i')} hs" : "");

                return [
                    'scheduled_date' => $scheduledDateStr,
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                    'label' => "{$dayName} {$dayNum} de {$monthName}{$rangeStr}",
                ];
            }

            return [
                'scheduled_date' => null,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'label' => 'A coordinar',
            ];
        }

        return [
            'scheduled_date' => $scheduledDateStr,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'label' => 'A coordinar',
        ];
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

        if ($user && app(\App\Domain\Trust\Services\BanService::class)->isBanned($user)) {
            return response()->json([
                'message' => 'Tu cuenta se encuentra suspendida.',
            ], 403);
        }

        if ($user && app(\App\Domain\Trust\Services\BanService::class)->hasActiveRestriction($user, 'cannot_accept_requests')) {
            return response()->json([
                'message' => 'Tu cuenta tiene una restricción activa para aceptar solicitudes.',
            ], 403);
        }

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$providerProfile) {
            return response()->json(['message' => 'Perfil de proveedor no encontrado.'], 404);
        }

        if (!$providerProfile->isIdentityVerified()) {
            return response()->json([
                'message' => 'Solo los profesionales con identidad verificada pueden confirmar solicitudes.',
            ], 403);
        }

        // Si ya existe un trabajo confirmado para este pedido y profesional, retornarlo de forma idempotente
        $existingWork = \App\Infrastructure\Persistence\Eloquent\WorkModel::where('service_request_id', $serviceRequest->id)
            ->where('provider_id', $providerProfile->id)
            ->first();

        if ($existingWork) {
            $conversation = \App\Infrastructure\Persistence\Eloquent\ConversationModel::where('work_id', $existingWork->id)->first();
            return response()->json([
                'message' => 'Trabajo confirmado.',
                'data' => [
                    'id' => $serviceRequest->uuid,
                    'work_id' => $existingWork->uuid,
                    'conversation_id' => $conversation?->uuid,
                    'status' => 'confirmed',
                    'estimated_duration_min' => $existingWork->estimated_duration_min,
                    'scheduled_at' => $existingWork->scheduled_at?->toISOString(),
                ],
            ]);
        }

        // Resolver o crear MatchCard automáticamente si la solicitud no poseía tarjeta previa
        $matchCard = \App\Infrastructure\Persistence\Eloquent\MatchCardModel::whereHas('matchSession', function ($q) use ($serviceRequest) {
            $q->where('service_request_id', $serviceRequest->id);
        })
        ->where('provider_id', $providerProfile->id)
        ->first();

        if (!$matchCard) {
            $matchSession = \App\Infrastructure\Persistence\Eloquent\MatchSessionModel::firstOrCreate(
                ['service_request_id' => $serviceRequest->id],
                ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active']
            );

            $matchCard = \App\Infrastructure\Persistence\Eloquent\MatchCardModel::firstOrCreate(
                [
                    'match_session_id' => $matchSession->id,
                    'provider_id' => $providerProfile->id,
                ],
                [
                    'rank_position' => 1,
                    'score_total' => 1.0,
                    'card_status' => 'accepted',
                ]
            );
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
            $work = \App\Infrastructure\Persistence\Eloquent\WorkModel::where('service_request_id', $serviceRequest->id)
                ->where('provider_id', $providerProfile->id)
                ->first();

            if ($work) {
                $conversation = \App\Infrastructure\Persistence\Eloquent\ConversationModel::where('work_id', $work->id)->first();
                return response()->json([
                    'message' => 'Trabajo confirmado.',
                    'data' => [
                        'id' => $serviceRequest->uuid,
                        'work_id' => $work->uuid,
                        'conversation_id' => $conversation?->uuid,
                        'status' => 'confirmed',
                        'estimated_duration_min' => $work->estimated_duration_min,
                        'scheduled_at' => $work->scheduled_at?->toISOString(),
                    ],
                ]);
            }

            return response()->json(['message' => $e->getMessage()], ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 422);
        }
    }

    public function declineWorkRequest(string $id, Request $request): JsonResponse
    {
        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $id);
        if (!$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('decline', $serviceRequest);

        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if ($providerProfile) {
            $card = \App\Infrastructure\Persistence\Eloquent\MatchCardModel::whereHas('matchSession', function ($q) use ($serviceRequest) {
                $q->where('service_request_id', $serviceRequest->id);
            })
            ->where('provider_id', $providerProfile->id)
            ->first();

            if ($card) {
                $card->update([
                    'card_status' => \App\Domain\Matching\Enums\CardStatus::Rejected,
                    'decided_at' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Solicitud declinada.',
            'data' => ['id' => $id, 'status' => 'declined'],
        ]);
    }
}
