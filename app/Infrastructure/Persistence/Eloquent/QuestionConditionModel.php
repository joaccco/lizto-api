<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class QuestionConditionModel extends Model
{
    protected $table = 'question_conditions';

    protected $fillable = [
        'question_id',
        'depends_on_question_id',
        'depends_on_question_key',
        'operator',
        'expected_value',
    ];

    protected $casts = [
        'expected_value' => 'json',
    ];

    public function question()
    {
        return $this->belongsTo(QuestionModel::class, 'question_id');
    }

    public function dependsOnQuestion()
    {
        return $this->belongsTo(QuestionModel::class, 'depends_on_question_id');
    }
}
