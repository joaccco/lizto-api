<?php

namespace App\Http\Requests\ServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

class ParseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:3', 'max:500'],
            'urgency' => ['nullable', 'string', 'in:immediate,today,scheduled,flexible'],
            'location' => ['nullable', 'array'],
            'location.lat' => ['nullable', 'numeric'],
            'location.lng' => ['nullable', 'numeric'],
        ];
    }
}
