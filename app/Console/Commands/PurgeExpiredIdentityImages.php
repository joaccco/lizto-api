<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Identity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PurgeExpiredIdentityImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'identity:purge-expired-images {--days=90 : Retention threshold in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge document images older than 90 days from verification per Ley 25.326, preserving facial template';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0) {
            $days = 90;
        }

        $threshold = Carbon::now()->subDays($days);
        $this->info("Scanning verified identities older than {$days} days (verified on or before {$threshold->toIso8601String()})...");

        $identities = Identity::query()
            ->where('status', 'approved')
            ->whereNotNull('verified_at')
            ->where('verified_at', '<=', $threshold)
            ->where(function ($query) {
                $query->whereNotNull('document_front_url')
                      ->orWhereNotNull('selfie_url');
            })
            ->get();

        $count = 0;

        foreach ($identities as $identity) {
            $oldFront = $identity->document_front_url;
            $oldSelfie = $identity->selfie_url;

            // Idempotency guard: nothing to purge if already null
            if ($oldFront === null && $oldSelfie === null) {
                continue;
            }

            // Purge image URLs, preserving biometric face_template and identity record
            $identity->document_front_url = null;
            $identity->selfie_url = null;
            $identity->save();

            // Record immutable audit log
            AuditLog::create([
                'event' => 'identity_images_purged',
                'subject_type' => 'Identity',
                'subject_id' => $identity->id,
                'user_id' => $identity->user_id,
                'action' => 'purge_expired_images',
                'old_values' => [
                    'document_front_url' => $oldFront,
                    'selfie_url' => $oldSelfie,
                ],
                'new_values' => [
                    'document_front_url' => null,
                    'selfie_url' => null,
                    'retention_policy' => '90_days_ley_25326',
                    'face_template_retained' => true,
                ],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'cli:identity:purge-expired-images',
                'created_at' => Carbon::now(),
            ]);

            $count++;
        }

        $this->info("Successfully purged image references for {$count} identities.");

        return Command::SUCCESS;
    }
}
