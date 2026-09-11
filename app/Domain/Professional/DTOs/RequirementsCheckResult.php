<?php

namespace App\Domain\Professional\DTOs;

class RequirementsCheckResult
{
    public function __construct(
        public readonly bool $isEligible,
        public readonly array $fulfilledRequirements,
        public readonly array $missingRequirements,
        public readonly string $categorySlug,
        public readonly array $details = [],
    ) {}

    public function toArray(): array
    {
        return [
            'is_eligible' => $this->isEligible,
            'fulfilled_requirements' => $this->fulfilledRequirements,
            'missing_requirements' => $this->missingRequirements,
            'category_slug' => $this->categorySlug,
            'details' => $this->details,
        ];
    }
}
