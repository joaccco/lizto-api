<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class RatingModel extends Model
{
    protected $table = 'ratings';
    public $timestamps = false;
    protected $dates = ['created_at'];
    protected $fillable = ['work_id','reviewer_id','reviewed_id','direction','score','comment'];
    public function work() { return $this->belongsTo(WorkModel::class, 'work_id'); }
    public function reviewer() { return $this->belongsTo(UserModel::class, 'reviewer_id'); }
    public function reviewed() { return $this->belongsTo(UserModel::class, 'reviewed_id'); }
}