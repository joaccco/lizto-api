<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class QuestionOptionModel extends Model
{
    protected $table = 'question_options';

    protected $fillable = [
        'question_id',
        'label',
        'value',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function question()
    {
        return $this->belongsTo(QuestionModel::class, 'question_id');
    }
}
