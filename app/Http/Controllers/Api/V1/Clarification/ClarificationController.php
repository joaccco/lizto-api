<?php

namespace App\Http\Controllers\Api\V1\Clarification;

use App\Domain\Clarification\Enums\AnswerSource;
use App\Domain\Clarification\Events\BriefConfirmed;
use App\Domain\Clarification\Events\ClarificationCompleted;
use App\Domain\Clarification\Events\ClarificationStarted;
use App\Domain\Clarification\Events\QuestionAnswered;
use App\Domain\Clarification\Services\ClarificationAiService;
use App\Domain\Clarification\Services\DynamicQuestionEngine;
use App\Domain\Clarification\Services\ServiceBriefBuilder;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionnaireVersionModel;
use App\Infrastructure\Persistence\Eloquent\RequestAnswerModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\ServiceTypeModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ClarificationController extends Controller
{
    public function __construct(
        protected DynamicQuestionEngine $engine,
        protected ClarificationAiService $aiService,
        protected ServiceBriefBuilder $briefBuilder
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|min:3',
            'urgency' => 'nullable|string',
            'location' => 'nullable|array',
            'is_remote' => 'nullable|boolean',
        ]);

        $prompt = $validated['prompt'];
        $user = $request->user();

        // 1. AI Classification
        $classification = $this->aiService->classify($prompt);
        $category = CategoryModel::where('slug', $classification['category_slug'])->first()
            ?? CategoryModel::first();

        $serviceType = null;
        if (!empty($classification['service_type_slug'])) {
            $serviceType = ServiceTypeModel::where('slug', $classification['service_type_slug'])->first();
        }
        if (!$serviceType && $category) {
            $serviceType = ServiceTypeModel::where('category_id', $category->id)->first();
        }

        $version = $serviceType?->currentVersion;

        // 2. Create ServiceRequest
        $serviceRequest = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $user?->id,
            'category_id' => $category?->id,
            'service_type_id' => $serviceType?->id,
            'questionnaire_version_id' => $version?->id,
            'raw_prompt' => $prompt,
            'original_prompt' => $prompt,
            'parsed_intent' => [
                'category_slug' => $category?->slug,
                'service_type_slug' => $serviceType?->slug,
                'confidence' => $classification['confidence'] ?? 0.8,
            ],
            'is_remote' => $validated['is_remote'] ?? false,
            'urgency' => $validated['urgency'] ?? 'scheduled',
            'location_lat' => $validated['location']['lat'] ?? null,
            'location_lng' => $validated['location']['lng'] ?? null,
            'location_address' => $validated['location']['address'] ?? null,
            'status' => 'pending_survey',
            'expires_at' => now()->addDays(7),
        ]);

        event(new ClarificationStarted($serviceRequest));

        // 3. AI Extraction if questionnaire version exists
        if ($version) {
            $extracted = $this->aiService->extract($prompt, $version);
            foreach ($extracted as $ansData) {
                RequestAnswerModel::updateOrCreate(
                    [
                        'service_request_id' => $serviceRequest->id,
                        'question_id' => $ansData['question_id'],
                    ],
                    [
                        'question_key' => $ansData['question_key'],
                        'answer_value' => $ansData['answer_value'],
                        'source' => AnswerSource::AiExtracted,
                        'ai_confidence' => $ansData['confidence'],
                        'confirmed_by_user' => false,
                    ]
                );
            }
        }

        // 4. Update Quote Readiness & Brief
        $readiness = $this->engine->calculateQuoteReadiness($serviceRequest);
        $serviceRequest->update(['quote_readiness' => $readiness]);
        $brief = $this->briefBuilder->buildOrUpdate($serviceRequest);

        $nextQuestion = $this->engine->getNextQuestion($serviceRequest);

        return response()->json([
            'data' => [
                'id' => $serviceRequest->uuid,
                'status' => $serviceRequest->status,
                'quote_readiness' => $serviceRequest->quote_readiness->value,
                'category' => $category ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug] : null,
                'service_type' => $serviceType ? ['id' => $serviceType->id, 'name' => $serviceType->name, 'slug' => $serviceType->slug] : null,
                'next_question' => $nextQuestion ? [
                    'id' => $nextQuestion->id,
                    'question_key' => $nextQuestion->question_key,
                    'question_text' => $nextQuestion->question_text,
                    'input_type' => $nextQuestion->input_type,
                    'is_required' => $nextQuestion->is_required,
                    'options' => $nextQuestion->options->map(fn($o) => ['label' => $o->label, 'value' => $o->value]),
                ] : null,
                'brief' => [
                    'summary' => $brief->summary,
                    'attributes' => $brief->attributes,
                    'is_confirmed' => $brief->is_confirmed,
                ],
            ],
            'message' => 'Solicitud creada e iniciada aclaración.',
        ], 201);
    }

    public function classifyOverride(Request $request, string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        $validated = $request->validate([
            'category_slug' => 'required|string',
            'service_type_slug' => 'nullable|string',
        ]);

        $category = CategoryModel::where('slug', $validated['category_slug'])->firstOrFail();
        $serviceType = null;
        if (!empty($validated['service_type_slug'])) {
            $serviceType = ServiceTypeModel::where('slug', $validated['service_type_slug'])->first();
        }
        if (!$serviceType) {
            $serviceType = ServiceTypeModel::where('category_id', $category->id)->first();
        }

        $version = $serviceType?->currentVersion;

        $serviceRequest->update([
            'category_id' => $category->id,
            'service_type_id' => $serviceType?->id,
            'questionnaire_version_id' => $version?->id,
        ]);

        $readiness = $this->engine->calculateQuoteReadiness($serviceRequest);
        $serviceRequest->update(['quote_readiness' => $readiness]);
        $brief = $this->briefBuilder->buildOrUpdate($serviceRequest);

        return response()->json([
            'data' => [
                'id' => $serviceRequest->uuid,
                'category_slug' => $category->slug,
                'service_type_slug' => $serviceType?->slug,
                'quote_readiness' => $serviceRequest->quote_readiness->value,
                'brief' => [
                    'summary' => $brief->summary,
                    'attributes' => $brief->attributes,
                ],
            ],
            'message' => 'Categoría y tipo de servicio actualizados manualmente.',
        ]);
    }

    public function getNextQuestion(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        $nextQuestion = $this->engine->getNextQuestion($serviceRequest);

        return response()->json([
            'data' => [
                'next_question' => $nextQuestion ? [
                    'id' => $nextQuestion->id,
                    'question_key' => $nextQuestion->question_key,
                    'question_text' => $nextQuestion->question_text,
                    'input_type' => $nextQuestion->input_type,
                    'is_required' => $nextQuestion->is_required,
                    'options' => $nextQuestion->options->map(fn($o) => ['label' => $o->label, 'value' => $o->value]),
                ] : null,
                'quote_readiness' => $serviceRequest->quote_readiness->value,
            ],
        ]);
    }

    public function answerQuestion(Request $request, string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        $validated = $request->validate([
            'question_id' => 'required|integer',
            'answer_value' => 'required',
        ]);

        $questionKey = $request->input('question_key');
        $rawQuestionId = (int) $validated['question_id'];

        if ($rawQuestionId === 99999 || $questionKey === 'service_schedule') {
            $questionKey = 'service_schedule';
            $qObj = QuestionModel::where('question_key', 'service_schedule')->first() ?? QuestionModel::first();
            if (!$qObj) {
                $cat = CategoryModel::first() ?? CategoryModel::create(['name' => 'General', 'slug' => 'general']);
                $st = ServiceTypeModel::first() ?? ServiceTypeModel::create([
                    'category_id' => $cat->id,
                    'name' => 'Servicios Generales',
                    'slug' => 'servicios-generales',
                ]);
                $ver = QuestionnaireVersionModel::first() ?? QuestionnaireVersionModel::create([
                    'service_type_id' => $serviceRequest->service_type_id ?? $st->id,
                    'version_number' => 1,
                    'status' => 'published',
                ]);
                $qObj = QuestionModel::create([
                    'questionnaire_version_id' => $serviceRequest->questionnaire_version_id ?? $ver->id,
                    'question_key' => 'service_schedule',
                    'question_text' => '¿Cuándo necesitás resolverlo?',
                    'input_type' => 'single_select',
                    'position' => 99,
                ]);
            }
            $questionId = $qObj->id;
        } else {
            $qObj = QuestionModel::find($rawQuestionId);
            $questionKey = $qObj ? $qObj->question_key : ($questionKey ?? "q_{$rawQuestionId}");
            $questionId = $qObj ? $qObj->id : (QuestionModel::first()?->id ?? 1);
        }

        $ans = RequestAnswerModel::updateOrCreate(
            [
                'service_request_id' => $serviceRequest->id,
                'question_key' => $questionKey,
            ],
            [
                'question_id' => $questionId,
                'question_key' => $questionKey,
                'answer_value' => $validated['answer_value'],
                'source' => AnswerSource::User,
                'confirmed_by_user' => true,
            ]
        );

        if ($questionKey === 'service_schedule') {
            $ansVal = $validated['answer_value'];
            $timing = is_string($ansVal) ? $ansVal : ($ansVal['timing'] ?? $ansVal['urgency'] ?? 'scheduled');

            $urgencyEnum = match ($timing) {
                'immediate' => \App\Domain\ServiceRequests\Enums\RequestUrgency::Immediate,
                'today' => \App\Domain\ServiceRequests\Enums\RequestUrgency::Today,
                'scheduled' => \App\Domain\ServiceRequests\Enums\RequestUrgency::Scheduled,
                default => \App\Domain\ServiceRequests\Enums\RequestUrgency::Scheduled,
            };

            $scheduledDate = is_array($ansVal) && isset($ansVal['scheduled_date'])
                ? $ansVal['scheduled_date']
                : (is_array($ansVal) && isset($ansVal['date']) ? $ansVal['date'] : ($timing === 'scheduled' ? null : now()->toDateString()));

            $windowStart = is_array($ansVal) ? ($ansVal['window_start'] ?? null) : null;
            $windowEnd = is_array($ansVal) ? ($ansVal['window_end'] ?? null) : null;

            $serviceRequest->update([
                'urgency' => $urgencyEnum,
                'scheduled_date' => $scheduledDate,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
            ]);
        }

        event(new QuestionAnswered($serviceRequest, $ans));

        $readiness = $this->engine->calculateQuoteReadiness($serviceRequest);
        $serviceRequest->update(['quote_readiness' => $readiness]);
        $brief = $this->briefBuilder->buildOrUpdate($serviceRequest);

        $nextQuestion = $this->engine->getNextQuestion($serviceRequest);
        if (!$nextQuestion) {
            event(new ClarificationCompleted($serviceRequest));
        }

        return response()->json([
            'data' => [
                'answer_id' => $ans->id,
                'question_key' => $ans->question_key,
                'quote_readiness' => $serviceRequest->quote_readiness->value,
                'next_question' => $nextQuestion ? [
                    'id' => $nextQuestion->id,
                    'question_key' => $nextQuestion->question_key,
                    'question_text' => $nextQuestion->question_text,
                    'input_type' => $nextQuestion->input_type,
                    'options' => $nextQuestion->options->map(fn($o) => ['label' => $o->label, 'value' => $o->value]),
                ] : null,
                'brief' => [
                    'summary' => $brief->summary,
                    'attributes' => $brief->attributes,
                ],
            ],
            'message' => 'Respuesta guardada correctamente.',
        ]);
    }

    public function removeAnswer(string $id, int $questionId): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        RequestAnswerModel::where('service_request_id', $serviceRequest->id)
            ->where('question_id', $questionId)
            ->delete();

        $readiness = $this->engine->calculateQuoteReadiness($serviceRequest);
        $serviceRequest->update(['quote_readiness' => $readiness]);
        $brief = $this->briefBuilder->buildOrUpdate($serviceRequest);

        return response()->json([
            'data' => [
                'quote_readiness' => $serviceRequest->quote_readiness->value,
                'brief' => [
                    'summary' => $brief->summary,
                    'attributes' => $brief->attributes,
                ],
            ],
            'message' => 'Respuesta eliminada.',
        ]);
    }

    public function getBrief(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        $brief = $this->briefBuilder->buildOrUpdate($serviceRequest);

        return response()->json([
            'data' => [
                'service_request_id' => $serviceRequest->uuid,
                'summary' => $brief->summary,
                'attributes' => $brief->attributes,
                'is_confirmed' => $brief->is_confirmed,
                'confirmed_at' => $brief->confirmed_at?->toISOString(),
            ],
        ]);
    }

    public function confirmBrief(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        $brief = $this->briefBuilder->confirm($serviceRequest);

        event(new BriefConfirmed($brief));

        return response()->json([
            'data' => [
                'service_request_id' => $serviceRequest->uuid,
                'summary' => $brief->summary,
                'attributes' => $brief->attributes,
                'is_confirmed' => $brief->is_confirmed,
                'confirmed_at' => $brief->confirmed_at?->toISOString(),
            ],
            'message' => 'Brief confirmado y congelado.',
        ]);
    }

    public function patchBrief(Request $request, string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        $validated = $request->validate([
            'attributes' => 'required|array',
        ]);

        $brief = $this->briefBuilder->buildOrUpdate($serviceRequest);
        if ($brief->is_confirmed) {
            return response()->json(['message' => 'El brief ya está congelado y no se puede modificar.'], 422);
        }

        $brief->update([
            'attributes' => array_merge($brief->attributes ?? [], $validated['attributes']),
        ]);

        return response()->json([
            'data' => [
                'summary' => $brief->summary,
                'attributes' => $brief->attributes,
                'is_confirmed' => $brief->is_confirmed,
            ],
            'message' => 'Brief actualizado.',
        ]);
    }

    public function storeAttachment(Request $request, string $id): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->firstOrFail();
        return response()->json([
            'data' => [
                'service_request_id' => $serviceRequest->uuid,
                'attachment_id' => (string) Str::uuid(),
            ],
            'message' => 'Adjunto registrado opcionalmente.',
        ]);
    }
}
