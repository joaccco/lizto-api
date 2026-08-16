<?php

namespace App\Domain\Clarification\Services;

use App\Infrastructure\Persistence\Eloquent\QuestionModel;
use App\Infrastructure\Persistence\Eloquent\ServiceBriefModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;

class ServiceBriefBuilder
{
    public function buildOrUpdate(ServiceRequestModel $request): ServiceBriefModel
    {
        $existingBrief = $request->brief;
        if ($existingBrief && $existingBrief->is_confirmed) {
            return $existingBrief;
        }

        $answers = $request->answers()->with('question')->get();
        $attributes = [];
        $summaryLines = [];

        if ($request->original_prompt) {
            $summaryLines[] = "Solicitud inicial: {$request->original_prompt}";
        }

        foreach ($answers as $ans) {
            $val = $ans->answer_value;
            $valStr = is_array($val) ? implode(', ', $val) : (string) $val;

            $attributes[$ans->question_key] = $val;
            $qText = $ans->question?->question_text ?? $ans->question_key;
            $summaryLines[] = "{$qText}: {$valStr}";
        }

        $summary = implode("\n", $summaryLines);

        return ServiceBriefModel::updateOrCreate(
            ['service_request_id' => $request->id],
            [
                'summary' => $summary,
                'attributes' => $attributes,
                'is_confirmed' => false,
            ]
        );
    }

    public function confirm(ServiceRequestModel $request): ServiceBriefModel
    {
        $brief = $this->buildOrUpdate($request);
        $brief->update([
            'is_confirmed' => true,
            'confirmed_at' => now(),
        ]);
        return $brief;
    }
}
