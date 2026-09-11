<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MVUResource;
use App\Models\AuditLog;
use App\Models\ProfessionalMVU;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MVUController extends Controller
{
    /**
     * List pending professional MVUs.
     */
    public function pending(Request $request): JsonResponse
    {
        $query = ProfessionalMVU::with([
            'provider.user',
            'provider.categories.category',
            'identity',
        ])->where('overall_verification_status', 'pending');

        $mvus = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => MVUResource::collection($mvus),
            'meta' => [
                'current_page' => $mvus->currentPage(),
                'last_page' => $mvus->lastPage(),
                'total' => $mvus->total(),
            ],
        ]);
    }

    /**
     * Get specific MVU details.
     */
    public function show(int $id): JsonResponse
    {
        $mvu = ProfessionalMVU::with([
            'provider.user',
            'provider.categories.category',
            'identity',
        ])->findOrFail($id);

        return response()->json([
            'data' => new MVUResource($mvu),
        ]);
    }

    /**
     * Approve MVU and verify the provider profile.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            /** @var ProfessionalMVU $mvu */
            $mvu = ProfessionalMVU::with('provider')->findOrFail($id);

            $adminId = auth()->id();

            $mvu->update([
                'overall_verification_status' => 'approved',
                'skills_verified' => true,
                'skills_verified_by' => $adminId,
                'antecedentes_status' => 'approved',
            ]);

            if ($mvu->provider) {
                $mvu->provider->update([
                    'status' => ProviderProfileStatus::Verified,
                    'is_verified' => true,
                    'verified_at' => now(),
                    'verified_by' => $adminId,
                    'rejection_reason' => null,
                ]);
            }

            AuditLog::create([
                'event' => 'mvu_approved',
                'subject_type' => 'ProfessionalMVU',
                'subject_id' => $mvu->id,
                'user_id' => $adminId,
                'action' => 'approve',
                'new_values' => [
                    'provider_id' => $mvu->provider_id,
                    'overall_verification_status' => 'approved',
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return response()->json([
                'message' => 'Verificación profesional aprobada exitosamente.',
                'data' => new MVUResource($mvu),
            ]);
        });
    }

    /**
     * Reject MVU.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return DB::transaction(function () use ($request, $id) {
            /** @var ProfessionalMVU $mvu */
            $mvu = ProfessionalMVU::with('provider')->findOrFail($id);

            $adminId = auth()->id();
            $reason = $request->input('reason');

            $mvu->update([
                'overall_verification_status' => 'rejected',
                'antecedentes_status' => 'rejected',
            ]);

            if ($mvu->provider) {
                $mvu->provider->update([
                    'status' => ProviderProfileStatus::Rejected,
                    'is_verified' => false,
                    'rejected_at' => now(),
                    'rejection_reason' => $reason,
                ]);
            }

            AuditLog::create([
                'event' => 'mvu_rejected',
                'subject_type' => 'ProfessionalMVU',
                'subject_id' => $mvu->id,
                'user_id' => $adminId,
                'action' => 'reject',
                'new_values' => [
                    'provider_id' => $mvu->provider_id,
                    'reason' => $reason,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return response()->json([
                'message' => 'Verificación profesional rechazada.',
                'data' => new MVUResource($mvu),
            ]);
        });
    }

    /**
     * Request additional data from the provider.
     */
    public function requestData(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => ['required', 'string', 'max:1000'],
        ]);

        $mvu = ProfessionalMVU::findOrFail($id);

        AuditLog::create([
            'event' => 'mvu_data_requested',
            'subject_type' => 'ProfessionalMVU',
            'subject_id' => $mvu->id,
            'user_id' => auth()->id(),
            'action' => 'request_data',
            'new_values' => ['notes' => $request->input('notes')],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Solicitud de datos enviada al profesional.',
        ]);
    }
}
