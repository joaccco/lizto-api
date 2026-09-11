<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Domain\Identity\Services\IdentityService;
use App\Http\Controllers\Controller;
use App\Models\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    public function __construct(
        protected IdentityService $identityService
    ) {}

    /**
     * Start Didit KYC session.
     */
    public function start(Request $request): JsonResponse
    {
        $user = $request->user();
        $res = $this->identityService->initiateKYC($user, [
            'redirect_url' => $request->input('redirect_url'),
            'callback' => $request->input('callback'),
            'consent_version' => $request->input('consent_version'),
        ]);

        return response()->json([
            'data' => $res,
            'message' => 'Sesión de verificación iniciada.',
        ]);
    }

    /**
     * Poll identity status.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $identity = Identity::where('user_id', $user->id)->first();

        if (!$identity) {
            return response()->json([
                'status' => 'not_started',
                'data' => null,
            ]);
        }

        return response()->json([
            'status' => $identity->status,
            'data' => [
                'identity_id' => $identity->id,
                'status' => $identity->status,
                'verified_at' => $identity->verified_at?->toISOString(),
                'rejection_reason' => $identity->rejection_reason,
                'session_id' => $identity->didit_kyc_response_id,
            ],
        ]);
    }
}
