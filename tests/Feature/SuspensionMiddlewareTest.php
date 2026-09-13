<?php

namespace Tests\Feature;

use App\Domain\Trust\Services\BanService;
use App\Domain\Users\Enums\UserStatus;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SuspensionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_active_user_token_stops_working_after_account_suspension(): void
    {
        // 1. Create active user and issue a valid token
        $user = UserModel::create([
            'name'     => 'Active User',
            'email'    => 'active@test.com',
            'password' => Hash::make('Password123'),
            'status'   => UserStatus::Active,
        ]);
        $user->assignRole('client');

        $token = $user->createToken('auth_token')->plainTextToken;

        // 2. Request works with token while active
        $response1 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/auth/me');

        $response1->assertStatus(200)
                  ->assertJsonPath('data.email', 'active@test.com');

        // 3. User account is suspended after token was already issued
        $user->update(['status' => UserStatus::Suspended]);

        // 4. Request with the EXACT SAME token is now rejected
        $response2 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/auth/me');

        $response2->assertStatus(403)
                  ->assertJsonPath('message', 'Tu cuenta se encuentra suspendida.')
                  ->assertJsonPath('errors.account.0', 'Cuenta suspendida.');

        // 5. Also verify on service requests route
        $response3 = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/requests');

        $response3->assertStatus(403)
                  ->assertJsonPath('message', 'Tu cuenta se encuentra suspendida.');
    }

    public function test_user_banned_via_ban_service_is_blocked_immediately(): void
    {
        $user = UserModel::create([
            'name'     => 'Provider User',
            'email'    => 'provider-ban@test.com',
            'password' => Hash::make('Password123'),
            'status'   => UserStatus::Active,
        ]);
        $user->assignRole('client');

        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/auth/me')->assertStatus(200);

        // Ban via BanService
        app(BanService::class)->ban($user, 'fraud_confirmed');

        // Token is immediately rejected
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(403)
                 ->assertJsonPath('message', 'Tu cuenta se encuentra suspendida.');
    }

    public function test_undeterminable_or_invalid_status_fails_closed(): void
    {
        $user = UserModel::create([
            'name'     => 'Null Status User',
            'email'    => 'null-status@test.com',
            'password' => Hash::make('Password123'),
            'status'   => UserStatus::Pending, // Not active
        ]);
        $user->assignRole('client');

        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(403)
                 ->assertJsonPath('message', 'Tu cuenta se encuentra suspendida.');
    }
}
