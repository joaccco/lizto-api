<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class WorkQuoteModel extends Model
{
    protected $table = 'work_quotes';

    protected $fillable = [
        'uuid',
        'work_id',
        'provider_id',
        'client_id',
        'amount',
        'currency',
        'breakdown_items',
        'estimated_hours',
        'valid_until',
        'terms_conditions',
        'origin',
        'status',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'breakdown_items' => 'array',
            'estimated_hours' => 'integer',
            'valid_until' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function work()
    {
        return $this->belongsTo(WorkModel::class, 'work_id');
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }

    public function client()
    {
        return $this->belongsTo(UserModel::class, 'client_id');
    }
}
