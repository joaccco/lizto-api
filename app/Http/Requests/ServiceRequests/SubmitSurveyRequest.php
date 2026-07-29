<?php

namespace App\Http\Requests\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_key' => ['required', 'string'],
            'answers.*.question_text' => ['required', 'string'],
            'answers.*.answer_value' => ['required'],
            'answers.*.question_id' => ['nullable'],
        ];
    }
}
