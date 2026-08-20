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
        'file_path',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
        ];
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }
}
