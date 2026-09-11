<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Users\Enums\UserStatus;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class UserModel extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;

    protected $table = 'users';

    protected $fillable = ['uuid', 'name', 'email', 'phone', 'password', 'avatar_url', 'status'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'password' => 'hashed',
        ];
    }

    public function providerProfile()
    {
        return $this->hasOne(ProviderProfileModel::class, 'user_id');
    }

    public function serviceRequests()
    {
        return $this->hasMany(ServiceRequestModel::class, 'client_id');
    }

    public function devices()
    {
        return $this->hasMany(UserDeviceModel::class, 'user_id');
    }

    public function activeDevices()
    {
        return $this->hasMany(UserDeviceModel::class, 'user_id')->whereNull('revoked_at');
    }

    public function identity()
    {
        return $this->hasOne(\App\Models\Identity::class, 'user_id');
    }

    public function bans()
    {
        return $this->hasMany(\App\Models\Ban::class, 'user_id');
    }

    public function restrictions()
    {
        return $this->hasMany(\App\Models\Restriction::class, 'user_id');
    }
}