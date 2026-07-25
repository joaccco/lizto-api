<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Domain\ServiceRequests\Enums\RequestUrgency;
use Illuminate\Database\Eloquent\Model;

class ServiceRequestModel extends Model
{
    protected $table = 'service_requests';
    protected $fillable = ['uuid','client_id','category_id','raw_prompt','parsed_intent','structured_data','location_lat','location_lng','location_address','is_remote','urgency','preferred_datetime','status','expires_at'];
    protected function casts(): array { return ['parsed_intent' => 'array', 'structured_data' => 'array', 'is_remote' => 'boolean', 'urgency' => RequestUrgency::class, 'status' => RequestStatus::class, 'preferred_datetime' => 'datetime', 'expires_at' => 'datetime']; }
    public function client() { return $this->belongsTo(UserModel::class, 'client_id'); }
    public function category() { return $this->belongsTo(CategoryModel::class, 'category_id'); }
    public function surveyResponses() { return $this->hasMany(SurveyResponseModel::class, 'service_request_id'); }
    public function matchSession() { return $this->hasOne(MatchSessionModel::class, 'service_request_id'); }
    public function works() { return $this->hasMany(WorkModel::class, 'service_request_id'); }
}