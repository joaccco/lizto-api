<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ProviderPortfolioItemModel extends Model
{
    protected $table = 'provider_portfolio_items';

    protected $fillable = [
        'uuid',
        'provider_id',
        'title',
        'description',
        'media_urls',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function provider()
    {
        return $this->belongsTo(ProviderProfileModel::class, 'provider_id');
    }
}
