<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Providers\Enums\DocumentStatus;
use App\Domain\Providers\Enums\DocumentType;
use App\Domain\Providers\Enums\ProviderProfileStatus;
use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\CategoryModel;
use App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel;
use App\Infrastructure\Persistence\Eloquent\ProviderPortfolioItemModel;
use App\Infrastructure\Persistence\Eloquent\ProviderProfileModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderProfileController extends Controller
{
    private const DAY_CODE_MAP = [
        1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun',
    ];

    private const DAY_NUM_MAP = [
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7,
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)
            ->with(['user', 'categories.category', 'serviceAreas', 'schedules', 'portfolioItems', 'documents'])
            ->first();

        if (!$providerProfile) {
            return response()->json([
                'data' => [
                    'id' => null,
                    'uuid' => null,
                    'user_id' => $user->id,
                    'status' => ProviderProfileStatus::Draft->value,
                    'first_name' => explode(' ', $user->name)[0] ?? '',
                    'last_name' => explode(' ', $user->name)[1] ?? '',
                    'commercial_name' => '',
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                    'bio' => '',
                    'years_experience' => 0,
                    'is_verified' => false,
                    'radius_km' => 15,
                    'base_address' => '',
                    'specialties' => [],
                    'schedules' => ['mon', 'tue', 'wed', 'thu', 'fri'],
                    'completion_percentage' => 10,
                    'portfolio' => [],
                    'documents' => [],
                    'rejection_reason' => null,
                    'suspension_reason' => null,
                ],
            ]);
        }

        // Ensure UUID is present
        if (empty($providerProfile->uuid)) {
            $providerProfile->update(['uuid' => (string) Str::uuid()]);
        }

        $area = $providerProfile->serviceAreas->first();
        $radiusKm = $area ? (int) $area->radius_km : 15;

        $category = $providerProfile->categories->first();
        $specialties = $category ? ($category->specialties ?? []) : [];
        $categoryName = $category?->category?->name ?? 'Servicios Generales';

        $schedules = $providerProfile->schedules->map(function ($s) {
            return self::DAY_CODE_MAP[$s->day_of_week] ?? $s->day_of_week;
        })->values()->toArray();

        if (empty($schedules)) {
            $schedules = ['mon', 'tue', 'wed', 'thu', 'fri'];
        }

        $statusVal = $providerProfile->status instanceof \BackedEnum
            ? $providerProfile->status->value
            : ($providerProfile->status ?? 'draft');

        return response()->json([
            'data' => [
                'id' => $providerProfile->id,
                'uuid' => $providerProfile->uuid,
                'user_id' => $user->id,
                'status' => $statusVal,
                'first_name' => $providerProfile->first_name ?? explode(' ', $user->name)[0],
                'last_name' => $providerProfile->last_name ?? '',
                'commercial_name' => $providerProfile->commercial_name ?? '',
                'name' => $providerProfile->commercial_name ?: $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? '',
                'bio' => $providerProfile->bio ?? '',
                'years_experience' => (int) ($providerProfile->years_experience ?? 3),
                'is_verified' => (bool) ($providerProfile->is_verified ?? false),
                'avg_rating' => (float) ($providerProfile->avg_rating ?? 5.0),
                'total_reviews' => (int) ($providerProfile->total_reviews ?? 0),
                'total_jobs_completed' => (int) ($providerProfile->total_jobs_completed ?? 0),
                'radius_km' => $radiusKm,
                'base_address' => $providerProfile->base_address ?? '',
                'category_name' => $categoryName,
                'specialties' => $specialties,
                'schedules' => $schedules,
                'completion_percentage' => $providerProfile->completion_percentage,
                'availability_status' => $providerProfile->availability_status instanceof \BackedEnum
                    ? $providerProfile->availability_status->value
                    : $providerProfile->availability_status,
                'rejection_reason' => $providerProfile->rejection_reason,
                'suspension_reason' => $providerProfile->suspension_reason,
                'submitted_at' => $providerProfile->submitted_at?->toISOString(),
                'verified_at' => $providerProfile->verified_at?->toISOString(),
                'portfolio' => $providerProfile->portfolioItems->map(fn($item) => [
                    'id' => $item->id,
                    'uuid' => $item->uuid,
                    'title' => $item->title,
                    'description' => $item->description,
                    'media_urls' => $item->media_urls ?? [],
                    'sort_order' => $item->sort_order,
                ]),
                'documents' => $providerProfile->documents->map(fn($doc) => [
                    'id' => $doc->id,
                    'uuid' => $doc->uuid,
                    'document_type' => $doc->document_type instanceof \BackedEnum ? $doc->document_type->value : $doc->document_type,
                    'document_number' => $doc->document_number,
                    'file_path' => $doc->file_path,
                    'status' => $doc->status instanceof \BackedEnum ? $doc->status->value : $doc->status,
                    'created_at' => $doc->created_at?->toISOString(),
                ]),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'commercial_name' => 'nullable|string|max:150',
            'bio' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:30',
            'years_experience' => 'nullable|integer|min:0|max:50',
            'base_address' => 'nullable|string|max:500',
            'base_lat' => 'nullable|numeric',
            'base_lng' => 'nullable|numeric',
            'radius_km' => 'nullable|integer|min:' . ProviderProfileModel::MIN_COVERAGE_RADIUS_KM . '|max:' . ProviderProfileModel::MAX_COVERAGE_RADIUS_KM,
            'category_id' => 'nullable|integer|exists:categories,id',
            'specialties' => 'nullable|array',
            'schedules' => 'nullable|array',
        ]);

        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            $providerProfile = ProviderProfileModel::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => ProviderProfileStatus::Draft,
                'bio' => $request->input('bio', ''),
                'availability_status' => 'available',
            ]);
        }

        DB::transaction(function () use ($user, $providerProfile, $request) {
            if ($request->has('phone')) {
                $user->update(['phone' => $request->input('phone')]);
            }

            $updateFields = [];
            if ($request->has('first_name')) $updateFields['first_name'] = $request->input('first_name');
            if ($request->has('last_name')) $updateFields['last_name'] = $request->input('last_name');
            if ($request->has('commercial_name')) $updateFields['commercial_name'] = $request->input('commercial_name');
            if ($request->has('bio')) $updateFields['bio'] = $request->input('bio');
            if ($request->has('years_experience')) $updateFields['years_experience'] = $request->input('years_experience');
            if ($request->has('base_address')) $updateFields['base_address'] = $request->input('base_address');
            if ($request->has('base_lat')) $updateFields['base_lat'] = $request->input('base_lat');
            if ($request->has('base_lng')) $updateFields['base_lng'] = $request->input('base_lng');

            if (!empty($updateFields)) {
                $providerProfile->update($updateFields);
            }

            if ($request->has('radius_km') || $request->has('base_lat') || $request->has('base_lng')) {
                $radius = $request->input('radius_km', 15);
                $lat = $request->input('base_lat', $providerProfile->base_lat ?? -27.4692);
                $lng = $request->input('base_lng', $providerProfile->base_lng ?? -58.8306);

                $area = $providerProfile->serviceAreas()->first();
                if ($area) {
                    $area->update([
                        'radius_km' => $radius,
                        'center_lat' => $lat,
                        'center_lng' => $lng,
                    ]);
                } else {
                    $providerProfile->serviceAreas()->create([
                        'center_lat' => $lat,
                        'center_lng' => $lng,
                        'radius_km' => $radius,
                        'label' => 'Principal',
                    ]);
                }
            }

            if ($request->has('specialties') || $request->has('category_id')) {
                $specialties = $request->input('specialties', []);
                $catId = $request->input('category_id');

                $cat = $providerProfile->categories()->first();
                if ($cat) {
                    $data = ['specialties' => $specialties];
                    if ($catId) $data['category_id'] = $catId;
                    $cat->update($data);
                } else {
                    $category = $catId ? CategoryModel::find($catId) : CategoryModel::first();
                    if ($category) {
                        $providerProfile->categories()->create([
                            'category_id' => $category->id,
                            'specialties' => $specialties,
                        ]);
                    }
                }
            }

            if ($request->has('schedules')) {
                $schedulesInput = $request->input('schedules', []);
                $providerProfile->schedules()->delete();

                foreach ($schedulesInput as $day) {
                    $dayNum = self::DAY_NUM_MAP[$day] ?? null;
                    if ($dayNum) {
                        $providerProfile->schedules()->create([
                            'day_of_week' => $dayNum,
                            'start_time' => '08:00:00',
                            'end_time' => '18:00:00',
                            'is_active' => true,
                        ]);
                    }
                }
            }
        });

        return $this->show($request);
    }

    public function storePortfolioItem(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'media_urls' => 'nullable|array',
            'media_urls.*' => 'string|url',
        ]);

        $item = ProviderPortfolioItemModel::create([
            'uuid' => (string) Str::uuid(),
            'provider_id' => $providerProfile->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'media_urls' => $validated['media_urls'] ?? [],
            'sort_order' => $providerProfile->portfolioItems()->count() + 1,
        ]);

        return response()->json([
            'message' => 'Proyecto agregado al portafolio.',
            'data' => [
                'id' => $item->id,
                'uuid' => $item->uuid,
                'title' => $item->title,
                'description' => $item->description,
                'media_urls' => $item->media_urls,
            ],
        ], 201);
    }

    public function deletePortfolioItem(string $uuid, Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->firstOrFail();

        $item = ProviderPortfolioItemModel::where('uuid', $uuid)
            ->where('provider_id', $providerProfile->id)
            ->firstOrFail();

        $item->delete();

        return response()->json(['message' => 'Proyecto eliminado del portafolio.']);
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)->first();

        if (!$providerProfile) {
            $providerProfile = ProviderProfileModel::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => ProviderProfileStatus::Draft,
            ]);
        }

        $validated = $request->validate([
            'document_type' => 'required|string|in:dni_front,dni_back,professional_license,certificate,other',
            'document_number' => 'nullable|string|max:100',
            'file_path' => 'nullable|string|max:500',
            'file' => 'nullable|file|mimes:jpeg,jpg,png,pdf|max:10240',
        ]);

        $filePath = $validated['file_path'] ?? null;

        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('verification_docs', 'public');
            $filePath = '/storage/' . $path;
        }

        if (!$filePath) {
            $filePath = '/docs/verification_sample.pdf';
        }

        $doc = ProviderDocumentModel::create([
            'uuid' => (string) Str::uuid(),
            'provider_id' => $providerProfile->id,
            'document_type' => $validated['document_type'],
            'document_number' => $validated['document_number'] ?? null,
            'file_path' => $filePath,
            'status' => DocumentStatus::Pending,
        ]);

        return response()->json([
            'message' => 'Documentación recibida de forma segura.',
            'data' => [
                'id' => $doc->id,
                'uuid' => $doc->uuid,
                'document_type' => $doc->document_type->value,
                'file_path' => $doc->file_path,
                'status' => $doc->status->value,
            ],
        ], 201);
    }

    public function submitVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        $providerProfile = ProviderProfileModel::where('user_id', $user->id)
            ->with(['categories', 'serviceAreas', 'documents'])
            ->first();

        if (!$providerProfile) {
            return response()->json(['message' => 'Debe completar el perfil antes de solicitar verificación.'], 422);
        }

        // Validate minimum required fields for verification
        if (empty($providerProfile->base_address) && empty($providerProfile->base_lat)) {
            return response()->json(['message' => 'Debe indicar su domicilio o zona de trabajo principal.'], 422);
        }

        if ($providerProfile->categories()->count() === 0) {
            return response()->json(['message' => 'Debe seleccionar al menos una categoría de servicio.'], 422);
        }

        $providerProfile->update([
            'status' => ProviderProfileStatus::PendingVerification,
            'submitted_at' => now(),
            'rejection_reason' => null,
        ]);

        // Assign 'provider' spatie role to user if not assigned
        if (!$user->hasRole('provider')) {
            $user->assignRole('provider');
        }

        return response()->json([
            'message' => 'Perfil enviado a verificación exitosamente. El equipo de Lizto revisará tu solicitud.',
            'data' => [
                'status' => ProviderProfileStatus::PendingVerification->value,
                'submitted_at' => $providerProfile->submitted_at->toISOString(),
            ],
        ]);
    }
}
