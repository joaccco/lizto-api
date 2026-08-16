<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class OfferQuestionModel extends Model
{
    protected $table = 'offer_questions';

    protected $fillable = [
        'offer_id',
        'question_key',
        'question_text',
        'answer',
        'answered_at',
    ];

    protected $casts = [
        'answered_at' => 'datetime',
    ];

    public function offer()
    {
        return $this->belongsTo(OfferModel::class, 'offer_id');
    }
}
