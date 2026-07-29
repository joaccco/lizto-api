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

        $requests = ServiceRequestModel::where('client_id', $user->id)
            ->with([
                'category',
                'matchSession.cards' => function ($q) {
                    $q->where('card_status', 'accepted')->with('provider.user');
                },
            ])
            ->orderByDesc('created_at')
            ->paginate(10);

        $items = collect($requests->items())->map(function ($sr) {
            $acceptedCard = $sr->matchSession?->cards->first();
            $provider = $acceptedCard?->provider;
            $providerUser = $provider?->user;
            $snapshot = $acceptedCard?->snapshot ?? [];

            return [
                'uuid' => $sr->uuid,
                'raw_prompt' => Str::limit($sr->raw_prompt, 60),
                'full_prompt' => $sr->raw_prompt,
                'status' => $sr->status instanceof \BackedEnum ? $sr->status->value : $sr->status,
                'urgency' => $sr->urgency instanceof \BackedEnum ? $sr->urgency->value : $sr->urgency,
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

    public function store(CreateServiceRequestRequest $request): JsonResponse
    {
        $user = $request->user();

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

        $serviceRequest->update([
            'structured_data' => $structuredData,
            'status' => 'pending_matching',
        ]);

        return response()->json([
            'data' => [
                'request_id' => $serviceRequest->uuid,
                'status' => 'pending_matching',
                'structured_data' => $structuredData,
            ],
            'message' => 'Encuesta completada.',
        ], 200);
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
