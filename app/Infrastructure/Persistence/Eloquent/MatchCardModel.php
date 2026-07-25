<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Matching\Enums\CardStatus;
use Illuminate\Database\Eloquent\Model;

class MatchCardModel extends Model
{
    protected $table = 'match_cards';
    protected $fillable = ['match_session_id','provider_id','rank_position','score_total','score_breakdown','snapshot','card_status','shown_at','decided_at'];
    protected function casts(): array { return ['score_breakdown' => 'array', 'snapshot' => 'array', 'card_status' => CardStatus::class, 'shown_at' => 'datetime', 'decided_at' => 'datetime']; }
    public function matchSession() { return $this->belongsTo(MatchSessionModel::class, 'match_session_id'); }
    public function provider() { return $this->belongsTo(ProviderProfileModel::class, 'provider_id'); }
    public function scopePending($query) { return $query->where('card_status', 'pending'); }
    public function scopeShown($query) { return $query->where('card_status', 'shown'); }
    public function scopeRejected($query) { return $query->where('card_status', 'rejected'); }
}