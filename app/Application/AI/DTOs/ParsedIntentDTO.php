<?php

namespace App\Application\AI\DTOs;

class ParsedIntentDTO
{
    public function __construct(
        public array $parsedIntent,
        public array $suggestedQuestions,
        public string $mode
    ) {}

    public function toArray(): array
    {
        return [
            'parsed_intent' => $this->parsedIntent,
            'suggested_questions' => $this->suggestedQuestions,
            'mode' => $this->mode,
        ];
    }
}
