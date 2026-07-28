<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;

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

});
