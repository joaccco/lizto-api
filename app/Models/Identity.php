<?php

namespace App\Models;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Identity extends Model
{
    use HasFactory;

    protected $table = 'identities';

    protected $fillable = [
        'user_id',
        'dni',
        'firstname',
        'lastname',
        'birthdate',
        'face_template',
        'face_photo_hash',
        'document_front_url',
        'selfie_url',
        'verified_at',
        'verified_by',
        'status',
        'rejection_reason',
        'didit_kyc_response_id',
        'expires_at',
    ];

    protected $casts = [
        'dni' => 'encrypted',
        'face_template' => 'encrypted',
        'birthdate' => 'encrypted',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    public function professionalMvu(): HasOne
    {
        return $this->hasOne(ProfessionalMVU::class, 'identity_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')
                     ->whereNotNull('verified_at');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}
