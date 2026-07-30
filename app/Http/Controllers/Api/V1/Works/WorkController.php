<?php

namespace App\Http\Controllers\Api\V1\Works;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkController extends Controller
{
    public function complete(string $id, Request $request): JsonResponse
    {
        // Find service request or work by uuid or id
        $serviceRequest = ServiceRequestModel::where('uuid', $id)->first();
        if ($serviceRequest) {
            $serviceRequest->update(['status' => 'completed']);
        }

        return response()->json([
            'message' => 'El trabajo fue marcado como completado.',
            'data' => [
                'id' => $id,
                'status' => 'completed',
            ],
        ]);
    }
}
