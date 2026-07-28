<?php

namespace App\Http\Controllers\Api\V1\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderDetailResource;
use App\Http\Resources\ProviderResource;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    /**
     * Display a listing of service providers.
     */
    public function index(Request $request)
    {
        $query = ProviderProfileModel::query()->with(['user', 'categories.category']);

        // Filter by category slug
        if ($request->filled('category')) {
            $categorySlug = $request->input('category');
            $query->whereHas('categories.category', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        }

        // Filter by availability status
        if ($request->filled('availability')) {
            $availability = $request->input('availability');
            $query->where('availability_status', $availability);
        }

        // Default sorting by avg_rating desc
        $query->orderByDesc('avg_rating');

        $providers = $query->paginate(15);

        return ProviderResource::collection($providers);
    }

    /**
     * Display full profile of a specific provider by user UUID.
     */
    public function show(string $uuid)
    {
        $user = UserModel::where('uuid', $uuid)->firstOrFail();

        $provider = ProviderProfileModel::where('user_id', $user->id)
            ->with(['user', 'categories.category', 'serviceAreas', 'schedules'])
            ->firstOrFail();

        return new ProviderDetailResource($provider);
    }
}
