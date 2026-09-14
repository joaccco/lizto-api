<?php

namespace App\Http\Controllers\Api\V1\Offers;

use App\Application\Works\Actions\CreateWorkAction;
use App\Domain\Clarification\Enums\AnswerSource;
use App\Domain\Offers\Enums\OfferStatus;
use App\Domain\Offers\Events\OfferAccepted;
use App\Domain\Offers\Events\OfferCountered;
use App\Domain\Offers\Events\OfferCreated;
use App\Domain\Offers\Events\OfferRejected;
use App\Domain\Offers\Events\QuestionAnswered;
use App\Domain\Offers\Events\QuestionAsked;
use App\Domain\Offers\Exceptions\MaxCounterOfferRoundsExceededException;
use App\Domain\Offers\Services\ContactInfoGuard;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\OfferModel;
use App\Infrastructure\Persistence\Eloquent\OfferQuestionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\RequestAnswerModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferController extends Controller
{
    public function __construct(
        protected ContactInfoGuard $contactGuard,
        protected CreateWorkAction $createWorkAction,
        protected \App\Application\Offers\Actions\AcceptOfferAction $acceptOfferAction
    ) {}

    public function store(Request $request, string $serviceRequestId): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $serviceRequestId)
            ->with(['serviceType', 'category'])
            ->firstOrFail();

        $user = $request->user();

        $provider = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$provider) {
            return response()->json(['message' => 'Solo los usuarios con perfil de profesional pueden realizar ofertas.'], 403);
        }

        // SEC-04: Verificación de identidad usa ProviderProfileModel::isIdentityVerified() como única autoridad.
        // Las marcas heredadas (status, is_verified) no son suficientes.
        if (!$provider->isIdentityVerified()) {
            return response()->json(['message' => 'Solo los profesionales con identidad verificada pueden realizar ofertas.'], 403);
        }

        $allowedKeys = [
            'pricing_mode',
            'proposed_price',
            'price_min',
            'price_max',
            'currency_code',
            'proposed_start_at',
            'estimated_duration_min',
            'notes',
        ];

        $unknownKeys = array_diff(array_keys($request->all()), $allowedKeys);
        if (!empty($unknownKeys)) {
            return response()->json([
                'message' => 'Campos no permitidos en el cuerpo del pedido.',
                'errors' => ['request' => ['Campos desconocidos detectados: ' . implode(', ', $unknownKeys)]],
            ], 422);
        }

        // Determine default pricing_mode from ServiceType
        $defaultPricingMode = 'quoted';
        if ($serviceRequest->serviceType && $serviceRequest->serviceType->requires_onsite_diagnosis) {
            $defaultPricingMode = 'requires_visit';
        }

        $validated = $request->validate([
            'pricing_mode' => 'nullable|in:quoted,requires_visit',
            'proposed_price' => 'nullable|numeric|min:0',
            'price_min' => 'nullable|integer|min:0',
            'price_max' => 'nullable|integer|min:0',
            'currency_code' => 'nullable|string|size:3',
            'proposed_start_at' => 'nullable|date',
            'estimated_duration_min' => 'nullable|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ]);

        $pricingMode = $validated['pricing_mode'] ?? $defaultPricingMode;

        if ($pricingMode === 'quoted') {
            if (!isset($validated['proposed_price']) || $validated['proposed_price'] === null) {
                return response()->json(['message' => 'El precio propuesto es obligatorio para ofertas cotizadas.'], 422);
            }
            if (isset($validated['price_min']) || isset($validated['price_max'])) {
                return response()->json(['message' => 'El rango de precios (price_min/price_max) no es permitido en modalidad quoted.'], 422);
            }
        } elseif ($pricingMode === 'requires_visit') {
            if (!isset($validated['price_min']) || !isset($validated['price_max'])) {
                return response()->json(['message' => 'El rango estimado (price_min y price_max) es obligatorio para ofertas que requieren visita.'], 422);
            }
            if (isset($validated['proposed_price']) && $validated['proposed_price'] !== null) {
                return response()->json(['message' => 'El precio exacto (proposed_price) no es permitido en modalidad requires_visit.'], 422);
            }
            if ((int) $validated['price_min'] > (int) $validated['price_max']) {
                return response()->json(['message' => 'El precio mínimo no puede superar al precio máximo.'], 422);
            }
        }

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $provider->id,
            'status' => OfferStatus::Pending,
            'pricing_mode' => $pricingMode,
            'proposed_price' => $pricingMode === 'quoted' ? ($validated['proposed_price'] ?? null) : null,
            'price_min' => $pricingMode === 'requires_visit' ? ($validated['price_min'] ?? null) : null,
            'price_max' => $pricingMode === 'requires_visit' ? ($validated['price_max'] ?? null) : null,
            'notes' => $validated['notes'] ?? null,
            'currency_code' => $validated['currency_code'] ?? 'ARS',
            'proposed_start_at' => $validated['proposed_start_at'] ?? null,
            'estimated_duration_min' => $validated['estimated_duration_min'] ?? null,
            'round_number' => 1,
        ]);

        event(new OfferCreated($offer));

        return response()->json([
            'data' => [
                'id' => $offer->uuid,
                'status' => $offer->status->value,
                'pricing_mode' => $offer->pricing_mode,
                'proposed_price' => $offer->proposed_price,
                'price_min' => $offer->price_min,
                'price_max' => $offer->price_max,
                'notes' => $offer->notes,
                'currency_code' => $offer->currency_code,
                'round_number' => $offer->round_number,
            ],
            'message' => 'Oferta creada exitosamente.',
        ], 201);
    }

    public function counter(Request $request, string $id): JsonResponse
    {
        $offer = OfferModel::where('uuid', $id)->firstOrFail();
        $maxRounds = config('offers.max_offer_rounds', 3);

        if ($offer->round_number >= $maxRounds) {
            throw new MaxCounterOfferRoundsExceededException();
        }

        $validated = $request->validate([
            'proposed_price' => 'nullable|numeric|min:0',
            'proposed_start_at' => 'nullable|date',
            'estimated_duration_min' => 'nullable|integer|min:1',
        ]);

        $offer->update([
            'status' => OfferStatus::Countered,
            'proposed_price' => $validated['proposed_price'] ?? $offer->proposed_price,
            'proposed_start_at' => $validated['proposed_start_at'] ?? $offer->proposed_start_at,
            'estimated_duration_min' => $validated['estimated_duration_min'] ?? $offer->estimated_duration_min,
            'round_number' => $offer->round_number + 1,
        ]);

        event(new OfferCountered($offer));

        return response()->json([
            'data' => [
                'id' => $offer->uuid,
                'status' => $offer->status->value,
                'proposed_price' => $offer->proposed_price,
                'round_number' => $offer->round_number,
            ],
            'message' => 'Contraoferta enviada.',
        ]);
    }

    public function accept(string $id): JsonResponse
    {
        $offer = OfferModel::where('uuid', $id)->firstOrFail();

        if (!$offer->provider || !$offer->provider->isIdentityVerified()) {
            return response()->json(['message' => 'El profesional que realizó esta oferta ya no cuenta con identidad verificada.'], 403);
        }

        try {
            $result = $this->acceptOfferAction->execute($offer);
            return response()->json([
                'data' => [
                    'offer_id' => $result['offer']->uuid,
                    'status' => $result['offer']->status->value,
                    'work_id' => $result['work']->uuid,
                    'conversation_id' => $result['conversation']->uuid,
                ],
                'message' => 'Oferta aceptada. Trabajo y conversación creados exitosamente.',
            ]);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        }
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $offer = OfferModel::where('uuid', $id)->firstOrFail();
        $validated = $request->validate([
            'reason' => 'nullable|string|in:price_too_high,unavailable,other',
        ]);

        $reason = $validated['reason'] ?? 'other';
        $offer->update([
            'status' => OfferStatus::Rejected,
            'rejection_reason' => $reason,
        ]);

        event(new OfferRejected($offer, $reason));

        return response()->json([
            'data' => [
                'id' => $offer->uuid,
                'status' => $offer->status->value,
                'rejection_reason' => $offer->rejection_reason,
            ],
            'message' => 'Oferta rechazada.',
        ]);
    }

    public function askQuestion(Request $request, string $id): JsonResponse
    {
        $offer = OfferModel::where('uuid', $id)->firstOrFail();
        $validated = $request->validate([
            'question_key' => 'required|string',
            'question_text' => 'required|string',
        ]);

        $q = OfferQuestionModel::create([
            'offer_id' => $offer->id,
            'question_key' => $validated['question_key'],
            'question_text' => $validated['question_text'],
        ]);

        event(new QuestionAsked($q));

        return response()->json([
            'data' => [
                'id' => $q->id,
                'question_key' => $q->question_key,
                'question_text' => $q->question_text,
            ],
            'message' => 'Pregunta enviada.',
        ], 201);
    }

    public function answerQuestion(Request $request, int $questionId): JsonResponse
    {
        $offerQuestion = OfferQuestionModel::findOrFail($questionId);
        $validated = $request->validate([
            'answer' => 'required|string',
        ]);

        $answerText = $validated['answer'];

        // PRE-AGREEMENT ANTI-CONTACT GUARD
        try {
            $this->contactGuard->guardPreAgreement($answerText);
        } catch (\App\Domain\Offers\Exceptions\ContactInfoDetectedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $offerQuestion->update([
            'answer' => $answerText,
            'answered_at' => now(),
        ]);

        event(new QuestionAnswered($offerQuestion));

        // If question_key matches a structured engine question, record RequestAnswer with source = professional
        $serviceRequest = $offerQuestion->offer->serviceRequest;
        if ($serviceRequest && $serviceRequest->questionnaire_version_id) {
            $engineQuestion = QuestionModel::where('questionnaire_version_id', $serviceRequest->questionnaire_version_id)
                ->where('question_key', $offerQuestion->question_key)
                ->first();

            if ($engineQuestion) {
                RequestAnswerModel::updateOrCreate(
                    [
                        'service_request_id' => $serviceRequest->id,
                        'question_id' => $engineQuestion->id,
                    ],
                    [
                        'question_key' => $engineQuestion->question_key,
                        'answer_value' => $answerText,
                        'source' => AnswerSource::Professional,
                        'confirmed_by_user' => true,
                    ]
                );
            }
        }

        return response()->json([
            'data' => [
                'id' => $offerQuestion->id,
                'answer' => $offerQuestion->answer,
                'answered_at' => $offerQuestion->answered_at?->toISOString(),
            ],
            'message' => 'Pregunta respondida correctamente.',
        ]);
    }
}
