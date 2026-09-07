<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Domain\Providers\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;

class ProviderDocumentModel extends Model
{
    protected $table = 'provider_documents';

    protected $fillable = [
        'uuid',
        'provider_id',
        'document_type',
        'document_number',
        'expiry_date',
        'file_path',
        'status',
        'rejection_reason',
        'verified_at',
        'rejected_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'expiry_date' => 'date',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProviderDocumentModel $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }
}
