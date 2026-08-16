<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Clarification\Enums\QuestionnaireVersionStatus;
use Illuminate\Database\Eloquent\Model;

class QuestionnaireVersionModel extends Model
{
    protected $table = 'questionnaire_versions';

    protected $fillable = [
        'service_type_id',
        'version_number',
        'status',
        'published_at',
    ];

    protected $casts = [
        'status' => QuestionnaireVersionStatus::class,
        'published_at' => 'datetime',
    ];

    public function serviceType()
    {
        return $this->belongsTo(ServiceTypeModel::class, 'service_type_id');
    }

    public function questions()
    {
        return $this->hasMany(QuestionModel::class, 'questionnaire_version_id')->orderBy('position');
    }
}
