<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ServiceBriefModel extends Model
{
    protected $table = 'service_briefs';

    protected $fillable = [
        'service_request_id',
        'summary',
        'attributes',
        'is_confirmed',
        'confirmed_at',
    ];

    protected $casts = [
        'attributes' => 'json',
        'is_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }
}
