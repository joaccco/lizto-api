<?php

namespace App\Models;

use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalMVU extends Model
{
    use HasFactory;

    protected $table = 'professional_mvus';

    protected $fillable = [
        'provider_id',
        'identity_id',
        'identity_verified_at',
        'antecedentes_cert_uploaded_at',
        'antecedentes_cert_url',
        'antecedentes_status',
        'matrícula_number',
        'matrícula_verified_at',
        'skills_verified',
        'skills_verified_by',
        'overall_verification_status',
    ];

    protected $casts = [
        'matrícula_number' => 'encrypted',
        'identity_verified_at' => 'datetime',
        'antecedentes_cert_uploaded_at' => 'datetime',
        'matrícula_verified_at' => 'datetime',
        'skills_verified' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class, 'identity_id');
    }

    public function skillsVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'skills_verified_by');
    }

    public function scopeApproved($query)
    {
        return $query->where('overall_verification_status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('overall_verification_status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('overall_verification_status', 'rejected');
    }
}
