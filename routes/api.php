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

        // Works
        Route::post('/works/{id}/complete', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'complete'])->name('works.complete');

        // Provider Dashboard
        Route::post('/provider/availability', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'availability'])->name('provider.availability');
        Route::get('/provider/work-requests', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'workRequests'])->name('provider.work-requests');
        Route::post('/provider/work-requests/{id}/confirm', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'confirmWorkRequest'])->name('provider.work-requests.confirm');
        Route::post('/provider/work-requests/{id}/decline', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'declineWorkRequest'])->name('provider.work-requests.decline');
    });

});
