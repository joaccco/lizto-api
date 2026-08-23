<?php

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $providerRole = Role::firstOrCreate(['name' => 'provider', 'guard_name' => 'web']);
        $clientRole = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);

        // Find all users who have a verified ProviderProfile or existing profile and assign provider role
        $verifiedUserIds = ProviderProfileModel::where(function ($q) {
            $q->where('status', 'verified')->orWhere('is_verified', true);
        })->pluck('user_id')->unique();

        foreach ($verifiedUserIds as $userId) {
            $user = UserModel::find($userId);
            if ($user && !$user->hasRole('provider')) {
                $user->assignRole($providerRole);
            }
        }
    }

    public function down(): void
    {
        // No-op to avoid revoking valid roles on rollback
    }
};
