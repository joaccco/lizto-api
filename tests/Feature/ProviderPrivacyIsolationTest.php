<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\MatchCardModel;
use App\Infrastructure\Persistence\Eloquent\MatchSessionModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderPrivacyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createProviderWithCategory(string $name, string $categoryName, string $categorySlug): array
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'email' => strtolower(str_replace(' ', '_', $name)) . '_' . Str::random(4) . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('provider');

        $profile = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
        ]);

        $category = CategoryModel::firstOrCreate(
            ['slug' => $categorySlug],
            ['uuid' => (string) Str::uuid(), 'name' => $categoryName]
        );

        $profile->categories()->create([
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        return [$user, $profile, $category];
    }

    public function test_provider_only_sees_requests_explicitly_matched_or_assigned_to_them(): void
    {
        // 1. Create Electrician Provider A
        [$userA, $profileA, $catElectricidad] = $this->createProviderWithCategory('Electricista Carlos', 'Electricidad', 'electricidad');

        // 2. Create Electrician Provider B (Same category, but different provider)
        [$userB, $profileB] = $this->createProviderWithCategory('Electricista Pedro', 'Electricidad', 'electricidad');

        // 3. Create Accountant Provider C (Different category)
        [$userC, $profileC, $catContaduria] = $this->createProviderWithCategory('Contador Roberto', 'Contaduría', 'contaduria');

        // 4. Create Client
        $client = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cliente Test',
            'email' => 'client_' . Str::random(4) . '@test.com',
            'password' => bcrypt('password'),
        ]);

        // 5. Service Request 1: Electricity request matched specifically to Provider A
        $sr1 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $catElectricidad->id,
            'raw_prompt' => 'Cambio de disyuntor para Carlos',
            'status' => 'pending_matching',
        ]);
        $session1 = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr1->id,
            'status' => 'active',
        ]);
        MatchCardModel::create([
            'match_session_id' => $session1->id,
            'provider_id' => $profileA->id, // Matched ONLY to Provider A
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        // 6. Service Request 2: Accounting request matched specifically to Provider C
        $sr2 = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'category_id' => $catContaduria->id,
            'raw_prompt' => 'Balance anual contable',
            'status' => 'pending_matching',
        ]);
        $session2 = MatchSessionModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $sr2->id,
            'status' => 'active',
        ]);
        MatchCardModel::create([
            'match_session_id' => $session2->id,
            'provider_id' => $profileC->id, // Matched ONLY to Provider C
            'rank_position' => 1,
            'score_total' => 0.9,
            'card_status' => 'accepted',
        ]);

        // VERIFICATION FOR PROVIDER A (Electricista Carlos):
        // Should ONLY see SR1 ("Cambio de disyuntor para Carlos")
        // Must NOT see SR2 (Accounting) nor requests matched to other providers!
        $responseA = $this->actingAs($userA, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $responseA->assertStatus(200);

        $idsA = collect($responseA->json('data'))->pluck('id')->toArray();
        $this->assertContains($sr1->uuid, $idsA);
        $this->assertNotContains($sr2->uuid, $idsA);
        $this->assertCount(1, $idsA);

        // VERIFICATION FOR PROVIDER B (Electricista Pedro):
        // SR1 was matched specifically to Provider A, so Provider B must NOT see it!
        $responseB = $this->actingAs($userB, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $responseB->assertStatus(200);

        $idsB = collect($responseB->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($sr1->uuid, $idsB);
        $this->assertNotContains($sr2->uuid, $idsB);
        $this->assertCount(0, $idsB);

        // VERIFICATION FOR PROVIDER C (Contador Roberto):
        // Should ONLY see SR2 (Accounting) and NOT SR1 (Electricity)
        $responseC = $this->actingAs($userC, 'sanctum')->getJson('/api/v1/provider/work-requests');
        $responseC->assertStatus(200);

        $idsC = collect($responseC->json('data'))->pluck('id')->toArray();
        $this->assertContains($sr2->uuid, $idsC);
        $this->assertNotContains($sr1->uuid, $idsC);
        $this->assertCount(1, $idsC);
    }
}
