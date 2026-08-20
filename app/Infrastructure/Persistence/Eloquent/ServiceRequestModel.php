<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\ServiceRequests\Enums\RequestStatus;
use App\Domain\ServiceRequests\Enums\RequestUrgency;
use Illuminate\Database\Eloquent\Model;

class ServiceRequestModel extends Model
{
    protected $table = 'service_requests';

    protected $fillable = [
        'uuid',
        'client_id',
        'category_id',
        'service_type_id',
        'questionnaire_version_id',
        'raw_prompt',
        'original_prompt',
        'parsed_intent',
        'structured_data',
        'location_lat',
        'location_lng',
        'location_address',
        'is_remote',
        'urgency',
        'preferred_datetime',
        'scheduled_date',
        'window_start',
        'window_end',
        'status',
        'quote_readiness',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_intent' => 'array',
            'structured_data' => 'array',
            'is_remote' => 'boolean',
            'urgency' => RequestUrgency::class,
            'status' => RequestStatus::class,
            'quote_readiness' => \App\Domain\Clarification\Enums\QuoteReadiness::class,
            'preferred_datetime' => 'datetime',
            'scheduled_date' => 'date',
            'expires_at' => 'datetime',
        ];
    }

    public function client() { return $this->belongsTo(UserModel::class, 'client_id'); }
    public function category() { return $this->belongsTo(CategoryModel::class, 'category_id'); }
    public function serviceType() { return $this->belongsTo(ServiceTypeModel::class, 'service_type_id'); }
    public function questionnaireVersion() { return $this->belongsTo(QuestionnaireVersionModel::class, 'questionnaire_version_id'); }
    public function answers() { return $this->hasMany(RequestAnswerModel::class, 'service_request_id'); }
    public function brief() { return $this->hasOne(ServiceBriefModel::class, 'service_request_id'); }
    public function surveyResponses() { return $this->hasMany(SurveyResponseModel::class, 'service_request_id'); }
    public function matchSession() { return $this->hasOne(MatchSessionModel::class, 'service_request_id'); }
    public function works() { return $this->hasMany(WorkModel::class, 'service_request_id'); }

    public function getFormattedScheduleAttribute(): string
    {
        $urgencyVal = $this->urgency instanceof \BackedEnum ? $this->urgency->value : $this->urgency;

        if ($urgencyVal === 'immediate') {
            return 'Necesita atención ahora mismo';
        }

        if ($urgencyVal === 'today') {
            $window = ($this->window_start && $this->window_end)
                ? " · entre {$this->window_start} y {$this->window_end}"
                : "";
            return "Hoy{$window}";
        }

        if ($urgencyVal === 'scheduled') {
            $dateStr = $this->scheduled_date
                ? $this->scheduled_date->format('d/m/Y')
                : ($this->preferred_datetime ? $this->preferred_datetime->format('d/m/Y') : 'Fecha a coordinar');

            $window = ($this->window_start && $this->window_end)
                ? " · entre {$this->window_start} y {$this->window_end}"
                : "";

            return "{$dateStr}{$window}";
        }

        return 'Horario flexible';
    }

    public function transitionTo(RequestStatus|string $targetStatus): void
    {
        $targetEnum = is_string($targetStatus) ? RequestStatus::from($targetStatus) : $targetStatus;
        $currentEnum = $this->status instanceof RequestStatus ? $this->status : RequestStatus::from($this->status);

        if ($currentEnum === $targetEnum) {
            return;
        }

        if (!$currentEnum->canTransitionTo($targetEnum)) {
            throw new \App\Domain\Shared\Exceptions\InvalidStateTransitionException(
                "No se puede cambiar el estado de {$currentEnum->value} a {$targetEnum->value}."
            );
        }

        $this->update(['status' => $targetEnum]);
    }
}