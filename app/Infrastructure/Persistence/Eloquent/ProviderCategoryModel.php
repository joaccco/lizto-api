<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class ProviderCategoryModel extends Model
{
    protected $table = 'provider_categories';
    protected $fillable = ['provider_id','category_id','specialties','price_type','price_from','price_to','is_active'];
    protected function casts(): array { return ['specialties' => 'array', 'is_active' => 'boolean']; }
    public function provider() { return $this->belongsTo(ProviderProfileModel::class, 'provider_id'); }
    public function category() { return $this->belongsTo(CategoryModel::class, 'category_id'); }
}