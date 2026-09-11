<?php

namespace App\Console\Commands;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\ProfessionalMVU;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateProviderIdentities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:provider-identities {--dry-run : Simulate the migration without modifying the database} {--execute : Execute the database migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate existing provider profiles to decoupled Identity and ProfessionalMVU architecture';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run') || !$this->option('execute');

        if ($isDryRun) {
            $this->info('Running in DRY-RUN mode. No database changes will be committed.');
        } else {
            $this->warn('Executing migration in LIVE mode.');
        }

        $providers = ProviderProfileModel::where('is_migrated', false)->get();
        $total = $providers->count();

        $this->info("Found {$total} provider profiles to migrate.");

        if ($total === 0) {
            $this->info('No profiles pending migration.');
            return Command::SUCCESS;
        }

        $migratedCount = 0;
        $legacyVerifiedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($providers as $provider) {
                $isLegacyVerified = (bool) ($provider->is_verified || $provider->status?->value === 'verified');

                $identityStatus = $isLegacyVerified ? 'approved' : 'pending';
                $mvuStatus = $isLegacyVerified ? 'approved' : 'pending';

                $this->line("Processing Provider #{$provider->id} (User: {$provider->user_id}, Verified: " . ($isLegacyVerified ? 'YES' : 'NO') . ")");

                if (!$isDryRun) {
                    // Create or update Identity
                    $identity = Identity::firstOrNew(['user_id' => $provider->user_id]);
                    if (!$identity->exists || $identity->status !== 'approved') {
                        $identity->firstname = $provider->first_name;
                        $identity->lastname = $provider->last_name;
                        $identity->status = $identityStatus;
                        $identity->verified_at = $isLegacyVerified ? ($provider->verified_at ?? now()) : null;
                        $identity->verified_by = $isLegacyVerified ? ($provider->verified_by ? (string) $provider->verified_by : 'legacy_migration') : null;
                        $identity->save();
                    }

                    // Create or update ProfessionalMVU
                    $mvu = ProfessionalMVU::firstOrNew(['provider_id' => $provider->id]);
                    $mvu->identity_id = $identity->id;
                    $mvu->overall_verification_status = $mvuStatus;
                    $mvu->skills_verified = $isLegacyVerified;
                    $mvu->skills_verified_by = $isLegacyVerified ? $provider->verified_by : null;
                    if ($isLegacyVerified) {
                        $mvu->identity_verified_at = $provider->verified_at ?? now();
                        $mvu->antecedentes_status = 'approved';
                    }
                    $mvu->save();

                    // Update ProviderProfile
                    $provider->update([
                        'is_migrated' => true,
                        'migrated_at' => now(),
                    ]);

                    AuditLog::create([
                        'event' => 'provider_identity_migrated',
                        'subject_type' => 'ProviderProfile',
                        'subject_id' => $provider->id,
                        'user_id' => $provider->user_id,
                        'action' => 'migrate',
                        'new_values' => [
                            'identity_id' => $identity->id,
                            'mvu_id' => $mvu->id,
                            'status' => $identityStatus,
                        ],
                        'created_at' => now(),
                    ]);
                }

                $migratedCount++;
                if ($isLegacyVerified) {
                    $legacyVerifiedCount++;
                }
            }

            if ($isDryRun) {
                DB::rollBack();
                $this->info("DRY-RUN completed. {$migratedCount} profiles would be migrated ({$legacyVerifiedCount} preserved as legacy-verified).");
            } else {
                DB::commit();
                $this->info("Migration completed successfully! {$migratedCount} profiles migrated ({$legacyVerifiedCount} preserved as legacy-verified).");
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Migration failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
