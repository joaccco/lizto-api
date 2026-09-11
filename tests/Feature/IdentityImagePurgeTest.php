<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Identity;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityImagePurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_verified_91_days_ago_has_images_purged_while_retaining_face_template(): void
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario 91 Dias',
            'email' => 'user91@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $identity = Identity::create([
            'user_id' => $user->id,
            'dni' => '12345678',
            'firstname' => 'Juan',
            'lastname' => 'Perez',
            'status' => 'approved',
            'verified_at' => Carbon::now()->subDays(91),
            'verified_by' => 'didit_id',
            'face_template' => '{"embedding": [0.123, 0.456, 0.789]}',
            'face_photo_hash' => 'hash_abc123',
            'document_front_url' => 'https://s3.bucket/docs/dni_front.jpg',
            'selfie_url' => 'https://s3.bucket/docs/selfie.jpg',
        ]);

        $this->artisan('identity:purge-expired-images')
            ->assertSuccessful();

        $identity->refresh();

        // Document images MUST be purged
        $this->assertNull($identity->document_front_url, 'Document front image was not purged at 91 days');
        $this->assertNull($identity->selfie_url, 'Selfie image was not purged at 91 days');

        // Biometric template and audit hash MUST be retained for duplicate prevention
        $this->assertNotNull($identity->face_template, 'Face template was improperly removed');
        $this->assertEquals('{"embedding": [0.123, 0.456, 0.789]}', $identity->face_template);
        $this->assertEquals('hash_abc123', $identity->face_photo_hash);
        $this->assertEquals('Juan', $identity->firstname);

        // Audit trail MUST be recorded
        $audit = AuditLog::where('event', 'identity_images_purged')
            ->where('subject_id', $identity->id)
            ->first();

        $this->assertNotNull($audit, 'Audit log was not recorded for purged identity');
        $this->assertEquals('purge_expired_images', $audit->action);
        $this->assertEquals('https://s3.bucket/docs/dni_front.jpg', $audit->old_values['document_front_url']);
        $this->assertNull($audit->new_values['document_front_url']);
    }

    public function test_identity_verified_89_days_ago_retains_images(): void
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario 89 Dias',
            'email' => 'user89@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $identity = Identity::create([
            'user_id' => $user->id,
            'dni' => '87654321',
            'firstname' => 'Maria',
            'lastname' => 'Gomez',
            'status' => 'approved',
            'verified_at' => Carbon::now()->subDays(89),
            'verified_by' => 'didit_id',
            'face_template' => '{"embedding": [0.987, 0.654]}',
            'document_front_url' => 'https://s3.bucket/docs/dni_maria.jpg',
            'selfie_url' => 'https://s3.bucket/docs/selfie_maria.jpg',
        ]);

        $this->artisan('identity:purge-expired-images')
            ->assertSuccessful();

        $identity->refresh();

        // Images must NOT be purged before 90 days
        $this->assertEquals('https://s3.bucket/docs/dni_maria.jpg', $identity->document_front_url);
        $this->assertEquals('https://s3.bucket/docs/selfie_maria.jpg', $identity->selfie_url);

        // No audit log created for unpurged identity
        $this->assertDatabaseMissing('audit_logs', [
            'subject_id' => $identity->id,
            'event' => 'identity_images_purged',
        ]);
    }

    public function test_purge_command_is_idempotent(): void
    {
        $user = UserModel::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Usuario Idempotente',
            'email' => 'idempotent@test.com',
            'password' => bcrypt('Secret123!'),
            'status' => 'active',
        ]);

        $identity = Identity::create([
            'user_id' => $user->id,
            'dni' => '55443322',
            'firstname' => 'Pedro',
            'lastname' => 'Alonso',
            'status' => 'approved',
            'verified_at' => Carbon::now()->subDays(100),
            'verified_by' => 'didit_id',
            'face_template' => '{"embedding": [0.1, 0.2]}',
            'document_front_url' => 'https://s3.bucket/docs/dni_pedro.jpg',
            'selfie_url' => 'https://s3.bucket/docs/selfie_pedro.jpg',
        ]);

        // First run purges
        $this->artisan('identity:purge-expired-images')->assertSuccessful();
        $this->assertEquals(1, AuditLog::where('subject_id', $identity->id)->count());

        // Second run must be idempotent without creating duplicate logs
        $this->artisan('identity:purge-expired-images')->assertSuccessful();
        $this->assertEquals(1, AuditLog::where('subject_id', $identity->id)->count());

        $identity->refresh();
        $this->assertNull($identity->document_front_url);
        $this->assertNull($identity->selfie_url);
    }

    public function test_purge_command_is_scheduled_daily(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $purgeEvent = $events->first(function ($event) {
            return str_contains($event->command, 'identity:purge-expired-images');
        });

        $this->assertNotNull($purgeEvent, 'identity:purge-expired-images command is not scheduled');
        $this->assertEquals('0 0 * * *', $purgeEvent->expression, 'Command is not scheduled daily');
    }
}
