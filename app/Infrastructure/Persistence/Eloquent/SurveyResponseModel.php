<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class SurveyResponseModel extends Model
{
    protected $table = 'survey_responses';
    protected $fillable = ['service_request_id','question_id','question_key','question_text','answer_value','is_ai_generated'];
    protected function casts(): array { return ['answer_value' => 'array', 'is_ai_generated' => 'boolean']; }
    public function serviceRequest() { return $this->belongsTo(ServiceRequestModel::class, 'service_request_id'); }
    public function question() { return $this->belongsTo(SurveyQuestionModel::class, 'question_id'); }
}