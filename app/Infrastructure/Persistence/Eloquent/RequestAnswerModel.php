<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Clarification\Enums\AnswerSource;
use Illuminate\Database\Eloquent\Model;

class RequestAnswerModel extends Model
{
    protected $table = 'request_answers';

    protected $fillable = [
        'service_request_id',
        'question_id',
        'question_key',
        'answer_value',
        'source',
        'ai_confidence',
        'confirmed_by_user',
    ];

    protected $casts = [
        'answer_value' => 'json',
        'source' => AnswerSource::class,
        'ai_confidence' => 'float',
        'confirmed_by_user' => 'boolean',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }

    public function question()
    {
        return $this->belongsTo(QuestionModel::class, 'question_id');
    }
}
