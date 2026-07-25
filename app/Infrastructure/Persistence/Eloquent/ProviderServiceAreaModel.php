<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ProviderServiceAreaModel extends Model
{
    protected $table = 'provider_service_areas';
    protected $fillable = ['provider_id','center_lat','center_lng','radius_km','label'];
    public function provider() { return $this->belongsTo(ProviderProfileModel::class, 'provider_id'); }
}