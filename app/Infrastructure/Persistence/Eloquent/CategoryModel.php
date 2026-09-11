<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CategoryModel extends Model
{
    protected $table = 'categories';
    protected $fillable = ['parent_id', 'name', 'slug', 'icon', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent()
    {
        return $this->belongsTo(CategoryModel::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(CategoryModel::class, 'parent_id');
    }

    public function surveyQuestions()
    {
        return $this->hasMany(SurveyQuestionModel::class, 'category_id');
    }

    /**
     * Resolves an arbitrary slug, alias, synonym, or name to the single canonical category slug.
     */
    public static function resolveCanonicalSlug(?string $slugOrAlias): ?string
    {
        if (!$slugOrAlias || trim($slugOrAlias) === '') {
            return null;
        }

        $raw = trim($slugOrAlias);
        $normalized = Str::slug($raw);

        $configCanonical = config('categories.canonical', []);

        // 1. Direct match on canonical slug key
        if (isset($configCanonical[$normalized])) {
            return $normalized;
        }

        // 2. Match in synonyms list
        foreach ($configCanonical as $canonicalSlug => $info) {
            $synonyms = $info['synonyms'] ?? [];
            foreach ($synonyms as $synonym) {
                if (Str::slug($synonym) === $normalized) {
                    return $canonicalSlug;
                }
            }
        }

        // 3. Check if an active category in database exists directly with this slug
        $dbCategory = self::where('slug', $normalized)->first();
        if ($dbCategory) {
            return $dbCategory->slug;
        }

        // 4. Check if database category name matches
        $dbCategoryByName = self::whereRaw('LOWER(name) = ?', [mb_strtolower($raw)])->first();
        if ($dbCategoryByName) {
            return $dbCategoryByName->slug;
        }

        return null;
    }

    /**
     * Resolves an arbitrary slug or synonym directly to the Eloquent CategoryModel.
     */
    public static function resolve(?string $slugOrAlias): ?self
    {
        $canonicalSlug = self::resolveCanonicalSlug($slugOrAlias);
        if (!$canonicalSlug) {
            return null;
        }

        return self::where('slug', $canonicalSlug)->first();
    }

    /**
     * Resolves to CategoryModel or throws an exception if the category cannot be resolved.
     *
     * @throws \InvalidArgumentException
     */
    public static function resolveOrFail(string $slugOrAlias): self
    {
        $category = self::resolve($slugOrAlias);
        if (!$category) {
            throw new \InvalidArgumentException("No se pudo resolver la categoría para el slug o alias: '{$slugOrAlias}'.");
        }

        return $category;
    }

    /**
     * Checks if a slug or alias can be resolved canonically.
     */
    public static function isResolvable(string $slugOrAlias): bool
    {
        return self::resolveCanonicalSlug($slugOrAlias) !== null;
    }
}