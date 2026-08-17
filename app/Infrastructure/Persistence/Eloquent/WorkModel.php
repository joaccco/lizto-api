<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Works\Enums\WorkStatus;
use Illuminate\Database\Eloquent\Model;

class WorkModel extends Model
{
    protected $table = 'works';
    protected $fillable = ['uuid','service_request_id','match_card_id','client_id','provider_id','status','scheduled_at','started_at','completed_at','estimated_duration_min','estimated_completion_at','work_lat','work_lng','work_address','agreed_price','final_price','currency'];
    protected function casts(): array { return ['status' => WorkStatus::class, 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'estimated_completion_at' => 'datetime', 'final_price' => 'decimal:2', 'agreed_price' => 'decimal:2']; }
    public function serviceRequest() { return $this->belongsTo(ServiceRequestModel::class, 'service_request_id'); }
    public function matchCard() { return $this->belongsTo(MatchCardModel::class, 'match_card_id'); }
    public function client() { return $this->belongsTo(UserModel::class, 'client_id'); }
    public function provider() { return $this->belongsTo(ProviderProfileModel::class, 'provider_id'); }
    public function conversation() { return $this->hasOne(ConversationModel::class, 'work_id'); }
    public function events() { return $this->hasMany(WorkEventModel::class, 'work_id'); }
    public function ratings() { return $this->hasMany(RatingModel::class, 'work_id'); }

    public function transitionTo(WorkStatus|string $targetStatus): void
    {
        $targetEnum = is_string($targetStatus) ? WorkStatus::from($targetStatus) : $targetStatus;
        $currentEnum = $this->status instanceof WorkStatus ? $this->status : WorkStatus::from($this->status);

        if ($currentEnum === $targetEnum) {
            return;
        }

        if (!$currentEnum->canTransitionTo($targetEnum)) {
            throw new \App\Domain\Shared\Exceptions\InvalidStateTransitionException(
                "No se puede cambiar el estado de {$currentEnum->value} a {$targetEnum->value}."
            );
        }

        $this->update([
            'status' => $targetEnum,
            'completed_at' => $targetEnum === WorkStatus::Completed ? ($this->completed_at ?? now()) : $this->completed_at,
        ]);
    }
}