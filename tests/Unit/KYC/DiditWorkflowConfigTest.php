<?php

namespace Tests\Unit\KYC;

use App\Domain\Identity\Services\IdentityService;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiditWorkflowConfigTest extends TestCase
{
    public function test_fails_loudly_when_workflow_id_not_configured(): void
    {
        config(['services.didit.workflow_id' => null]);

        $service = app(IdentityService::class);
        $user = new UserModel([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'email' => 'test@example.com',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DIDIT_WORKFLOW_ID is not configured.');

        $service->initiateKYC($user);
    }

    public function test_env_example_has_no_hardcoded_workflow_id(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('DIDIT_WORKFLOW_ID=', $envExample);
        $this->assertStringNotContainsString('1a3cf8eb-1e92-4554-bb91-2017577cf811', $envExample);
    }
}
