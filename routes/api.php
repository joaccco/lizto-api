<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\ServiceRequests\ServiceRequestController;
use App\Http\Controllers\Api\V1\Matching\MatchSessionController;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Health check público
    Route::get('/health', function () {
        return response()->json([
            'status'    => 'ok',
            'service'   => 'lizto-api',
            'version'   => '1.0.0',
            'timestamp' => now()->toISOString(),
        ]);
    })->name('health');

    // Auth — público
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/login',    [AuthController::class, 'login'])->name('login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me',      [ProfileController::class, 'me'])->name('me');
        });
    });

    // Catálogo — público
    Route::get('/categories', [\App\Http\Controllers\Api\V1\Providers\CategoryController::class, 'index'])->name('categories.index');
    Route::get('/providers', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'index'])->name('providers.index');
    Route::get('/providers/{uuid}', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'show'])->name('providers.show');

    // Parser de solicitudes — público
    Route::post('/requests/parse', [\App\Http\Controllers\Api\V1\ServiceRequests\ParseRequestController::class, 'parse'])->name('requests.parse');

    // Rutas protegidas (auth:sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // Service Requests
        Route::get('/requests', [ServiceRequestController::class, 'index'])->name('requests.index');
        Route::post('/requests', [ServiceRequestController::class, 'store'])->name('requests.store');
        Route::post('/requests/{uuid}/survey', [ServiceRequestController::class, 'survey'])->name('requests.survey');
        Route::delete('/requests/cleanup', [ServiceRequestController::class, 'cleanup'])->name('requests.cleanup');

        // Matching Engine
        Route::post('/requests/{uuid}/match', [MatchSessionController::class, 'createSession'])->name('requests.match');
        Route::post('/match-sessions/{uuid}/cards/{cardId}/accept', [MatchSessionController::class, 'accept'])->name('match-sessions.cards.accept');
        Route::post('/match-sessions/{uuid}/cards/{cardId}/reject', [MatchSessionController::class, 'reject'])->name('match-sessions.cards.reject');
        Route::post('/match-sessions/{uuid}/cards/{cardId}/recover', [MatchSessionController::class, 'recover'])->name('match-sessions.cards.recover');
    });

});
