<?php

namespace App\Http\Controllers\Api\V1\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;

class CategoryController extends Controller
{
    /**
     * Display a listing of active categories with nested subcategories.
     */
    public function index()
    {
        $categories = CategoryModel::whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => function ($query) {
                $query->where('is_active', true)->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }
}
