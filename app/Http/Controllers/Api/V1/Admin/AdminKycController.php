<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminKycController extends Controller
{
    public function verify(string $uuid, Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin || !$admin->hasRole('admin')) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $document = ProviderDocumentModel::where('uuid', $uuid)->first();
        if (!$document) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        $document->update([
            'status' => DocumentStatus::Verified,
            'verified_at' => now(),
            'rejection_reason' => null,
            'rejected_at' => null,
        ]);

        Log::info('KYC document verified by admin', [
            'admin_id' => $admin->id,
            'document_id' => $document->uuid,
            'provider_id' => $document->provider_id,
            'result' => 'verified',
        ]);

        return response()->json([
            'message' => 'Documento verificado correctamente.',
            'data' => [
                'document_id' => $document->uuid,
                'status' => 'verified',
                'verified_at' => $document->verified_at?->toISOString(),
            ],
        ], 200);
    }

    public function reject(string $uuid, Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin || !$admin->hasRole('admin')) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $document = ProviderDocumentModel::where('uuid', $uuid)->first();
        if (!$document) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        $document->update([
            'status' => DocumentStatus::Rejected,
            'rejection_reason' => $validated['reason'],
            'rejected_at' => now(),
        ]);

        Log::info('KYC document rejected by admin', [
            'admin_id' => $admin->id,
            'document_id' => $document->uuid,
            'provider_id' => $document->provider_id,
            'reason' => $validated['reason'],
            'result' => 'rejected',
        ]);

        return response()->json([
            'message' => 'Documento rechazado.',
            'data' => [
                'document_id' => $document->uuid,
                'status' => 'rejected',
                'rejection_reason' => $document->rejection_reason,
                'rejected_at' => $document->rejected_at?->toISOString(),
            ],
        ], 200);
    }
}
