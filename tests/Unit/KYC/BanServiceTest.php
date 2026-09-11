<?php

namespace Tests\Unit\KYC;

use App\Domain\Trust\Services\BanService;
use App\Domain\Users\Enums\UserStatus;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BanServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BanService $banService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->banService = new BanService();
    }

    public function test_ban_user_updates_status_and_suspends_provider_profile(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Bad User',
            'email' => 'bad_user@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $provider = ProviderProfileModel::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'status' => 'verified',
            'is_verified' => true,
            'availability_status' => 'available',
        ]);

        $ban = $this->banService->ban($user, 'fraud_confirmed', 'Confirmed payment fraud');

        $this->assertNotNull($ban->id);
        $user->refresh();
        $provider->refresh();

        $this->assertEquals(UserStatus::Suspended, $user->status);
        $this->assertEquals('suspended', $provider->status->value);
        $this->assertEquals('unavailable', $provider->availability_status->value);
        $this->assertTrue($this->banService->isBanned($user));
    }

    public function test_restrict_user_action(): void
    {
        $user = UserModel::create([
            'uuid' => Str::uuid()->toString(),
            'name' => 'Restricted User',
            'email' => 'restricted_user@lizto.test',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $restriction = $this->banService->restrict(
            $user,
            'cannot_accept_requests',
            'Multiple late cancellations',
            now()->addDays(7)
        );

        $this->assertNotNull($restriction->id);
        $this->assertTrue($this->banService->hasActiveRestriction($user, 'cannot_accept_requests'));
        $this->assertFalse($this->banService->hasActiveRestriction($user, 'cannot_create_requests'));

        // Expired restriction should return false
        $expiredRestriction = $this->banService->restrict(
            $user,
            'cannot_create_requests',
            'Expired test',
            now()->subDay()
        );

        $this->assertFalse($this->banService->hasActiveRestriction($user, 'cannot_create_requests'));
    }
}
