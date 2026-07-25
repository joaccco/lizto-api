<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class SurveyQuestionModel extends Model
{
    protected $table = 'survey_questions';
    protected $fillable = ['category_id','question_key','question_text','input_type','options','is_required','sort_order','condition'];
    protected function casts(): array { return ['options' => 'array', 'condition' => 'array', 'is_required' => 'boolean']; }
    public function category() { return $this->belongsTo(CategoryModel::class, 'category_id'); }
}