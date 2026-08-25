<?php

namespace App\Http\Controllers\Api\V1\Devices;

use App\Http\Controllers\Controller;
use App\Infrastructure\Persistence\Eloquent\UserDeviceModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => ['required', 'string'],
            'platform' => ['required', 'string', Rule::in(['web', 'ios', 'android'])],
            'device_identifier' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $token = $validated['device_token'];

        $device = UserDeviceModel::updateOrCreate(
            ['device_token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $validated['platform'],
                'device_identifier' => $validated['device_identifier'] ?? null,
                'last_active_at' => now(),
                'revoked_at' => null,
            ]
        );

        return response()->json([
            'message' => 'Dispositivo registrado correctamente.',
            'data' => [
                'id' => $device->id,
                'device_token' => $device->device_token,
                'platform' => $device->platform,
                'device_identifier' => $device->device_identifier,
                'last_active_at' => $device->last_active_at?->toISOString(),
            ],
        ], 200);
    }

    public function destroy(Request $request, ?string $tokenParam = null): JsonResponse
    {
        $token = $request->input('device_token') ?? $tokenParam ?? $request->route('token');

        if (empty($token)) {
            return response()->json([
                'message' => 'Se requiere el token del dispositivo.',
                'errors' => ['device_token' => ['Campo requerido.']],
            ], 422);
        }

        $user = $request->user();
        $device = UserDeviceModel::where('device_token', $token)->first();

        if (!$device) {
            return response()->json([
                'message' => 'Dispositivo no encontrado.',
            ], 404);
        }

        if ($device->user_id !== $user->id) {
            return response()->json([
                'message' => 'No tienes permiso para dar de baja este dispositivo.',
            ], 403);
        }

        $device->update(['revoked_at' => now()]);

        return response()->json([
            'message' => 'Dispositivo dado de baja correctamente.',
        ], 200);
    }
}
