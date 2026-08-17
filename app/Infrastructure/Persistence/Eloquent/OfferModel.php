<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Offers\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Model;

class OfferModel extends Model
{
    protected $table = 'offers';

    protected $fillable = [
        'uuid',
        'service_request_id',
        'provider_id',
        'status',
        'rejection_reason',
        'pricing_mode',
        'proposed_price',
        'currency_code',
        'proposed_start_at',
        'estimated_duration_min',
        'round_number',
    ];

    protected $casts = [
        'status' => OfferStatus::class,
        'proposed_price' => 'decimal:2',
        'proposed_start_at' => 'datetime',
        'estimated_duration_min' => 'integer',
        'round_number' => 'integer',
    ];

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }

    public function questions()
    {
        return $this->hasMany(OfferQuestionModel::class, 'offer_id');
    }

    public function conversation()
    {
        return $this->hasOne(ConversationModel::class, 'offer_id');
    }
}
