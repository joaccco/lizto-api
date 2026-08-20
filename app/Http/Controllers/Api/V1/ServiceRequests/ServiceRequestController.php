<?php

namespace App\Http\Controllers\Api\V1\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequests\CreateServiceRequestRequest;
use App\Http\Requests\ServiceRequests\SubmitSurveyRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\SurveyQuestionModel;
use App\Infrastructure\Persistence\Eloquent\SurveyResponseModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ServiceRequestModel::where('client_id', $user->id)
            ->with([
                'category',
                'works.provider.user',
                'works.conversation',
                'matchSession.cards' => function ($q) {
                    $q->where('card_status', 'accepted')->with('provider.user');
                },
            ]);

        if ($request->query('status') === 'active') {
            $query->where(function ($q) {
                $q->whereIn('status', [
                    'pending_survey',
                    'pending_matching',
                    'matching_active',
                    'provider_selected',
                    'pending_confirmation',
                    'confirmed',
                    'in_progress',
                    'pending_completion',
                    'active',
                ])->orWhere(function ($q2) {
                    $q2->where('status', 'cancelled')
                       ->where('updated_at', '>=', now()->subHours(2));
                });
            });
        }

        $limit = (int) $request->query('limit', 10);
        $requests = $query->orderByDesc('created_at')->paginate($limit);

        $items = collect($requests->items())->map(function ($sr) {
            $work = $sr->works->first();
            $acceptedCard = $sr->matchSession?->cards->first();
            $provider = $work?->provider ?? $acceptedCard?->provider;
            $providerUser = $provider?->user;
            $snapshot = $acceptedCard?->snapshot ?? [];
            $statusVal = $work ? $work->status->value : ($sr->status instanceof \BackedEnum ? $sr->status->value : $sr->status);

            return [
                'uuid' => $sr->uuid,
                'work_id' => $work?->uuid,
                'conversation_id' => $work?->conversation?->uuid,
                'raw_prompt' => Str::limit($sr->raw_prompt, 60),
                'full_prompt' => $sr->raw_prompt,
                'status' => $statusVal,
                'urgency' => $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : $sr->urgency,
                'estimated_duration_min' => $work?->estimated_duration_min ?? 60,
                'category' => $sr->category ? [
                    'name' => $sr->category->name,
                    'slug' => $sr->category->slug,
                    'icon' => $sr->category->icon,
                ] : null,
                'accepted_provider' => $provider ? [
                    'name' => $providerUser?->name ?? 'Proveedor',
                    'avg_rating' => (float) ($snapshot['avg_rating'] ?? $provider->avg_rating ?? 5.0),
                    'avatar_url' => $providerUser?->avatar_url,
                ] : null,
                'created_at' => $sr->created_at?->toISOString(),
            ];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ],
        ]);
    }

    public function show(string $uuid, Request $request): JsonResponse
    {
        $sr = ServiceRequestModel::where('uuid', $uuid)
            ->with([
                'category',
                'works.provider.user',
                'works.conversation',
                'matchSession.cards' => function ($q) {
                    $q->where('card_status', 'accepted')->with('provider.user');
                },
            ])
            ->first();

        if (!$sr) {
            return response()->json(['message' => 'Solicitud no encontrada.'], 404);
        }

        $work = $sr->works->first();
        $acceptedCard = $sr->matchSession?->cards->first();
        $provider = $work?->provider ?? $acceptedCard?->provider;
        $providerUser = $provider?->user;
        $statusVal = $work ? $work->status->value : ($sr->status instanceof \BackedEnum ? $sr->status->value : $sr->status);

        $conversationId = $work?->conversation?->uuid;
        if (!$conversationId && $work) {
            $conv = \App\Infrastructure\Persistence\Eloquent\ConversationModel::firstOrCreate(
                ['work_id' => $work->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'service_request_id' => $sr->id,
                    'client_id' => $sr->client_id,
                    'provider_id' => $work->provider_id,
                ]
            );
            $conversationId = $conv->uuid;
        }

        return response()->json([
            'data' => [
                'id' => $sr->uuid,
                'uuid' => $sr->uuid,
                'raw_prompt' => $sr->raw_prompt,
                'status' => $statusVal,
                'urgency' => $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : $sr->urgency,
                'address' => $sr->location_address ?? 'Centro',
                'created_at' => $sr->created_at?->toISOString(),
                'conversation_id' => $conversationId,
                'category' => $sr->category ? [
                    'name' => $sr->category->name,
                    'slug' => $sr->category->slug,
                ] : null,
                'accepted_provider' => $provider ? [
                    'id' => $provider->uuid,
                    'uuid' => $provider->uuid,
                    'name' => $providerUser?->name ?? 'Proveedor',
                    'avatar_url' => $providerUser?->avatar_url,
                    'bio' => $provider->bio ?? 'Profesional certificado con amplia experiencia.',
                    'avg_rating' => (float) ($provider->avg_rating ?? 5.0),
                    'total_reviews' => (int) ($provider->total_reviews ?? 0),
                    'total_jobs_completed' => (int) ($provider->total_jobs_completed ?? 0),
                    'years_experience' => (int) ($provider->years_experience ?? 5),
                    'is_verified' => (bool) ($provider->is_verified ?? true),
                    'specialties' => $provider->specialties ?? ['Servicios integrales'],
                    'price_from' => $provider->price_from ?? 8000,
                    'price_to' => $provider->price_to ?? 35000,
                    'response_time' => $provider->avg_response_minutes ? "~{$provider->avg_response_minutes} min" : "~10 min",
                ] : null,
            ],
        ]);
    }

    public function store(CreateServiceRequestRequest $request): JsonResponse
    {
        $user = $request->user();
        $prompt = $request->input('prompt');

        $existing = ServiceRequestModel::where('client_id', $user->id)
            ->where('raw_prompt', $prompt)
            ->whereIn('status', ['pending_survey', 'pending_matching', 'matching_active'])
            ->where('created_at', '>', now()->subMinutes(5))
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            if ($existing->category_id) {
                $existing->load('category.surveyQuestions');
            }
            return response()->json([
                'data' => (new ServiceRequestResource($existing))->resolve(),
                'message' => 'Solicitud recuperada.',
            ], 200);
        }

        $categoryId = $request->input('category_id');
        $categorySlug = $request->input('category_slug');

        $category = null;
        if ($categoryId) {
            $category = CategoryModel::find($categoryId);
        }
        if (!$category && $categorySlug) {
            $category = CategoryModel::where('slug', $categorySlug)->first();
        }

        $location = $request->input('location', []);

        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $user->id,
            'category_id' => $category?->id,
            'raw_prompt' => $request->input('prompt'),
            'parsed_intent' => $request->input('parsed_intent', []),
            'structured_data' => [],
            'location_lat' => $location['lat'] ?? null,
            'location_lng' => $location['lng'] ?? null,
            'location_address' => $location['address'] ?? null,
            'is_remote' => $request->boolean('is_remote', false),
            'urgency' => $request->input('urgency', 'immediate'),
            'status' => 'pending_survey',
            'expires_at' => now()->addHours(24),
        ]);

        if ($category) {
            $category->load('surveyQuestions');
            $serviceRequest->setRelation('category', $category);
        }

        return response()->json([
            'data' => (new ServiceRequestResource($serviceRequest))->resolve(),
            'message' => 'Solicitud creada correctamente.',
        ], 201);
    }

    public function survey(SubmitSurveyRequest $request, string $uuid): JsonResponse
    {
        $user = $request->user();

        $serviceRequest = ServiceRequestModel::where('uuid', $uuid)
            ->where('client_id', $user->id)
            ->firstOrFail();

        $answers = $request->input('answers', []);
        $structuredData = [];

        foreach ($request->answers as $answer) {
            if (empty($answer['answer_value'])) {
                continue;
            }

            $question = null;
            if (!empty($answer['question_id'])) {
                $question = SurveyQuestionModel::find($answer['question_id']);
            }
            if (!$question && !empty($answer['question_key'])) {
                $question = SurveyQuestionModel::where('question_key', $answer['question_key'])->first();
            }

            SurveyResponseModel::create([
                'service_request_id' => $serviceRequest->id,
                'question_id'        => $question?->id,
                'question_key'       => $answer['question_key'],
                'question_text'      => $answer['question_text'],
                'answer_value'       => $answer['answer_value'],
                'is_ai_generated'    => $answer['is_ai_generated'] ?? false,
            ]);

            $structuredData[$answer['question_key']] = $answer['answer_value'];
        }

        $location = $request->input('location', []);
        $lat = $location['lat'] ?? $request->input('location_lat') ?? $serviceRequest->location_lat;
        $lng = $location['lng'] ?? $request->input('location_lng') ?? $serviceRequest->location_lng;
        $address = $location['address'] ?? $request->input('location_address') ?? $serviceRequest->location_address;

        $serviceRequest->update([
            'structured_data'  => $structuredData,
            'location_lat'     => $lat,
            'location_lng'     => $lng,
            'location_address' => $address,
        ]);
        $serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::PendingMatching);

        return response()->json([
            'data' => [
                'request_id' => $serviceRequest->uuid,
                'status' => $serviceRequest->status->value,
                'structured_data' => $structuredData,
            ],
            'message' => 'Encuesta completada.',
        ], 200);
    }

    use \App\Traits\ResolvesByUuid;

    public function cancel(string $uuid, Request $request): JsonResponse
    {
        $serviceRequest = $this->findByUuid(ServiceRequestModel::class, $uuid);

        if (!$serviceRequest) {
            return response()->json(['message' => 'Recurso no encontrado.'], 404);
        }

        \Illuminate\Support\Facades\Gate::authorize('cancel', $serviceRequest);

        $serviceRequest->transitionTo(\App\Domain\ServiceRequests\Enums\RequestStatus::Cancelled);

        return response()->json([
            'message' => 'Solicitud cancelada correctamente.',
            'data' => [
                'id' => $serviceRequest->uuid,
                'status' => 'cancelled',
            ],
        ]);
    }

    public function cleanup(Request $request): JsonResponse
    {
        $deleted = ServiceRequestModel::where('client_id', $request->user()->id)
            ->whereIn('status', ['pending_survey', 'pending_matching'])
            ->where('created_at', '<', now()->subHour())
            ->delete();

        return response()->json([
            'data'    => ['deleted' => $deleted],
            'message' => "{$deleted} solicitudes eliminadas.",
        ]);
    }
}
