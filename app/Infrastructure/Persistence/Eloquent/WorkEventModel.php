<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class WorkEventModel extends Model
{
    protected $table = 'work_events';
    public $timestamps = false;
    protected $dates = ['created_at'];
    protected $fillable = ['work_id','event_type','actor_id','metadata'];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function work() { return $this->belongsTo(WorkModel::class, 'work_id'); }
    public function actor() { return $this->belongsTo(UserModel::class, 'actor_id'); }
}