<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ServiceTypeModel extends Model
{
    protected $table = 'service_types';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'always_requires_evaluation',
        'requires_onsite_diagnosis',
        'current_version_id',
    ];

    protected $casts = [
        'always_requires_evaluation' => 'boolean',
        'requires_onsite_diagnosis' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(CategoryModel::class, 'category_id');
    }

    public function versions()
    {
        return $this->hasMany(QuestionnaireVersionModel::class, 'service_type_id');
    }

    public function currentVersion()
    {
        return $this->belongsTo(QuestionnaireVersionModel::class, 'current_version_id');
    }
}
