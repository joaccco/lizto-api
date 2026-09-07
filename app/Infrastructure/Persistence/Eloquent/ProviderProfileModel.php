<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Providers\Enums\AvailabilityStatus;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use Illuminate\Database\Eloquent\Model;

class ProviderProfileModel extends Model
{
    public const MIN_COVERAGE_RADIUS_KM = 1;
    public const MAX_COVERAGE_RADIUS_KM = 50;

    protected $table = 'provider_profiles';

    protected $fillable = [
        'uuid',
        'user_id',
        'status',
        'first_name',
        'last_name',
        'commercial_name',
        'bio',
        'years_experience',
        'is_verified',
        'verification_docs',
        'submitted_at',
        'verified_at',
        'verified_by',
        'rejected_at',
        'rejection_reason',
        'suspended_at',
        'suspension_reason',
        'avg_rating',
        'total_reviews',
        'total_jobs_completed',
        'completion_rate',
        'cancellation_count',
        'response_rate',
        'avg_response_minutes',
        'base_lat',
        'base_lng',
        'base_address',
        'availability_status',
        'busy_until',
        'next_available_at',
        'current_job_lat',
        'current_job_lng',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderProfileStatus::class,
            'verification_docs' => 'array',
            'is_verified' => 'boolean',
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'suspended_at' => 'datetime',
            'avg_rating' => 'decimal:2',
            'completion_rate' => 'decimal:2',
            'response_rate' => 'decimal:2',
            'availability_status' => AvailabilityStatus::class,
            'busy_until' => 'datetime',
            'next_available_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(UserModel::class, 'verified_by');
    }

    public function categories()
    {
        return $this->hasMany(ProviderCategoryModel::class, 'provider_id');
    }

    public function serviceAreas()
    {
        return $this->hasMany(ProviderServiceAreaModel::class, 'provider_id');
    }

    public function schedules()
    {
        return $this->hasMany(ProviderScheduleModel::class, 'provider_id');
    }

    public function portfolioItems()
    {
        return $this->hasMany(ProviderPortfolioItemModel::class, 'provider_id')->orderBy('sort_order');
    }

    public function documents()
    {
        return $this->hasMany(ProviderDocumentModel::class, 'provider_id');
    }

    public function matchCards()
    {
        return $this->hasMany(MatchCardModel::class, 'provider_id');
    }

    public function reviews()
    {
        return $this->hasMany(RatingModel::class, 'reviewed_id', 'user_id')->orderBy('created_at', 'desc');
    }

    public function works()
    {
        return $this->hasMany(WorkModel::class, 'provider_id');
    }

    public function locations()
    {
        return $this->hasMany(ProviderLocationModel::class, 'provider_id');
    }

    public function scopeEligibleForMatching($query)
    {
        return $query->where(function ($q) {
            $q->where('status', ProviderProfileStatus::Verified)
              ->orWhere('is_verified', true);
        })->where('availability_status', AvailabilityStatus::Available);
    }

    public function scopeAvailable($query)
    {
        return $query->where('availability_status', 'available');
    }

    public function scopeAvailableSoon($query)
    {
        return $query->where('availability_status', 'busy')->where('next_available_at', '<=', now()->addMinutes(60));
    }

    public function scopeWithinRadius($query, $lat, $lng, $radiusKm)
    {
        return $query->whereRaw('(6371 * acos(cos(radians(?)) * cos(radians(base_lat)) * cos(radians(base_lng) - radians(?)) + sin(radians(?)) * sin(radians(base_lat)))) <= ?', [$lat, $lng, $lat, $radiusKm]);
    }

    /**
     * Calculate profile completion percentage (0 - 100%)
     */
    public function getCompletionPercentageAttribute(): int
    {
        $points = 0;
        $totalPoints = 6;

        if (!empty($this->first_name) || !empty($this->bio)) $points++;
        if (!empty($this->base_address) || (!empty($this->base_lat) && !empty($this->base_lng))) $points++;
        if ($this->categories()->count() > 0) $points++;
        if ($this->serviceAreas()->count() > 0) $points++;
        if ($this->schedules()->count() > 0) $points++;
        if ($this->documents()->count() > 0 || !empty($this->verification_docs)) $points++;

        return (int) round(($points / $totalPoints) * 100);
    }
}