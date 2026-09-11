<?php

namespace Tests\Feature\Admin;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminMVUTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);

        $this->admin = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Admin User',
            'email' => 'admin_mvu@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_admin_mvu_pending_lists_only_pending_items(): void
    {
        Sanctum::actingAs($this->admin);

        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Pending Provider User',
            'email' => 'mvu_provider@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'pending_verification',
            'is_verified' => false,
        ]);

        $mvu = ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'overall_verification_status' => 'pending',
        ]);

        $response = $this->getJson('/api/v1/admin/mvu/pending');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $mvu->id);
    }

    public function test_admin_mvu_approve_verifies_provider_profile(): void
    {
        Sanctum::actingAs($this->admin);

        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Approve User',
            'email' => 'approve_mvu@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'pending_verification',
            'is_verified' => false,
        ]);

        $mvu = ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'overall_verification_status' => 'pending',
        ]);

        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('data.overall_verification_status', 'approved');

        $mvu->refresh();
        $provider->refresh();

        $this->assertEquals('approved', $mvu->overall_verification_status);
        $this->assertTrue($mvu->skills_verified);
        $this->assertTrue($provider->is_verified);
        $this->assertEquals('verified', $provider->status->value);
        $this->assertEquals($this->admin->id, $provider->verified_by);
    }

    public function test_admin_mvu_reject_updates_provider_rejection_reason(): void
    {
        Sanctum::actingAs($this->admin);

        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Reject User',
            'email' => 'reject_mvu@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'pending_verification',
            'is_verified' => false,
        ]);

        $mvu = ProfessionalMVU::create([
            'provider_id' => $provider->id,
            'overall_verification_status' => 'pending',
        ]);

        $reason = 'Antecedentes penales incompatibles con la actividad requerida.';
        $response = $this->postJson("/api/v1/admin/mvu/{$mvu->id}/reject", [
            'reason' => $reason,
        ]);

        $response->assertStatus(200);

        $mvu->refresh();
        $provider->refresh();

        $this->assertEquals('rejected', $mvu->overall_verification_status);
        $this->assertFalse($provider->is_verified);
        $this->assertEquals('rejected', $provider->status->value);
        $this->assertEquals($reason, $provider->rejection_reason);
    }
}
