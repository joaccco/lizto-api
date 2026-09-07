<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = UserModel::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'phone'    => $request->phone,
            'status'   => 'active',
        ]);

        $user->assignRole('client');

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => [
                'user'  => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Registro exitoso.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = UserModel::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'message' => 'Tu cuenta está suspendida.',
                'errors'  => ['account' => ['Cuenta suspendida.']],
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'data' => [
                'user'  => new UserResource($user->load('providerProfile')),
                'token' => $token,
            ],
            'message' => 'Login exitoso.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($request->filled('device_token')) {
            \App\Infrastructure\Persistence\Eloquent\UserDeviceModel::where('user_id', $user->id)
                ->where('device_token', $request->input('device_token'))
                ->update(['revoked_at' => now()]);
        }

        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    public function becomeProvider(Request $request): JsonResponse
    {
        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'message' => 'Usuario no autenticado.',
            ], 401);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($userId) {
            /** @var UserModel|null $user */
            $user = UserModel::where('id', $userId)->lockForUpdate()->first();
            if (!$user) {
                return response()->json(['message' => 'Usuario no encontrado.'], 404);
            }

            $providerProfile = \App\Infrastructure\Persistence\Eloquent\ProviderProfileModel::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$providerProfile) {
                \Illuminate\Support\Facades\Log::warning('Failed becomeProvider attempt - missing profile', [
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'message' => 'No se encontró perfil de proveedor. Completa tu perfil primero.',
                ], 404);
            }

            $kyc = \App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel::where('provider_id', $providerProfile->id)
                ->whereIn('status', [
                    \App\Domain\Providers\Enums\DocumentStatus::Verified->value,
                    \App\Domain\Providers\Enums\DocumentStatus::Approved->value,
                ])
                ->first();

            $hasRejected = \App\Infrastructure\Persistence\Eloquent\ProviderDocumentModel::where('provider_id', $providerProfile->id)
                ->where('status', \App\Domain\Providers\Enums\DocumentStatus::Rejected->value)
                ->exists();

            if (!$kyc || $hasRejected) {
                \Illuminate\Support\Facades\Log::warning('Failed becomeProvider attempt - unverified KYC', [
                    'user_id' => $user->id,
                    'has_verified_doc' => (bool) $kyc,
                    'has_rejected_doc' => $hasRejected,
                ]);

                return response()->json([
                    'message' => 'Debes completar la verificación de identidad (KYC) antes de convertirte en proveedor.',
                    'errors'  => ['kyc' => ['Verificación pendiente o rechazada.']],
                ], 403);
            }

            if ($user->hasRole('provider')) {
                return response()->json([
                    'message' => 'Ya eres proveedor.',
                    'role'    => 'provider',
                ], 200);
            }

            $user->assignRole('provider');

            \Illuminate\Support\Facades\Log::info('User successfully transitioned to provider via KYC verification', [
                'user_id' => $user->id,
                'provider_profile_id' => $providerProfile->id,
            ]);

            return response()->json([
                'message' => 'Ahora eres proveedor. ¡Bienvenido!',
                'role'    => 'provider',
            ], 200);
        });
    }
}
