<?php

namespace App\Domain\Professional\Services;

use App\Domain\Professional\DTOs\RequirementsCheckResult;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;

class ProfessionalRequirementsEvaluator
{
    /**
     * Evaluate provider profile and MVU against category requirements.
     * Fails closed if category has no rules defined.
     * Subcategories inherit rules from their parent category.
     */
    public function evaluate(ProviderProfileModel $provider, ?string $categorySlug = null): RequirementsCheckResult
    {
        $categoriesConfig = config('professional-requirements.categories', []);

        $category = null;
        if ($categorySlug) {
            $category = CategoryModel::where('slug', $categorySlug)->first();
        } else {
            $category = $provider->categories()->with('category.parent')->first()?->category;
        }

        $effectiveSlug = $category?->slug ?? $categorySlug ?? 'unknown';

        // 1. Check direct category rules
        $rules = $categoriesConfig[$effectiveSlug] ?? null;

        // 2. Subcategory inheritance: if not found, check parent category rules
        if (!$rules && $category && $category->parent_id) {
            $parent = $category->parent ?? CategoryModel::find($category->parent_id);
            if ($parent && isset($categoriesConfig[$parent->slug])) {
                $rules = $categoriesConfig[$parent->slug];
            }
        }

        // 3. Fail closed if category has no defined rules
        if (!$rules) {
            return new RequirementsCheckResult(
                isEligible: false,
                fulfilledRequirements: [],
                missingRequirements: ['unsupported_category_configuration'],
                categorySlug: $effectiveSlug,
                details: [
                    'error' => "Faltan requisitos definidos para la categoría: {$effectiveSlug}",
                    'provider_id' => $provider->id,
                ]
            );
        }

        $fulfilled = [];
        $missing = [];

        // 1. Identity Check
        if ($rules['identity_required'] ?? false) {
            $identity = $provider->identity;
            if ($identity && $identity->status === 'approved' && $identity->verified_at !== null) {
                $fulfilled[] = 'identity';
            } else {
                $missing[] = 'identity';
            }
        }

        $mvu = $provider->mvu;

        // 2. Antecedentes Penales Check
        if ($rules['antecedentes_required'] ?? false) {
            if ($mvu && $mvu->antecedentes_status === 'approved') {
                $fulfilled[] = 'antecedentes';
            } else {
                $missing[] = 'antecedentes';
            }
        }

        // 3. Matrícula Profesional Check
        if ($rules['matrícula_required'] ?? false) {
            if ($mvu && !empty($mvu->matrícula_number) && $mvu->matrícula_verified_at !== null) {
                $fulfilled[] = 'matrícula';
            } else {
                $missing[] = 'matrícula';
            }
        }

        // 4. Skills Verification Check
        if ($rules['skills_verification_required'] ?? false) {
            if ($mvu && $mvu->skills_verified === true) {
                $fulfilled[] = 'skills_verification';
            } else {
                $missing[] = 'skills_verification';
            }
        }

        // 5. Experience Years Check
        $minExp = $rules['min_experience_years'] ?? 0;
        if ($minExp > 0) {
            if (($provider->years_experience ?? 0) >= $minExp) {
                $fulfilled[] = 'experience';
            } else {
                $missing[] = 'experience';
            }
        }

        $isEligible = empty($missing);

        return new RequirementsCheckResult(
            isEligible: $isEligible,
            fulfilledRequirements: $fulfilled,
            missingRequirements: $missing,
            categorySlug: $effectiveSlug,
            details: [
                'rules' => $rules,
                'provider_id' => $provider->id,
            ]
        );
    }
}
