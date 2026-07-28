<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_health_check_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');
        $response->assertStatus(200)
                 ->assertJsonPath('status', 'ok');
    }

    public function test_user_can_register_as_client(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Juan Perez',
            'email'                 => 'juan-auth@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'client',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'data' => ['user', 'token'],
                 ]);

        $this->assertDatabaseHas('users', ['email' => 'juan-auth@test.com']);
    }

    public function test_user_can_register_as_provider(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Roberto Tecnico',
            'email'                 => 'roberto-auth@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'provider',
        ]);

        $response->assertStatus(201);
        $this->assertTrue(
            UserModel::where('email', 'roberto-auth@test.com')
                     ->first()
                     ->hasRole('provider')
        );
    }

    public function test_user_can_login(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'client',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'test@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => ['user', 'token'],
                 ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        UserModel::create([
            'name'     => 'Wrong Password User',
            'email'    => 'user@test.com',
            'password' => Hash::make('correctpassword'),
            'status'   => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'user@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_authenticated_user_can_get_profile(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'me@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'client',
        ]);

        $token = $register->json('data.token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
                 ->assertJsonPath('data.email', 'me@test.com');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }

    public function test_user_can_logout(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Logout User',
            'email'                 => 'logout@test.com',
            'password'              => 'password123',
            'password_confirmation' => 'password123',
            'role'                  => 'client',
        ]);

        $token = $register->json('data.token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
    }
}
