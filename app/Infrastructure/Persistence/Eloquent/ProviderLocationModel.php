<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Location\Services\LocationPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderLocationModel extends Model
{
    protected $table = 'provider_locations';

    protected $fillable = [
        'provider_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'heading',
        'speed_kmh',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_meters' => 'integer',
        'heading' => 'integer',
        'speed_kmh' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }

    public function scopeLatestForProvider($query, int $providerId)
    {
        return $query->where('provider_id', $providerId)->orderByDesc('created_at');
    }

    public function getApproximateZoneAttribute(): string
    {
        $zone = LocationPresenter::getApproximateZone(null, $this->latitude, $this->longitude);
        return $zone['name'] ?? 'Zona Urbana';
    }
}
