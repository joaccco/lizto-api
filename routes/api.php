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

    $throttleLogin = 'throttle:' . env('RATE_LIMIT_LOGIN', '5,1');
    $throttleCatalog = 'throttle:' . env('RATE_LIMIT_CATALOG', '60,1');
    $throttleProtected = 'throttle:' . env('RATE_LIMIT_PROTECTED', '60,1');

    // Auth — público
    Route::prefix('auth')->name('auth.')->group(function () use ($throttleLogin, $throttleProtected) {
        Route::post('/register', [AuthController::class, 'register'])->middleware($throttleLogin)->name('register');
        Route::post('/login',    [AuthController::class, 'login'])->middleware($throttleLogin)->name('login');

        Route::middleware(['auth:sanctum', $throttleProtected])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('/me',      [ProfileController::class, 'me'])->name('me');
        });
    });

    // Catálogo — público
    Route::middleware($throttleCatalog)->group(function () {
        Route::get('/categories', [\App\Http\Controllers\Api\V1\Providers\CategoryController::class, 'index'])->name('categories.index');
        Route::get('/providers', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'index'])->name('providers.index');
        Route::get('/providers/{uuid}', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'show'])->name('providers.show');

        // Parser de solicitudes — público
        Route::post('/requests/parse', [\App\Http\Controllers\Api\V1\ServiceRequests\ParseRequestController::class, 'parse'])->name('requests.parse');
    });

    // Rutas protegidas (auth:sanctum)
    Route::middleware(['auth:sanctum', $throttleProtected])->group(function () {
        // Service Requests
        Route::get('/requests', [ServiceRequestController::class, 'index'])->name('requests.index');
        Route::post('/requests', [ServiceRequestController::class, 'store'])->name('requests.store');
        Route::post('/requests/{uuid}/survey', [ServiceRequestController::class, 'survey'])->name('requests.survey');
        Route::post('/requests/{uuid}/cancel', [ServiceRequestController::class, 'cancel'])->name('requests.cancel');
        Route::delete('/requests/cleanup', [ServiceRequestController::class, 'cleanup'])->name('requests.cleanup');

        // Clarification Engine (Bloque B)
        Route::post('/clarification/service-requests', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'store'])->name('clarification.store');
        Route::post('/clarification/service-requests/{id}/classify', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'classifyOverride'])->name('clarification.classify');
        Route::get('/clarification/service-requests/{id}/next-question', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'getNextQuestion'])->name('clarification.next-question');
        Route::post('/clarification/service-requests/{id}/answers', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'answerQuestion'])->name('clarification.answer');
        Route::delete('/clarification/service-requests/{id}/answers/{questionId}', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'removeAnswer'])->name('clarification.remove-answer');
        Route::post('/clarification/service-requests/{id}/attachments', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'storeAttachment'])->name('clarification.attachment');
        Route::get('/clarification/service-requests/{id}/brief', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'getBrief'])->name('clarification.brief.get');
        Route::post('/clarification/service-requests/{id}/brief/confirm', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'confirmBrief'])->name('clarification.brief.confirm');
        Route::patch('/clarification/service-requests/{id}/brief', [\App\Http\Controllers\Api\V1\Clarification\ClarificationController::class, 'patchBrief'])->name('clarification.brief.patch');

        // Matching Engine
        Route::post('/requests/{uuid}/match', [MatchSessionController::class, 'createSession'])->name('requests.match');
        Route::post('/match-sessions/{uuid}/cards/{cardId}/accept', [MatchSessionController::class, 'accept'])->name('match-sessions.cards.accept');
        Route::post('/match-sessions/{uuid}/cards/{cardId}/reject', [MatchSessionController::class, 'reject'])->name('match-sessions.cards.reject');
        Route::post('/match-sessions/{uuid}/cards/{cardId}/recover', [MatchSessionController::class, 'recover'])->name('match-sessions.cards.recover');

        // Works
        Route::post('/works/{id}/complete', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'complete'])->name('works.complete');
        Route::post('/works/{id}/cancel', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'cancel'])->name('works.cancel');
        Route::post('/works/{workId}/rate', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'rate'])->name('works.rate');

        // Offers (Bloque A)
        Route::post('/service-requests/{id}/offers', [\App\Http\Controllers\Api\V1\Offers\OfferController::class, 'store'])->name('offers.store');
        Route::post('/offers/{id}/counter', [\App\Http\Controllers\Api\V1\Offers\OfferController::class, 'counter'])->name('offers.counter');
        Route::post('/offers/{id}/accept', [\App\Http\Controllers\Api\V1\Offers\OfferController::class, 'accept'])->name('offers.accept');
        Route::post('/offers/{id}/reject', [\App\Http\Controllers\Api\V1\Offers\OfferController::class, 'reject'])->name('offers.reject');
        Route::post('/offers/{id}/questions', [\App\Http\Controllers\Api\V1\Offers\OfferController::class, 'askQuestion'])->name('offers.questions.store');
        Route::post('/offer-questions/{id}/answer', [\App\Http\Controllers\Api\V1\Offers\OfferController::class, 'answerQuestion'])->name('offer-questions.answer');

        // Conversations & Chat (Bloque A)
        Route::get('/conversations/{id}/messages', [\App\Http\Controllers\Api\V1\Conversations\ConversationController::class, 'messages'])->name('conversations.messages.index');
        Route::post('/conversations/{id}/messages', [\App\Http\Controllers\Api\V1\Conversations\ConversationController::class, 'sendMessage'])->name('conversations.messages.store');

        // Provider Profile & Dashboard
        Route::get('/provider/profile', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'show'])->name('provider.profile.show');
        Route::patch('/provider/profile', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'update'])->name('provider.profile.update');
        Route::post('/provider/availability', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'availability'])->name('provider.availability');
        Route::get('/provider/work-requests', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'workRequests'])->name('provider.work-requests');
        Route::post('/provider/work-requests/{id}/confirm', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'confirmWorkRequest'])->name('provider.work-requests.confirm');
        Route::post('/provider/work-requests/{id}/decline', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'declineWorkRequest'])->name('provider.work-requests.decline');
    });

});
