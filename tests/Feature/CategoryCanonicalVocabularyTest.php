<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCanonicalVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\CategorySeeder::class);
    }

    public function test_canonical_categories_resolve_to_themselves(): void
    {
        $canonicals = [
            'cerrajeria',
            'electricidad',
            'plomeria',
            'abogacia',
            'contaduria',
            'fotografia',
            'diseno',
            'limpieza',
            'cerrajeria-hogar',
        ];

        foreach ($canonicals as $slug) {
            $this->assertEquals($slug, CategoryModel::resolveCanonicalSlug($slug));
            $resolvedModel = CategoryModel::resolve($slug);
            $this->assertNotNull($resolvedModel, "Failed to resolve canonical category model for: {$slug}");
            $this->assertEquals($slug, $resolvedModel->slug);
            $this->assertTrue(CategoryModel::isResolvable($slug));
        }
    }

    public function test_synonyms_and_frontend_vocabulary_resolve_to_canonical_category(): void
    {
        $testCases = [
            'electricista' => 'electricidad',
            'electricistas' => 'electricidad',
            'electrico' => 'electricidad',
            'plomero' => 'plomeria',
            'plomeros' => 'plomeria',
            'gasista' => 'plomeria',
            'cerrajero' => 'cerrajeria',
            'cerrajeros' => 'cerrajeria',
            'abogado' => 'abogacia',
            'abogados' => 'abogacia',
            'abogada' => 'abogacia',
            'contador' => 'contaduria',
            'contadores' => 'contaduria',
            'fotografo' => 'fotografia',
            'diseñador' => 'diseno',
            'cerrajero-hogar' => 'cerrajeria-hogar',
        ];

        foreach ($testCases as $synonym => $expectedCanonical) {
            $this->assertEquals(
                $expectedCanonical,
                CategoryModel::resolveCanonicalSlug($synonym),
                "Synonym '{$synonym}' did not resolve to canonical '{$expectedCanonical}'"
            );

            $model = CategoryModel::resolve($synonym);
            $this->assertNotNull($model, "CategoryModel::resolve('{$synonym}') returned null");
            $this->assertEquals($expectedCanonical, $model->slug);
        }
    }

    public function test_system_fails_when_given_unresolvable_category_slug(): void
    {
        $unresolvableSlugs = [
            'astronauta',
            'alquimista',
            'categoria-inexistente-xyz',
            'hack_injection',
        ];

        foreach ($unresolvableSlugs as $slug) {
            $this->assertNull(CategoryModel::resolveCanonicalSlug($slug));
            $this->assertNull(CategoryModel::resolve($slug));
            $this->assertFalse(CategoryModel::isResolvable($slug));

            $caught = false;
            try {
                CategoryModel::resolveOrFail($slug);
            } catch (\InvalidArgumentException $e) {
                $caught = true;
            }
            $this->assertTrue($caught, "Expected resolveOrFail to throw InvalidArgumentException for '{$slug}'");
        }
    }

    public function test_provider_public_catalog_filters_successfully_using_frontend_synonyms(): void
    {
        $electricidadCategory = CategoryModel::where('slug', 'electricidad')->firstOrFail();

        $user = UserModel::create([
            'email' => 'electricista.real@test.com',
            'name' => 'Lucas Romero',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified,
            'availability_status' => 'available',
            'is_verified' => true,
            'is_available' => true,
            'is_profile_complete' => true,
            'base_address' => 'Palermo, CABA',
            'base_lat' => -34.5889,
            'base_lng' => -58.4306,
            'avg_rating' => 4.95,
        ]);

        $identity = \App\Models\Identity::create([
            'user_id' => $user->id,
            'status' => 'approved',
        ]);

        \App\Models\ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'identity_id' => $identity->id,
            'overall_verification_status' => 'approved',
            'mvu_status' => 'verified',
            'requirements_evaluation' => ['rules_passed' => true],
            'last_evaluated_at' => now(),
        ]);

        ProviderCategoryModel::create([
            'provider_id' => $provider->id,
            'category_id' => $electricidadCategory->id,
            'is_active' => true,
        ]);

        // 1. Frontend sends user choice 'electricista'
        $response = $this->getJson('/api/v1/providers?category=electricista');
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data, 'Provider catalog returned 0 results when filtering by synonym "electricista"');
        $this->assertEquals($provider->uuid, $data[0]['id']);

        // 2. Frontend sends unresolvable category -> returns 0 results (fails closed)
        $emptyResponse = $this->getJson('/api/v1/providers?category=astronauta');
        $emptyResponse->assertStatus(200);
        $this->assertEmpty($emptyResponse->json('data'));
    }
}
