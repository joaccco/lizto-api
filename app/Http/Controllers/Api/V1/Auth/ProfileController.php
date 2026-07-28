<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function me(): JsonResponse
    {
        $user = auth()->user()->load('providerProfile');

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }
}
