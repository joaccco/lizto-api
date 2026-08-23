<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProviderController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $profiles = ProviderProfileModel::where('status', ProviderProfileStatus::PendingVerification)
            ->with(['user', 'categories.category', 'serviceAreas', 'documents'])
            ->orderBy('submitted_at', 'asc')
            ->get();

        $data = $profiles->map(function ($p) {
            return [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'user_id' => $p->user_id,
                'user_name' => $p->user?->name,
                'email' => $p->user?->email,
                'phone' => $p->user?->phone,
                'commercial_name' => $p->commercial_name,
                'status' => $p->status->value,
                'category_name' => $p->categories->first()?->category?->name ?? 'Servicio general',
                'base_address' => $p->base_address,
                'submitted_at' => $p->submitted_at?->toISOString(),
                'documents_count' => $p->documents->count(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function show(string $uuid): JsonResponse
    {
        $profile = $this->findProfile($uuid);
        $profile->load(['user', 'categories.category', 'serviceAreas', 'schedules', 'portfolioItems', 'documents']);

        $category = $profile->categories->first();

        return response()->json([
            'data' => [
                'id' => $profile->id,
                'uuid' => $profile->uuid,
                'status' => $profile->status instanceof \BackedEnum ? $profile->status->value : $profile->status,
                'user' => [
                    'id' => $profile->user->id,
                    'name' => $profile->user->name,
                    'email' => $profile->user->email,
                    'phone' => $profile->user->phone,
                ],
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'commercial_name' => $profile->commercial_name,
                'bio' => $profile->bio,
                'years_experience' => $profile->years_experience,
                'is_verified' => $profile->is_verified,
                'base_address' => $profile->base_address,
                'category_name' => $category?->category?->name ?? 'Servicio general',
                'specialties' => $category?->specialties ?? [],
                'submitted_at' => $profile->submitted_at?->toISOString(),
                'verified_at' => $profile->verified_at?->toISOString(),
                'verified_by' => $profile->verifiedBy?->name,
                'rejected_at' => $profile->rejected_at?->toISOString(),
                'rejection_reason' => $profile->rejection_reason,
                'suspended_at' => $profile->suspended_at?->toISOString(),
                'suspension_reason' => $profile->suspension_reason,
                'portfolio' => $profile->portfolioItems->map(fn($item) => [
                    'id' => $item->id,
                    'uuid' => $item->uuid,
                    'title' => $item->title,
                    'description' => $item->description,
                    'media_urls' => $item->media_urls,
                ]),
                'documents' => $profile->documents->map(fn($doc) => [
                    'id' => $doc->id,
                    'uuid' => $doc->uuid,
                    'document_type' => $doc->document_type instanceof \BackedEnum ? $doc->document_type->value : $doc->document_type,
                    'document_number' => $doc->document_number,
                    'file_path' => $doc->file_path,
                    'status' => $doc->status instanceof \BackedEnum ? $doc->status->value : $doc->status,
                    'notes' => $doc->notes,
                    'created_at' => $doc->created_at?->toISOString(),
                ]),
            ],
        ]);
    }

    public function verify(string $uuid, Request $request): JsonResponse
    {
        $profile = $this->findProfile($uuid);
        $admin = $request->user();

        $profile->update([
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'verified_at' => now(),
            'verified_by' => $admin->id,
            'rejected_at' => null,
            'rejection_reason' => null,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $profile->user?->assignRole('provider');

        return response()->json([
            'message' => 'Perfil del profesional verificado y habilitado para trabajos.',
            'data' => [
                'uuid' => $profile->uuid,
                'status' => ProviderProfileStatus::Verified->value,
                'verified_at' => $profile->verified_at->toISOString(),
            ],
        ]);
    }

    public function reject(string $uuid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $profile = $this->findProfile($uuid);

        $profile->update([
            'status' => ProviderProfileStatus::Rejected,
            'is_verified' => false,
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'],
        ]);

        $profile->user?->removeRole('provider');

        return response()->json([
            'message' => 'Perfil del profesional rechazado.',
            'data' => [
                'uuid' => $profile->uuid,
                'status' => ProviderProfileStatus::Rejected->value,
                'rejection_reason' => $profile->rejection_reason,
            ],
        ]);
    }

    public function suspend(string $uuid, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $profile = $this->findProfile($uuid);

        $profile->update([
            'status' => ProviderProfileStatus::Suspended,
            'is_verified' => false,
            'suspended_at' => now(),
            'suspension_reason' => $validated['reason'],
        ]);

        $profile->user?->removeRole('provider');

        return response()->json([
            'message' => 'Perfil del profesional suspendido.',
            'data' => [
                'uuid' => $profile->uuid,
                'status' => ProviderProfileStatus::Suspended->value,
                'suspension_reason' => $profile->suspension_reason,
            ],
        ]);
    }

    public function reactivate(string $uuid, Request $request): JsonResponse
    {
        $profile = $this->findProfile($uuid);

        $profile->update([
            'status' => ProviderProfileStatus::Verified,
            'is_verified' => true,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $profile->user?->assignRole('provider');

        return response()->json([
            'message' => 'Perfil del profesional reactivado exitosamente.',
            'data' => [
                'uuid' => $profile->uuid,
                'status' => ProviderProfileStatus::Verified->value,
            ],
        ]);
    }

    private function findProfile(string $idOrUuid): ProviderProfileModel
    {
        $query = ProviderProfileModel::query();
        if (is_numeric($idOrUuid)) {
            $query->where('id', (int) $idOrUuid);
        } else {
            $query->where('uuid', $idOrUuid);
        }
        return $query->firstOrFail();
    }
}
