<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Works\Enums\WorkStatus;
use Illuminate\Database\Eloquent\Model;

class WorkModel extends Model
{
    protected $table = 'works';

    // Note: agreed_price and is_legacy_pre_quote are explicitly excluded from fillable
    // to prevent accidental mass assignment. agreed_price is derived solely from an accepted WorkQuote.
    protected $fillable = [
        'uuid',
        'service_request_id',
        'match_card_id',
        'client_id',
        'provider_id',
        'status',
        'scheduled_at',
        'scheduled_ends_at',
        'started_at',
        'completed_at',
        'estimated_duration_min',
        'estimated_completion_at',
        'work_lat',
        'work_lng',
        'work_address',
        'final_price',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkStatus::class,
            'scheduled_at' => 'datetime',
            'scheduled_ends_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_completion_at' => 'datetime',
            'final_price' => 'decimal:2',
            'agreed_price' => 'decimal:2',
            'is_legacy_pre_quote' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WorkModel $work) {
            if ($work->scheduled_at !== null) {
                $duration = $work->estimated_duration_min ?? 60;
                $work->scheduled_ends_at = \Carbon\Carbon::parse($work->scheduled_at)->addMinutes((int) $duration);
            } else {
                $work->scheduled_ends_at = null;
            }
        });
    }

    public function serviceRequest() { return $this->belongsTo(ServiceRequestModel::class, 'service_request_id'); }
    public function matchCard() { return $this->belongsTo(MatchCardModel::class, 'match_card_id'); }
    public function client() { return $this->belongsTo(UserModel::class, 'client_id'); }
    public function provider() { return $this->belongsTo(ProviderProfileModel::class, 'provider_id'); }
    public function conversation() { return $this->hasOne(ConversationModel::class, 'work_id'); }
    public function events() { return $this->hasMany(WorkEventModel::class, 'work_id'); }
    public function ratings() { return $this->hasMany(RatingModel::class, 'work_id'); }
    public function quotes() { return $this->hasMany(WorkQuoteModel::class, 'work_id'); }
    public function acceptedQuote() { return $this->hasOne(WorkQuoteModel::class, 'work_id')->where('status', 'accepted'); }

    public function applyAcceptedQuote(WorkQuoteModel $quote): void
    {
        $this->agreed_price = $quote->amount;
        $this->save();
    }

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

        if (in_array($targetEnum, [WorkStatus::Confirmed, WorkStatus::InProgress], true) && $this->scheduled_at !== null) {
            app(\App\Application\Works\Services\WorkScheduleValidator::class)->validateNoOverlap(
                $this->provider_id,
                $this->scheduled_at,
                $this->estimated_duration_min,
                $this->id
            );
        }

        $this->update([
            'status' => $targetEnum,
            'completed_at' => $targetEnum === WorkStatus::Completed ? ($this->completed_at ?? now()) : $this->completed_at,
        ]);
    }
}