<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class MatchSessionModel extends Model
{
    protected $table = 'match_sessions';
    protected $fillable = ['uuid', 'service_request_id', 'status', 'total_shown'];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }

    public function cards()
    {
        return $this->hasMany(MatchCardModel::class, 'match_session_id');
    }
}