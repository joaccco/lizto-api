<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class QuestionModel extends Model
{
    protected $table = 'questions';

    protected $fillable = [
        'questionnaire_version_id',
        'question_key',
        'question_text',
        'input_type',
        'is_required',
        'position',
        'question_group',
        'used_for_matching',
        'used_for_quote',
        'is_critical',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'used_for_matching' => 'boolean',
        'used_for_quote' => 'boolean',
        'is_critical' => 'boolean',
        'position' => 'integer',
    ];

    public function questionnaireVersion()
    {
        return $this->belongsTo(QuestionnaireVersionModel::class, 'questionnaire_version_id');
    }

    public function options()
    {
        return $this->hasMany(QuestionOptionModel::class, 'question_id')->orderBy('position');
    }

    public function conditions()
    {
        return $this->hasMany(QuestionConditionModel::class, 'question_id');
    }
}
