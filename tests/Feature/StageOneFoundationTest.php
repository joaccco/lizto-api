<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderCategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ProviderServiceAreaModel;
use App\Infrastructure\Persistence\Eloquent\SurveyQuestionModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StageOneFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_expected_categories_are_seeded(): void
    {
        $this->assertSame(11, CategoryModel::count());
        $this->assertSame(8, CategoryModel::whereNull('parent_id')->count());
        $this->assertSame(3, CategoryModel::whereNotNull('parent_id')->count());
        $this->assertDatabaseHas('categories', ['slug' => 'cerrajeria', 'name' => 'Cerrajería']);
    }

    public function test_locksmith_survey_questions_are_seeded(): void
    {
        $locksmith = CategoryModel::where('slug', 'cerrajeria')->firstOrFail();
        $questions = SurveyQuestionModel::where('category_id', $locksmith->id)->orderBy('sort_order')->get();

        $this->assertCount(5, $questions);
        $this->assertSame(['property_type', 'service_type', 'has_spare_key', 'has_new_lock', 'photo'], $questions->pluck('question_key')->all());
        $this->assertSame('single_select', $questions->firstWhere('question_key', 'property_type')->input_type);
        $this->assertSame(['if' => 'service_type', 'equals' => 'apertura'], $questions->firstWhere('question_key', 'has_spare_key')->condition);
    }

    public function test_client_users_are_seeded_without_provider_profiles(): void
    {
        $clients = UserModel::whereIn('email', ['juan@test.com', 'maria@test.com', 'carlos@test.com'])->get();

        $this->assertCount(3, $clients);
        $this->assertTrue($clients->every(fn (UserModel $client) => $client->providerProfile === null));
        $this->assertDatabaseHas('users', ['email' => 'maria@test.com', 'status' => 'active']);
    }

    public function test_five_provider_profiles_are_seeded(): void
    {
        $this->assertSame(5, ProviderProfileModel::count());
        $this->assertSame(3, ProviderProfileModel::available()->count());
        $this->assertSame(1, ProviderProfileModel::availableSoon()->count());
        $this->assertDatabaseHas('users', ['email' => 'roberto@lizto.test', 'name' => 'Roberto Medina']);
    }

    public function test_provider_relationships_connect_users_categories_and_service_areas(): void
    {
        $user = UserModel::where('email', 'roberto@lizto.test')->firstOrFail();
        $profile = $user->providerProfile;

        $this->assertNotNull($profile);
        $this->assertSame('Roberto Medina', $profile->user->name);
        $this->assertCount(1, $profile->categories);
        $this->assertSame('cerrajeria', $profile->categories->first()->category->slug);
        $this->assertCount(1, $profile->serviceAreas);
        $this->assertSame('15.00', $profile->serviceAreas->first()->radius_km);
    }

    public function test_spatie_roles_and_permissions_work_with_user_model(): void
    {
        $user = UserModel::where('email', 'juan@test.com')->firstOrFail();
        $role = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'requests.create', 'guard_name' => 'web']);

        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('client'));
        $this->assertTrue($user->hasPermissionTo('requests.create'));
        $this->assertSame(UserModel::class, get_class(config('auth.providers.users.model')::query()->first()));
    }

    public function test_provider_category_foreign_keys_are_enforced(): void
    {
        $category = CategoryModel::where('slug', 'cerrajeria')->firstOrFail();

        $this->expectException(QueryException::class);

        ProviderCategoryModel::create([
            'provider_id' => 999999,
            'category_id' => $category->id,
            'specialties' => ['apertura'],
            'price_type' => 'quote',
            'is_active' => true,
        ]);
    }

    public function test_provider_service_area_foreign_keys_are_enforced(): void
    {
        $this->expectException(QueryException::class);

        ProviderServiceAreaModel::create([
            'provider_id' => 999999,
            'center_lat' => -27.4692,
            'center_lng' => -58.8306,
            'radius_km' => 10,
            'label' => 'Invalid area',
        ]);
    }
}