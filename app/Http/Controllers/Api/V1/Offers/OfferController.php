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
        protected CreateWorkAction $createWorkAction
    ) {}

    public function store(Request $request, string $serviceRequestId): JsonResponse
    {
        $serviceRequest = ServiceRequestModel::where('uuid', $serviceRequestId)->firstOrFail();
        $user = $request->user();

        $provider = ProviderProfileModel::where('user_id', $user->id)->first()
            ?? ProviderProfileModel::first();

        $validated = $request->validate([
            'proposed_price' => 'nullable|numeric|min:0',
            'currency_code' => 'nullable|string|size:3',
            'proposed_start_at' => 'nullable|date',
            'estimated_duration_min' => 'nullable|integer|min:1',
        ]);

        $offer = OfferModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $provider->id,
            'status' => OfferStatus::Pending,
            'proposed_price' => $validated['proposed_price'] ?? null,
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
                'proposed_price' => $offer->proposed_price,
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
        return DB::transaction(function () use ($id) {
            $offer = OfferModel::where('uuid', $id)->firstOrFail();
            $serviceRequest = ServiceRequestModel::where('id', $offer->service_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard against multiple active accepted offers
            $existingAccepted = OfferModel::where('service_request_id', $serviceRequest->id)
                ->where('status', OfferStatus::Accepted->value)
                ->exists();

            if ($existingAccepted) {
                return response()->json(['message' => 'Esta solicitud ya tiene una oferta aceptada activa.'], 409);
            }

            $offer->update(['status' => OfferStatus::Accepted]);

            // Create Work via Action
            $work = $this->createWorkAction->execute($offer);

            // Create Conversation automatically
            $conversation = ConversationModel::create([
                'uuid' => (string) Str::uuid(),
                'work_id' => $work->id,
                'service_request_id' => $serviceRequest->id,
                'client_id' => $serviceRequest->client_id,
                'provider_id' => $offer->provider_id,
                'offer_id' => $offer->id,
            ]);

            event(new OfferAccepted($offer));

            return response()->json([
                'data' => [
                    'offer_id' => $offer->uuid,
                    'status' => $offer->status->value,
                    'work_id' => $work->uuid,
                    'conversation_id' => $conversation->uuid,
                ],
                'message' => 'Oferta aceptada. Trabajo y conversación creados exitosamente.',
            ]);
        });
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
        $this->contactGuard->guardPreAgreement($answerText);

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
