<?php

namespace App\Http\Controllers\Api\V1\ServiceRequests;

use App\Application\AI\Actions\ParseIntentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequests\ParseRequestRequest;
use Illuminate\Http\JsonResponse;

class ParseRequestController extends Controller
{
    public function parse(ParseRequestRequest $request, ParseIntentAction $action): JsonResponse
    {
        $dto = $action->execute(
            prompt: $request->input('prompt'),
            urgency: $request->input('urgency'),
            location: $request->input('location')
        );

        return response()->json([
            'data' => $dto->toArray(),
        ]);
    }
}
