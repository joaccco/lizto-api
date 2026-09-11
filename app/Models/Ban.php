<?php

namespace App\Models;

use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ban extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'bans';

    protected $fillable = [
        'user_id',
        'reason',
        'evidence',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'created_by');
    }
}
