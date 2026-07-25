<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ProviderScheduleModel extends Model
{
    protected $table = 'provider_schedules';
    protected $fillable = ['provider_id','day_of_week','start_time','end_time','is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
    public function provider() { return $this->belongsTo(ProviderProfileModel::class, 'provider_id'); }
}