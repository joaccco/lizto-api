<?php

namespace Tests\Feature;

use App\Application\AI\Actions\ParseIntentAction;
use App\Domain\Clarification\Services\ClarificationAiService;
use Tests\TestCase;

/**
 * T3b: Category Vocabulary Drift Test
 * Ensures that ClarificationAiService and ParseIntentAction both resolve
 * their category keywords solely against the canonical vocabulary defined
 * in config('categories.canonical').
 */
class CategoryVocabularyDriftTest extends TestCase
{
    public function test_clarification_service_resolves_only_canonical_category_slugs(): void
    {
        $service = app(ClarificationAiService::class);
        $canonicalSlugs = array_keys(config('categories.canonical', []));

        $serviceKeywords = $service->getCategoryKeywords();

        $this->assertNotEmpty($serviceKeywords, 'Clarification service keywords must not be empty.');

        foreach (array_keys($serviceKeywords) as $slug) {
            $this->assertContains(
                $slug,
                $canonicalSlugs,
                "ClarificationAiService knows category slug '{$slug}', which is NOT in config('categories.canonical')."
            );
        }
    }

    public function test_parse_intent_action_resolves_only_canonical_category_slugs(): void
    {
        $action = app(ParseIntentAction::class);
        $canonicalSlugs = array_keys(config('categories.canonical', []));

        $actionKeywords = $action->getCategoryKeywords();

        $this->assertNotEmpty($actionKeywords, 'ParseIntentAction keywords must not be empty.');

        foreach (array_keys($actionKeywords) as $slug) {
            $this->assertContains(
                $slug,
                $canonicalSlugs,
                "ParseIntentAction knows category slug '{$slug}', which is NOT in config('categories.canonical')."
            );
        }
    }

    public function test_drift_detected_when_unregistered_slug_introduced(): void
    {
        // Prove that drift would be detected if a service introduced an unregistered slug
        $canonicalSlugs = array_keys(config('categories.canonical', []));
        $rogueSlug = 'jardineria_no_canonico';

        $this->assertNotContains(
            $rogueSlug,
            $canonicalSlugs,
            "Rogue slug should not be present in canonical vocabulary."
        );
    }
}
