<?php

namespace App\Http\Controllers\Api\V1\Kyc;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Domain\Providers\Enums\DocumentType;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycDocumentController extends Controller
{
    private function resolveProviderProfile($user): ProviderProfileModel
    {
        $profile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$profile) {
            $profile = ProviderProfileModel::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => ProviderProfileStatus::Draft,
            ]);
        }
        return $profile;
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $validated = $request->validate([
            'document_type' => 'required|string|in:passport,driver_license,identity,selfie,dni_front,dni_back,professional_license,certificate,other',
            'document_file' => 'required|file|mimes:jpeg,jpg,png,pdf|max:10240',
            'document_number' => 'required|string|regex:/^[0-9A-Za-z]+$/|min:4|max:50',
            'expiry_date' => 'nullable|date',
        ], [
            'document_file.max' => 'El archivo supera el tamaño máximo permitido de 10MB.',
            'document_file.mimes' => 'El archivo debe ser una imagen JPEG, PNG o documento PDF.',
            'document_number.required' => 'El número de documento es obligatorio.',
            'document_number.regex' => 'El número de documento tiene un formato inválido.',
        ]);

        $providerProfile = $this->resolveProviderProfile($user);

        // Max 3 documents per provider per document_type
        $existingCount = ProviderDocumentModel::where('provider_id', $providerProfile->id)
            ->where('document_type', $validated['document_type'])
            ->count();

        if ($existingCount >= 3) {
            return response()->json([
                'message' => 'Límite alcanzado: máximo 3 documentos permitidos para este tipo.',
            ], 422);
        }

        // Duplicate check (document_type + document_number per provider)
        $duplicate = ProviderDocumentModel::where('provider_id', $providerProfile->id)
            ->where('document_type', $validated['document_type'])
            ->where('document_number', $validated['document_number'])
            ->whereIn('status', [DocumentStatus::Pending->value, DocumentStatus::Approved->value, DocumentStatus::Verified->value])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Ya existe un documento registrado con este número y tipo para este proveedor.',
            ], 409);
        }

        $file = $request->file('document_file');
        $uuid = (string) Str::uuid();
        $ext = $file->getClientOriginalExtension() ?: 'bin';
        $disk = env('KYC_FILESYSTEM_DISK', 's3');

        $storagePath = "kyc/{$providerProfile->id}/{$validated['document_type']}/{$uuid}.{$ext}";

        try {
            Storage::disk($disk)->putFileAs(
                "kyc/{$providerProfile->id}/{$validated['document_type']}",
                $file,
                "{$uuid}.{$ext}",
                'private'
            );
        } catch (\Throwable $e) {
            // Fallback to local private storage if s3 is unconfigured in dev/testing
            $disk = 'local';
            Storage::disk('local')->putFileAs(
                "kyc/{$providerProfile->id}/{$validated['document_type']}",
                $file,
                "{$uuid}.{$ext}"
            );
        }

        $document = ProviderDocumentModel::create([
            'uuid' => $uuid,
            'provider_id' => $providerProfile->id,
            'document_type' => $validated['document_type'],
            'document_number' => $validated['document_number'],
            'expiry_date' => $validated['expiry_date'] ?? null,
            'file_path' => $storagePath,
            'status' => DocumentStatus::Pending,
        ]);

        Log::info('KYC document uploaded successfully', [
            'user_id' => $user->id,
            'provider_id' => $providerProfile->id,
            'document_id' => $document->uuid,
            'document_type' => $document->document_type instanceof \BackedEnum ? $document->document_type->value : $document->document_type,
            'result' => 'success',
        ]);

        return response()->json([
            'document_id' => $document->uuid,
            'document_type' => $document->document_type instanceof \BackedEnum ? $document->document_type->value : $document->document_type,
            'status' => $document->status instanceof \BackedEnum ? $document->status->value : $document->status,
            'created_at' => $document->created_at?->toISOString(),
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $profile = ProviderProfileModel::where('user_id', $user->id)->first();
        if (!$profile) {
            return response()->json([
                'kyc_status' => 'unverified',
                'documents' => [],
                'rejection_reasons' => [],
            ], 200);
        }

        $documents = ProviderDocumentModel::where('provider_id', $profile->id)->get();

        if ($documents->isEmpty()) {
            return response()->json([
                'kyc_status' => 'unverified',
                'documents' => [],
                'rejection_reasons' => [],
            ], 200);
        }

        $hasRejected = $documents->contains(fn($d) => ($d->status instanceof \BackedEnum ? $d->status->value : $d->status) === DocumentStatus::Rejected->value);
        $hasVerifiedIdentity = $documents->contains(function ($d) {
            $status = $d->status instanceof \BackedEnum ? $d->status->value : $d->status;
            $type = $d->document_type instanceof \BackedEnum ? $d->document_type->value : $d->document_type;
            $isVerified = in_array($status, [DocumentStatus::Verified->value, DocumentStatus::Approved->value], true);
            $isIdentityType = in_array($type, [
                DocumentType::Identity->value,
                DocumentType::Passport->value,
                DocumentType::DriverLicense->value,
                DocumentType::DniFront->value,
            ], true);
            return $isVerified && $isIdentityType;
        });
        $hasPending = $documents->contains(fn($d) => ($d->status instanceof \BackedEnum ? $d->status->value : $d->status) === DocumentStatus::Pending->value);

        if ($hasRejected) {
            $kycStatus = 'rejected';
        } elseif ($hasVerifiedIdentity) {
            $kycStatus = 'verified';
        } elseif ($hasPending) {
            $kycStatus = 'pending';
        } else {
            $kycStatus = 'unverified';
        }

        $docsList = $documents->map(fn($d) => [
            'document_id' => $d->uuid,
            'document_type' => $d->document_type instanceof \BackedEnum ? $d->document_type->value : $d->document_type,
            'status' => $d->status instanceof \BackedEnum ? $d->status->value : $d->status,
            'verified_at' => $d->verified_at?->toISOString(),
            'created_at' => $d->created_at?->toISOString(),
        ]);

        $rejectionReasons = $documents
            ->filter(fn($d) => ($d->status instanceof \BackedEnum ? $d->status->value : $d->status) === DocumentStatus::Rejected->value && !empty($d->rejection_reason))
            ->map(fn($d) => [
                'document_type' => $d->document_type instanceof \BackedEnum ? $d->document_type->value : $d->document_type,
                'reason' => $d->rejection_reason,
            ])
            ->values();

        return response()->json([
            'kyc_status' => $kycStatus,
            'documents' => $docsList,
            'rejection_reasons' => $rejectionReasons,
        ], 200);
    }

    public function signedUrl(string $uuid, Request $request): JsonResponse
    {
        $user = $request->user();
        $doc = ProviderDocumentModel::where('uuid', $uuid)->first();

        if (!$doc) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        $profile = ProviderProfileModel::where('user_id', $user->id)->first();
        $isOwner = $profile && (int) $doc->provider_id === (int) $profile->id;
        $isAdmin = $user->hasRole('admin');

        if (!$isOwner && !$isAdmin) {
            Log::warning('Unauthorized KYC document signed URL access attempt', [
                'user_id' => $user->id,
                'document_id' => $doc->uuid,
            ]);
            return response()->json(['message' => 'No autorizado para acceder a este documento.'], 403);
        }

        $disk = env('KYC_FILESYSTEM_DISK', 's3');
        try {
            $url = Storage::disk($disk)->temporaryUrl($doc->file_path, now()->addMinutes(5));
        } catch (\Throwable $e) {
            $url = url("/api/v1/kyc/documents/{$doc->uuid}/view?expires=" . now()->addMinutes(5)->timestamp);
        }

        Log::info('KYC signed URL generated', [
            'user_id' => $user->id,
            'document_id' => $doc->uuid,
            'expires_at' => now()->addMinutes(5)->toISOString(),
        ]);

        return response()->json([
            'signed_url' => $url,
            'expires_in_seconds' => 300,
        ], 200);
    }

    public function destroy(string $uuid, Request $request): JsonResponse
    {
        $user = $request->user();
        $doc = ProviderDocumentModel::where('uuid', $uuid)->first();

        if (!$doc) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        $profile = ProviderProfileModel::where('user_id', $user->id)->first();
        $isOwner = $profile && (int) $doc->provider_id === (int) $profile->id;
        $isAdmin = $user->hasRole('admin');

        if (!$isOwner && !$isAdmin) {
            return response()->json(['message' => 'No autorizado para eliminar este documento.'], 403);
        }

        // Compliance: Move file to quarantine path rather than immediate hard purge
        $quarantinePath = "quarantine/{$doc->provider_id}/{$doc->uuid}";
        $disk = env('KYC_FILESYSTEM_DISK', 's3');

        try {
            if (Storage::disk($disk)->exists($doc->file_path)) {
                Storage::disk($disk)->move($doc->file_path, $quarantinePath);
            }
        } catch (\Throwable $ignored) {}

        Log::info('KYC document moved to quarantine and deleted', [
            'user_id' => $user->id,
            'document_id' => $doc->uuid,
            'quarantine_path' => $quarantinePath,
        ]);

        $doc->delete();

        return response()->json([
            'message' => 'Documento eliminado y archivado para cumplimiento normativo.',
        ], 200);
    }
}
