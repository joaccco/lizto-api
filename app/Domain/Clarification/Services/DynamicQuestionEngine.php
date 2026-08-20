<?php

namespace App\Domain\Clarification\Services;

use App\Domain\Clarification\Enums\QuoteReadiness;
use App\Infrastructure\Persistence\Eloquent\QuestionConditionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\QuestionnaireVersionModel;
use App\Infrastructure\Persistence\Eloquent\RequestAnswerModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Support\Collection;

class DynamicQuestionEngine
{
    public function getNextQuestion(ServiceRequestModel $request): ?QuestionModel
    {
        $version = $this->resolveVersion($request);
        if (!$version) {
            return null;
        }

        $answersMap = $this->getAnswersMap($request);
        $questions = $version->questions()->with(['options', 'conditions'])->orderBy('position')->get();

        foreach ($questions as $question) {
            if ($this->isAnswered($question, $answersMap)) {
                continue;
            }

            if ($this->evaluateConditions($question, $answersMap)) {
                return $question;
            }
        }

        // If category questions are completed, but timing/schedule hasn't been set, present service_schedule question
        if (!isset($answersMap['service_schedule']) && !$request->scheduled_date && $request->urgency === \App\Domain\ServiceRequests\Enums\RequestUrgency::Scheduled) {
            $timingQ = new QuestionModel();
            $timingQ->id = 99999;
            $timingQ->question_key = 'service_schedule';
            $timingQ->question_text = '¿Cuándo necesitás resolverlo?';
            $timingQ->input_type = 'single_select';
            $timingQ->is_required = true;
            return $timingQ;
        }

        return null;
    }

    public function calculateQuoteReadiness(ServiceRequestModel $request): QuoteReadiness
    {
        $version = $this->resolveVersion($request);
        if (!$version) {
            return QuoteReadiness::InsufficientInformation;
        }

        $serviceType = $request->serviceType ?? $version->serviceType;
        $answersMap = $this->getAnswersMap($request);
        $questions = $version->questions()->with('conditions')->get();

        $activeQuestions = $questions->filter(function ($question) use ($answersMap) {
            return $this->evaluateConditions($question, $answersMap);
        });

        // 1. Critical questions check
        $criticalUnanswered = $activeQuestions->filter(function ($q) use ($answersMap) {
            return $q->is_critical && !$this->isAnswered($q, $answersMap);
        });

        if ($criticalUnanswered->isNotEmpty()) {
            return QuoteReadiness::InsufficientInformation;
        }

        // 2. Matching questions check
        $matchingQuestions = $activeQuestions->filter(fn($q) => $q->used_for_matching || $q->is_required);
        $matchingUnanswered = $matchingQuestions->filter(fn($q) => !$this->isAnswered($q, $answersMap));

        if ($matchingUnanswered->isNotEmpty()) {
            return QuoteReadiness::InsufficientInformation;
        }

        // Check always_requires_evaluation flag
        if ($serviceType && $serviceType->always_requires_evaluation) {
            return QuoteReadiness::RequiresProfessionalEvaluation;
        }

        // 3. Quote questions check
        $quoteQuestions = $activeQuestions->filter(fn($q) => $q->used_for_quote);
        $quoteUnanswered = $quoteQuestions->filter(fn($q) => !$this->isAnswered($q, $answersMap));

        if ($quoteUnanswered->isEmpty() && $quoteQuestions->isNotEmpty()) {
            return QuoteReadiness::ReadyForQuote;
        }

        return QuoteReadiness::ReadyForMatch;
    }

    public function evaluateConditions(QuestionModel $question, array $answersMap): bool
    {
        $conditions = $question->conditions;
        if ($conditions->isEmpty()) {
            return true;
        }

        foreach ($conditions as $condition) {
            $parentKey = $condition->depends_on_question_key;
            if (!isset($answersMap[$parentKey])) {
                return false;
            }

            $userVal = $answersMap[$parentKey];
            $expectedVal = $condition->expected_value;

            if (!$this->checkOperator($condition->operator, $userVal, $expectedVal)) {
                return false;
            }
        }

        return true;
    }

    private function checkOperator(string $operator, mixed $userVal, mixed $expectedVal): bool
    {
        return match ($operator) {
            'equals' => (string) $userVal === (string) $expectedVal,
            'not_equals' => (string) $userVal !== (string) $expectedVal,
            'contains' => is_array($userVal) ? in_array($expectedVal, $userVal) : str_contains((string) $userVal, (string) $expectedVal),
            'in' => is_array($expectedVal) ? in_array($userVal, $expectedVal) : in_array($userVal, explode(',', (string) $expectedVal)),
            default => false,
        };
    }

    private function isAnswered(QuestionModel $question, array $answersMap): bool
    {
        if (!isset($answersMap[$question->question_key])) {
            return false;
        }

        $ans = $answersMap['_raw_' . $question->question_key] ?? null;
        if (!$ans) {
            return true;
        }

        if ($ans->source === \App\Domain\Clarification\Enums\AnswerSource::AiExtracted) {
            return $ans->confirmed_by_user || ($ans->ai_confidence >= 0.75);
        }

        return true;
    }

    private function resolveVersion(ServiceRequestModel $request): ?QuestionnaireVersionModel
    {
        if ($request->questionnaireVersion) {
            return $request->questionnaireVersion;
        }

        if ($request->serviceType && $request->serviceType->currentVersion) {
            return $request->serviceType->currentVersion;
        }

        return null;
    }

    private function getAnswersMap(ServiceRequestModel $request): array
    {
        $answers = $request->answers()->get();
        $map = [];

        foreach ($answers as $ans) {
            $map[$ans->question_key] = $ans->answer_value;
            $map['_raw_' . $ans->question_key] = $ans;
        }

        return $map;
    }
}
