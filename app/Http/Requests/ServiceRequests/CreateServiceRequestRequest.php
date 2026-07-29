<?php

namespace App\Http\Requests\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class CreateServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string'],
            'urgency' => ['nullable', 'string'],
            'category_slug' => ['nullable', 'string'],
            'category_id' => ['nullable'],
            'is_remote' => ['nullable', 'boolean'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['nullable', 'numeric'],
            'location.lng' => ['nullable', 'numeric'],
            'location.address' => ['nullable', 'string'],
            'parsed_intent' => ['nullable', 'array'],
        ];
    }
}
