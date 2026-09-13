<?php

namespace App\Domain\Trust\Services;

use App\Domain\Providers\Enums\AvailabilityStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Domain\Users\Enums\UserStatus;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Restriction;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class BanService
{
    /**
     * Issue a permanent or severity-based ban against a user.
     */
    public function ban(UserModel $user, string $reason, ?string $evidence = null, ?int $createdBy = null): Ban
    {
        return DB::transaction(function () use ($user, $reason, $evidence, $createdBy) {
            $ban = Ban::create([
                'user_id' => $user->id,
                'reason' => $reason,
                'evidence' => $evidence,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);

            $user->update(['status' => UserStatus::Suspended]);

            if ($user->providerProfile) {
                $user->providerProfile->update([
                    'status' => ProviderProfileStatus::Suspended,
                    'availability_status' => AvailabilityStatus::Unavailable,
                    'suspension_reason' => $reason,
                    'suspended_at' => now(),
                ]);
            }

            AuditLog::create([
                'event' => 'user_banned',
                'subject_type' => 'Ban',
                'subject_id' => $ban->id,
                'user_id' => $user->id,
                'action' => 'ban',
                'new_values' => [
                    'reason' => $reason,
                    'created_by' => $createdBy,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $ban;
        });
    }

    /**
     * Issue a temporary action restriction against a user.
     */
    public function restrict(
        UserModel $user,
        string $type,
        string $reason,
        DateTimeInterface $expiresAt,
        ?int $createdBy = null
    ): Restriction {
        return DB::transaction(function () use ($user, $type, $reason, $expiresAt, $createdBy) {
            $restriction = Restriction::create([
                'user_id' => $user->id,
                'type' => $type,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
                'created_at' => now(),
            ]);

            AuditLog::create([
                'event' => 'user_restricted',
                'subject_type' => 'Restriction',
                'subject_id' => $restriction->id,
                'user_id' => $user->id,
                'action' => 'restrict',
                'new_values' => [
                    'type' => $type,
                    'reason' => $reason,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $restriction;
        });
    }

    /**
     * Check if a user is currently banned.
     */
    public function isBanned(UserModel $user): bool
    {
        if ($user->status === UserStatus::Suspended) {
            return true;
        }

        return Ban::where('user_id', $user->id)->exists();
    }

    /**
     * Check if user has an active restriction of given type.
     */
    public function hasActiveRestriction(UserModel $user, string $type): bool
    {
        return Restriction::where('user_id', $user->id)
            ->where('type', $type)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
