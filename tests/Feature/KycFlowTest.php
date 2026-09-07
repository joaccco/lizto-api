<?php

namespace Tests\Feature;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Domain\Providers\Enums\DocumentType;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class KycFlowTest extends TestCase
{
    use RefreshDatabase;

    protected UserModel $user;
    protected UserModel $otherUser;
    protected UserModel $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('s3');
        Storage::fake('local');

        $this->user = UserModel::create([
            'name' => 'KYC Test User',
            'email' => 'kyc_user@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->user->assignRole('client');

        $this->otherUser = UserModel::create([
            'name' => 'Other User',
            'email' => 'other_user@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->otherUser->assignRole('client');

        $this->admin = UserModel::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'password' => bcrypt('Password123'),
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_unauthenticated_request_to_kyc_returns_401()
    {
        $res = $this->getJson('/api/v1/kyc/status');
        $res->assertStatus(401);

        $resUpload = $this->postJson('/api/v1/kyc/documents', []);
        $resUpload->assertStatus(401);
    }

    public function test_kyc_upload_happy_path_returns_201_and_uuid()
    {
        $file = UploadedFile::fake()->create('dni.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '38123456',
            'expiry_date' => '2030-12-31',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'document_id',
            'document_type',
            'status',
            'created_at',
        ]);

        $docId = $response->json('document_id');
        $this->assertDatabaseHas('provider_documents', [
            'uuid' => $docId,
            'document_number' => '38123456',
            'status' => 'pending',
        ]);
    }

    public function test_kyc_upload_rejects_file_exceeding_10mb()
    {
        // 11MB file
        $file = UploadedFile::fake()->create('huge.pdf', 11264, 'application/pdf');

        $response = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '12345678',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document_file');
    }

    public function test_kyc_upload_rejects_invalid_mime_type()
    {
        $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

        $response = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '12345678',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document_file');
    }

    public function test_kyc_upload_rejects_duplicate_document_type_and_number_with_409()
    {
        $file1 = UploadedFile::fake()->create('dni1.jpg', 300, 'image/jpeg');
        $file2 = UploadedFile::fake()->create('dni2.jpg', 300, 'image/jpeg');

        $res1 = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'passport',
            'document_file' => $file1,
            'document_number' => 'ABC123456',
        ]);
        $res1->assertStatus(201);

        $res2 = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'passport',
            'document_file' => $file2,
            'document_number' => 'ABC123456',
        ]);
        $res2->assertStatus(409);
        $res2->assertJsonFragment([
            'message' => 'Ya existe un documento registrado con este número y tipo para este proveedor.',
        ]);
    }

    public function test_kyc_upload_enforces_maximum_three_documents_per_type()
    {
        for ($i = 1; $i <= 3; $i++) {
            $file = UploadedFile::fake()->create("selfie_{$i}.jpg", 200, 'image/jpeg');
            $res = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
                'document_type' => 'selfie',
                'document_file' => $file,
                'document_number' => "SELFIE{$i}",
            ]);
            $res->assertStatus(201);
        }

        // 4th attempt must fail
        $file4 = UploadedFile::fake()->create("selfie_4.jpg", 200, 'image/jpeg');
        $res4 = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'selfie',
            'document_file' => $file4,
            'document_number' => 'SELFIE4',
        ]);
        $res4->assertStatus(422);
        $res4->assertJsonFragment([
            'message' => 'Límite alcanzado: máximo 3 documentos permitidos para este tipo.',
        ]);
    }

    public function test_kyc_status_progression_unverified_pending_verified_rejected()
    {
        // 1. Initial state -> unverified
        $resUnverified = $this->actingAs($this->user)->getJson('/api/v1/kyc/status');
        $resUnverified->assertStatus(200);
        $resUnverified->assertJsonPath('kyc_status', 'unverified');
        $this->assertEmpty($resUnverified->json('documents'));

        // 2. Upload document -> pending
        $file = UploadedFile::fake()->create('id.pdf', 300, 'application/pdf');
        $resUpload = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '99887766',
        ]);
        $resUpload->assertStatus(201);
        $docUuid = $resUpload->json('document_id');

        $resPending = $this->actingAs($this->user)->getJson('/api/v1/kyc/status');
        $resPending->assertStatus(200);
        $resPending->assertJsonPath('kyc_status', 'pending');
        $this->assertCount(1, $resPending->json('documents'));

        // 3. Admin verifies -> verified
        $resVerify = $this->actingAs($this->admin)->postJson("/api/v1/admin/kyc/documents/{$docUuid}/verify");
        $resVerify->assertStatus(200);

        $resVerified = $this->actingAs($this->user)->getJson('/api/v1/kyc/status');
        $resVerified->assertStatus(200);
        $resVerified->assertJsonPath('kyc_status', 'verified');

        // 4. Admin rejects another document -> rejected
        $file2 = UploadedFile::fake()->create('passport.pdf', 300, 'application/pdf');
        $resUpload2 = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'passport',
            'document_file' => $file2,
            'document_number' => 'PASS999',
        ]);
        $docUuid2 = $resUpload2->json('document_id');

        $resReject = $this->actingAs($this->admin)->postJson("/api/v1/admin/kyc/documents/{$docUuid2}/reject", [
            'reason' => 'Foto ilegible de bordes cortados',
        ]);
        $resReject->assertStatus(200);

        $resRejected = $this->actingAs($this->user)->getJson('/api/v1/kyc/status');
        $resRejected->assertStatus(200);
        $resRejected->assertJsonPath('kyc_status', 'rejected');
        $this->assertCount(1, $resRejected->json('rejection_reasons'));
        $this->assertEquals('Foto ilegible de bordes cortados', $resRejected->json('rejection_reasons.0.reason'));
    }

    public function test_user_cannot_access_another_users_signed_url_403()
    {
        $file = UploadedFile::fake()->create('doc.pdf', 300, 'application/pdf');
        $upload = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '44556677',
        ]);
        $docUuid = $upload->json('document_id');

        // Owner can get signed url
        $resOwner = $this->actingAs($this->user)->getJson("/api/v1/kyc/documents/{$docUuid}/signed-url");
        $resOwner->assertStatus(200);
        $resOwner->assertJsonStructure(['signed_url', 'expires_in_seconds']);

        // Admin can get signed url
        $resAdmin = $this->actingAs($this->admin)->getJson("/api/v1/kyc/documents/{$docUuid}/signed-url");
        $resAdmin->assertStatus(200);

        // Another user gets 403 Forbidden
        $resOther = $this->actingAs($this->otherUser)->getJson("/api/v1/kyc/documents/{$docUuid}/signed-url");
        $resOther->assertStatus(403);
    }

    public function test_admin_can_verify_and_reject_kyc_document_with_audit()
    {
        Log::spy();

        $file = UploadedFile::fake()->create('admin_test.pdf', 300, 'application/pdf');
        $upload = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '11223344',
        ]);
        $docUuid = $upload->json('document_id');

        // Non-admin trying to verify gets 403
        $resNonAdmin = $this->actingAs($this->user)->postJson("/api/v1/admin/kyc/documents/{$docUuid}/verify");
        $resNonAdmin->assertStatus(403);

        // Admin verifies
        $resAdmin = $this->actingAs($this->admin)->postJson("/api/v1/admin/kyc/documents/{$docUuid}/verify");
        $resAdmin->assertStatus(200);
        $this->assertEquals('verified', $resAdmin->json('data.status'));

        Log::shouldHaveReceived('info')
            ->atLeast()->once();
    }

    public function test_kyc_document_deletion_moves_to_quarantine_and_audits()
    {
        $file = UploadedFile::fake()->create('to_delete.pdf', 300, 'application/pdf');
        $upload = $this->actingAs($this->user)->postJson('/api/v1/kyc/documents', [
            'document_type' => 'identity',
            'document_file' => $file,
            'document_number' => '55667788',
        ]);
        $docUuid = $upload->json('document_id');

        // Other user cannot delete -> 403
        $resOther = $this->actingAs($this->otherUser)->deleteJson("/api/v1/kyc/documents/{$docUuid}");
        $resOther->assertStatus(403);

        // Owner deletes -> 200 and document removed from DB
        $resDelete = $this->actingAs($this->user)->deleteJson("/api/v1/kyc/documents/{$docUuid}");
        $resDelete->assertStatus(200);

        $this->assertDatabaseMissing('provider_documents', ['uuid' => $docUuid]);
    }
}
