<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Providers\Enums\AvailabilityStatus;
use Illuminate\Database\Eloquent\Model;

class ProviderProfileModel extends Model
{
    protected $table = 'provider_profiles';

    protected $fillable = ['user_id','bio','years_experience','is_verified','verification_docs','avg_rating','total_reviews','total_jobs_completed','completion_rate','cancellation_count','response_rate','avg_response_minutes','base_lat','base_lng','base_address','availability_status','busy_until','next_available_at','current_job_lat','current_job_lng'];

    protected function casts(): array
    {
        return [
            'verification_docs' => 'array',
            'is_verified' => 'boolean',
            'avg_rating' => 'decimal:2',
            'completion_rate' => 'decimal:2',
            'response_rate' => 'decimal:2',
            'availability_status' => AvailabilityStatus::class,
            'busy_until' => 'datetime',
            'next_available_at' => 'datetime',
        ];
    }

    public function user() { return $this->belongsTo(UserModel::class, 'user_id'); }
    public function categories() { return $this->hasMany(ProviderCategoryModel::class, 'provider_id'); }
    public function serviceAreas() { return $this->hasMany(ProviderServiceAreaModel::class, 'provider_id'); }
    public function schedules() { return $this->hasMany(ProviderScheduleModel::class, 'provider_id'); }
    public function matchCards() { return $this->hasMany(MatchCardModel::class, 'provider_id'); }
    public function scopeAvailable($query) { return $query->where('availability_status', 'available'); }
    public function scopeAvailableSoon($query) { return $query->where('availability_status', 'busy')->where('next_available_at', '<=', now()->addMinutes(60)); }
    public function scopeWithinRadius($query, $lat, $lng, $radiusKm)
    {
        return $query->whereRaw('(6371 * acos(cos(radians(?)) * cos(radians(base_lat)) * cos(radians(base_lng) - radians(?)) + sin(radians(?)) * sin(radians(base_lat)))) <= ?', [$lat, $lng, $lat, $radiusKm]);
    }
}