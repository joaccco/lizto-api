<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\ServiceRequests\Enums\RequestUrgency;
use App\Domain\Works\Enums\WorkStatus;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingClarificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function createUser(string $role = 'client'): UserModel
    {
        return UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Test ' . rand(100, 999),
            'email' => 'user_' . Str::random(8) . '@test.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_clarification_engine_creates_request_and_stores_immediate_urgency(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/clarification/service-requests', [
                'prompt' => 'Cambio de cerradura de emergencia',
                'urgency' => 'immediate',
            ]);

        $response->assertStatus(201);
        $uuid = $response->json('data.id');

        $sr = ServiceRequestModel::where('uuid', $uuid)->first();
        $this->assertNotNull($sr);
        $this->assertEquals(RequestUrgency::Immediate, $sr->urgency);
        $this->assertEquals('Necesita atención ahora mismo', $sr->formatted_schedule);
    }

    public function test_clarification_engine_stores_today_request_with_time_window(): void
    {
        $user = $this->createUser();

        $createRes = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/clarification/service-requests', [
                'prompt' => 'Reparación de cerradura trabada',
                'urgency' => 'today',
            ]);

        $uuid = $createRes->json('data.id');

        $qId = \App\Infrastructure\Persistence\Eloquent\QuestionModel::first()?->id ?? 1;

        $ansRes = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clarification/service-requests/{$uuid}/answers", [
                'question_id' => $qId,
                'question_key' => 'service_schedule',
                'answer_value' => [
                    'timing' => 'today',
                    'window_start' => '14:00',
                    'window_end' => '17:00',
                ],
            ]);

        $ansRes->assertStatus(200);

        $sr = ServiceRequestModel::where('uuid', $uuid)->first();
        $this->assertEquals(RequestUrgency::Today, $sr->urgency);
        $this->assertEquals('14:00', $sr->window_start);
        $this->assertEquals('17:00', $sr->window_end);
        $this->assertStringContainsString('Hoy · entre 14:00 y 17:00', $sr->formatted_schedule);
    }

    public function test_clarification_engine_stores_scheduled_request_with_calendar_date(): void
    {
        $user = $this->createUser();
        $futureDate = now()->addDays(3)->toDateString();
        $qId = \App\Infrastructure\Persistence\Eloquent\QuestionModel::first()?->id ?? 1;

        $createRes = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/clarification/service-requests', [
                'prompt' => 'Instalación de cerradura digital',
                'urgency' => 'scheduled',
            ]);

        $uuid = $createRes->json('data.id');

        $ansRes = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/clarification/service-requests/{$uuid}/answers", [
                'question_id' => $qId,
                'question_key' => 'service_schedule',
                'answer_value' => [
                    'timing' => 'scheduled',
                    'scheduled_date' => $futureDate,
                    'window_start' => '10:00',
                    'window_end' => '12:00',
                ],
            ]);

        $ansRes->assertStatus(200);

        $sr = ServiceRequestModel::where('uuid', $uuid)->first();
        $this->assertEquals(RequestUrgency::Scheduled, $sr->urgency);
        $this->assertEquals($futureDate, $sr->scheduled_date->toDateString());
        $this->assertEquals('10:00', $sr->window_start);
        $this->assertEquals('12:00', $sr->window_end);
    }

    public function test_matching_action_filters_out_provider_with_overlapping_work_conflict(): void
    {
        $category = CategoryModel::first();

        // 1. Create Available Provider A (No conflicts)
        $userA = $this->createUser('provider');
        $providerA = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userA->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'availability_status' => 'available',
        ]);
        $providerA->categories()->create(['category_id' => $category->id, 'is_active' => true]);

        // 2. Create Provider B with conflicting work today at 15:00
        $userB = $this->createUser('provider');
        $providerB = ProviderProfileModel::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $userB->id,
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'availability_status' => 'available',
        ]);
        $providerB->categories()->create(['category_id' => $category->id, 'is_active' => true]);

        $srConflicting = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $userA->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Trabajo existente',
            'urgency' => RequestUrgency::Today,
            'scheduled_date' => now()->toDateString(),
            'window_start' => '14:00',
            'window_end' => '16:00',
        ]);

        WorkModel::create([
            'uuid' => (string) Str::uuid(),
            'service_request_id' => $srConflicting->id,
            'client_id' => $userA->id,
            'provider_id' => $providerB->id,
            'status' => WorkStatus::Confirmed,
            'scheduled_at' => now()->setTime(15, 0),
        ]);

        // 3. New request for Provider B's conflicting window
        $newSr = ServiceRequestModel::create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $userA->id,
            'category_id' => $category->id,
            'raw_prompt' => 'Nuevo pedido',
            'urgency' => RequestUrgency::Today,
            'scheduled_date' => now()->toDateString(),
            'window_start' => '14:30',
            'window_end' => '15:30',
        ]);

        $action = new \App\Application\Matching\Actions\RunMatchingAction();
        $results = $action->execute($newSr);

        $matchedProviderIds = collect($results)->pluck('provider.id')->toArray();

        // Provider A must be present, Provider B must be excluded due to conflict
        $this->assertContains($providerA->id, $matchedProviderIds);
        $this->assertNotContains($providerB->id, $matchedProviderIds);
    }
}
