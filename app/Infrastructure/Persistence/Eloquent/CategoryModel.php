<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class CategoryModel extends Model
{
    protected $table = 'categories';
    protected $fillable = ['parent_id','name','slug','icon','is_active','sort_order'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function parent() { return $this->belongsTo(CategoryModel::class, 'parent_id'); }
    public function children() { return $this->hasMany(CategoryModel::class, 'parent_id'); }
    public function surveyQuestions() { return $this->hasMany(SurveyQuestionModel::class, 'category_id'); }
}