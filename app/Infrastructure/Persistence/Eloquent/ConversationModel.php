<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ConversationModel extends Model
{
    protected $table = 'conversations';

    protected $fillable = [
        'uuid',
        'work_id',
        'service_request_id',
        'client_id',
        'provider_id',
        'offer_id',
    ];

    public function work()
    {
        return $this->belongsTo(WorkModel::class, 'work_id');
    }

    public function serviceRequest()
    {
        return $this->belongsTo(ServiceRequestModel::class, 'service_request_id');
    }

    public function client()
    {
        return $this->belongsTo(UserModel::class, 'client_id');
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }

    public function offer()
    {
        return $this->belongsTo(OfferModel::class, 'offer_id');
    }

    public function messages()
    {
        return $this->hasMany(MessageModel::class, 'conversation_id')->orderBy('created_at', 'asc');
    }
}
