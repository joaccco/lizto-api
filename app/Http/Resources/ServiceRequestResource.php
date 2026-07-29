<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->relationLoaded('category') ? $this->category : null;
        
        $questions = [];
        if ($category && $category->relationLoaded('surveyQuestions')) {
            $questions = $category->surveyQuestions->map(function ($q) {
                return [
                    'id' => $q->id,
                    'key' => $q->question_key,
                    'text' => $q->question_text,
                    'input_type' => $q->input_type,
                    'options' => $q->options,
                    'is_required' => $q->is_required,
                ];
            })->toArray();
        }

        return [
            'id' => $this->uuid,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'urgency' => $this->urgency instanceof \BackedEnum ? $this->urgency->value : $this->urgency,
            'raw_prompt' => $this->raw_prompt,
            'category' => $category ? [
                'id' => $category->slug,
                'name' => $category->name,
                'slug' => $category->slug,
            ] : null,
            'suggested_questions' => $questions,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
