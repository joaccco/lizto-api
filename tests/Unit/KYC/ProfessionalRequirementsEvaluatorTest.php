<?php

namespace Tests\Unit\KYC;

use App\Domain\Professional\Services\ProfessionalRequirementsEvaluator;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfessionalRequirementsEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected ProfessionalRequirementsEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ProfessionalRequirementsEvaluator();
    }

    public function test_evaluate_unverified_provider_fails_identity(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Provider Test',
            'email' => 'eval_test@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'pending_verification',
            'is_verified' => false,
            'years_experience' => 1,
        ]);

        $result = $this->evaluator->evaluate($provider, 'cerrajeria');

        $this->assertFalse($result->isEligible);
        $this->assertContains('identity', $result->missingRequirements);
    }

    public function test_evaluate_electricidad_requires_matrícula_and_antecedentes(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Electrician Test',
            'email' => 'electrician@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'pending_verification',
            'is_verified' => false,
            'years_experience' => 3,
        ]);

        // Identity verified
        Identity::create([
            'user_id' => $user->id,
            'dni' => '30111222',
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => 'didit_id',
        ]);

        // MVU without matrícula
        $mvu = ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'antecedentes_status' => 'approved',
            'matrícula_number' => null,
            'matrícula_verified_at' => null,
            'skills_verified' => true,
        ]);

        $result = $this->evaluator->evaluate($provider, 'electricidad');

        $this->assertFalse($result->isEligible);
        $this->assertContains('matrícula', $result->missingRequirements);
        $this->assertContains('identity', $result->fulfilledRequirements);
        $this->assertContains('antecedentes', $result->fulfilledRequirements);

        // Now fulfill matrícula
        $mvu->update([
            'matrícula_number' => 'MAT-99881',
            'matrícula_verified_at' => now(),
        ]);

        $provider->refresh();
        $newResult = $this->evaluator->evaluate($provider, 'electricidad');

        $this->assertTrue($newResult->isEligible);
        $this->assertEmpty($newResult->missingRequirements);
    }

    public function test_unmapped_category_fails_closed_with_explicit_error(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Ghost Cat Provider',
            'email' => 'ghost@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'pending_verification',
            'is_verified' => false,
            'years_experience' => 5,
        ]);

        Identity::create([
            'user_id' => $user->id,
            'dni' => '31222333',
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => 'didit_id',
        ]);

        $result = $this->evaluator->evaluate($provider, 'categoria_inexistente_xyz');

        // Must fail closed!
        $this->assertFalse($result->isEligible, 'Categoría no mapeada debe fallar cerrado');
        $this->assertContains('unsupported_category_configuration', $result->missingRequirements);
        $this->assertStringContainsString('Faltan requisitos definidos', $result->details['error']);
    }

    public function test_subcategory_inherits_parent_category_rules(): void
    {
        $parentCategory = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cerrajería',
            'slug' => 'cerrajeria',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $subCategory = CategoryModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cerrajería del Hogar',
            'slug' => 'cerrajeria-hogar',
            'parent_id' => $parentCategory->id,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Subcat Provider',
            'email' => 'subcat@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'years_experience' => 3,
        ]);

        Identity::create([
            'user_id' => $user->id,
            'dni' => '32555666',
            'status' => 'approved',
            'verified_at' => now(),
            'verified_by' => 'didit_id',
        ]);

        // Without antecedentes, must fail because cerrajeria requires antecedentes
        $mvu = ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'antecedentes_status' => 'pending',
        ]);

        $result = $this->evaluator->evaluate($provider, 'cerrajeria-hogar');
        $this->assertFalse($result->isEligible);
        $this->assertContains('antecedentes', $result->missingRequirements);

        // Approve antecedentes -> becomes eligible
        $mvu->update(['antecedentes_status' => 'approved']);
        $provider->refresh();

        $result2 = $this->evaluator->evaluate($provider, 'cerrajeria-hogar');
        $this->assertTrue($result2->isEligible);
        $this->assertEmpty($result2->missingRequirements);
    }

    /**
     * Test del catálogo completo: verifica que ninguna categoría ni subcategoría
     * del catálogo quede huérfana o falle cerrado por falta de configuración.
     */
    public function test_entire_catalog_resolves_valid_rules_and_never_fails_closed(): void
    {
        $this->seed(CategorySeeder::class);

        $categories = CategoryModel::all();
        $this->assertNotEmpty($categories, 'El catálogo de categorías no debe estar vacío.');

        $dummyUser = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Catalog Auditor',
            'email' => 'cat_auditor@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $dummyProvider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $dummyUser->id,
            'years_experience' => 5,
        ]);

        foreach ($categories as $cat) {
            $result = $this->evaluator->evaluate($dummyProvider, $cat->slug);

            $this->assertNotContains(
                'unsupported_category_configuration',
                $result->missingRequirements,
                "La categoría '{$cat->slug}' (nombre: {$cat->name}) no tiene reglas resolubles y falló cerrado."
            );
        }
    }
}
