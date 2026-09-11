<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\ServiceRequests\ServiceRequestController;
use App\Http\Controllers\Api\V1\Matching\MatchSessionController;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Health check público
    Route::get('/health', \App\Http\Controllers\Api\V1\HealthCheckController::class)->name('health');

    $throttleLogin = 'throttle:' . env('RATE_LIMIT_LOGIN', '5,1');
    $throttleCatalog = 'throttle:' . env('RATE_LIMIT_CATALOG', '60,1');
    $throttleProtected = 'throttle:' . env('RATE_LIMIT_PROTECTED', '60,1');

    // Auth — público
    Route::prefix('auth')->name('auth.')->group(function () use ($throttleLogin, $throttleProtected) {
        Route::post('/register', [AuthController::class, 'register'])->middleware($throttleLogin)->name('register');
        Route::post('/login',    [AuthController::class, 'login'])->middleware($throttleLogin)->name('login');

        Route::middleware(['auth:sanctum', $throttleProtected])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/become-provider', [AuthController::class, 'becomeProvider'])->name('logout');
            Route::get('/me',      [ProfileController::class, 'me'])->name('me');
        });
    });

    // Catálogo — público
    Route::middleware($throttleCatalog)->group(function () {
        Route::get('/categories', [\App\Http\Controllers\Api\V1\Providers\CategoryController::class, 'index'])->name('categories.index');
        Route::get('/providers', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'index'])->name('providers.index');
        Route::get('/providers/{uuid}', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'show'])->name('providers.show');
        Route::get('/providers/{uuid}/reviews', [\App\Http\Controllers\Api\V1\Providers\ProviderController::class, 'reviews'])->name('providers.reviews');

        // Parser de solicitudes — público
        Route::post('/requests/parse', [\App\Http\Controllers\Api\V1\ServiceRequests\ParseRequestController::class, 'parse'])->name('requests.parse');
    });

    // Rutas protegidas (auth:sanctum)
    Route::middleware(['auth:sanctum', $throttleProtected])->group(function () {
        // Device Management (Push Notifications Infrastructure)
        Route::post('/devices', [\App\Http\Controllers\Api\V1\Devices\UserDeviceController::class, 'store'])->name('devices.store');
        Route::delete('/devices', [\App\Http\Controllers\Api\V1\Devices\UserDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::delete('/devices/{token}', [\App\Http\Controllers\Api\V1\Devices\UserDeviceController::class, 'destroy'])->name('devices.destroy.token');

        // Service Requests
        Route::get('/requests', [ServiceRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/{uuid}', [ServiceRequestController::class, 'show'])->name('requests.show');
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
        Route::patch('/works/{id}', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'update'])->name('works.update');
        Route::post('/works/{id}/cancel', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'cancel'])->name('works.cancel');
        Route::post('/works/{workId}/rate', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'rate'])->name('works.rate');
        Route::post('/works/{id}/final-quote', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'submitFinalQuote'])->name('works.final-quote.submit');
        Route::post('/works/{id}/final-quote/confirm', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'confirmFinalQuote'])->name('works.final-quote.confirm');
        Route::post('/works/{id}/final-quote/reject', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'rejectFinalQuote'])->name('works.final-quote.reject');
        Route::get('/works/{id}/location', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'location'])->name('works.location');
        Route::get('/works/{id}/progress', [\App\Http\Controllers\Api\V1\Works\WorkController::class, 'progress'])->name('works.progress');
        Route::get('/works/{id}/quotes', [\App\Http\Controllers\Api\V1\Works\WorkQuoteController::class, 'index'])->name('works.quotes.index');
        Route::post('/works/{id}/quotes', [\App\Http\Controllers\Api\V1\Works\WorkQuoteController::class, 'store'])->name('works.quotes.store');
        Route::post('/works/{id}/quotes/{quote_uuid}/accept', [\App\Http\Controllers\Api\V1\Works\WorkQuoteController::class, 'accept'])->name('works.quotes.accept');
        Route::post('/works/{id}/quotes/{quote_uuid}/reject', [\App\Http\Controllers\Api\V1\Works\WorkQuoteController::class, 'reject'])->name('works.quotes.reject');
        Route::get('/works/{id}/provider-location', [\App\Http\Controllers\Api\V1\Provider\ProviderLocationController::class, 'show'])->name('works.provider-location.show');

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

        // Provider Profile & Onboarding
        Route::get('/provider/profile', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'show'])->name('provider.profile.show');
        Route::post('/provider/profile', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'update'])->name('provider.profile.store');
        Route::patch('/provider/profile', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'update'])->name('provider.profile.update');
        Route::post('/provider/profile/portfolio', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'storePortfolioItem'])->name('provider.profile.portfolio.store');
        Route::delete('/provider/profile/portfolio/{uuid}', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'deletePortfolioItem'])->name('provider.profile.portfolio.destroy');
        Route::post('/provider/profile/documents', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'uploadDocument'])->name('provider.profile.documents.store');
        Route::post('/provider/profile/submit-verification', [\App\Http\Controllers\Api\V1\Provider\ProviderProfileController::class, 'submitVerification'])->name('provider.profile.submit-verification');

        Route::post('/provider/availability', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'availability'])->name('provider.availability');
        Route::post('/providers/me/location', [\App\Http\Controllers\Api\V1\Provider\ProviderLocationController::class, 'update'])->name('provider.location.update');
        Route::get('/provider/work-requests', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'workRequests'])->name('provider.work-requests');
        Route::get('/provider/agenda', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'agenda'])->name('provider.agenda');
        Route::post('/provider/work-requests/{id}/confirm', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'confirmWorkRequest'])->name('provider.work-requests.confirm');
        Route::post('/provider/work-requests/{id}/decline', [\App\Http\Controllers\Api\V1\Provider\ProviderDashboardController::class, 'declineWorkRequest'])->name('provider.work-requests.decline');

        // KYC Document Management (Task 2.1)
        Route::post('/kyc/documents', [\App\Http\Controllers\Api\V1\Kyc\KycDocumentController::class, 'upload'])->name('kyc.documents.upload');
        Route::get('/kyc/status', [\App\Http\Controllers\Api\V1\Kyc\KycDocumentController::class, 'status'])->name('kyc.status');
        Route::get('/kyc/documents/{uuid}/signed-url', [\App\Http\Controllers\Api\V1\Kyc\KycDocumentController::class, 'signedUrl'])->name('kyc.documents.signed-url');
        Route::delete('/kyc/documents/{uuid}', [\App\Http\Controllers\Api\V1\Kyc\KycDocumentController::class, 'destroy'])->name('kyc.documents.destroy');

        // Admin KYC Verification
        Route::post('/admin/kyc/documents/{uuid}/verify', [\App\Http\Controllers\Api\V1\Admin\AdminKycController::class, 'verify'])->name('admin.kyc.verify');
        Route::post('/admin/kyc/documents/{uuid}/reject', [\App\Http\Controllers\Api\V1\Admin\AdminKycController::class, 'reject'])->name('admin.kyc.reject');

        // Admin Verification Panel
        Route::get('/admin/providers/pending', [\App\Http\Controllers\Api\V1\Admin\AdminProviderController::class, 'pending'])->name('admin.providers.pending');
        Route::get('/admin/providers/{uuid}', [\App\Http\Controllers\Api\V1\Admin\AdminProviderController::class, 'show'])->name('admin.providers.show');
        Route::post('/admin/providers/{uuid}/verify', [\App\Http\Controllers\Api\V1\Admin\AdminProviderController::class, 'verify'])->name('admin.providers.verify');
        Route::post('/admin/providers/{uuid}/reject', [\App\Http\Controllers\Api\V1\Admin\AdminProviderController::class, 'reject'])->name('admin.providers.reject');
        Route::post('/admin/providers/{uuid}/suspend', [\App\Http\Controllers\Api\V1\Admin\AdminProviderController::class, 'suspend'])->name('admin.providers.suspend');
        Route::post('/admin/providers/{uuid}/reactivate', [\App\Http\Controllers\Api\V1\Admin\AdminProviderController::class, 'reactivate'])->name('admin.providers.reactivate');

        // Onboarding Identity (Didit KYC)
        Route::prefix('onboarding/identity')->name('onboarding.identity.')->group(function () {
            Route::post('/start', [\App\Http\Controllers\Api\V1\Onboarding\IdentityController::class, 'start'])->name('start');
            Route::get('/status', [\App\Http\Controllers\Api\V1\Onboarding\IdentityController::class, 'status'])->name('status');
        });

        // Admin MVU (Professional Verification Panel)
        Route::prefix('admin/mvu')->name('admin.mvu.')->group(function () {
            Route::get('/pending', [\App\Http\Controllers\Api\V1\Admin\MVUController::class, 'pending'])->name('pending');
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\Admin\MVUController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [\App\Http\Controllers\Api\V1\Admin\MVUController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject', [\App\Http\Controllers\Api\V1\Admin\MVUController::class, 'reject'])->name('reject');
            Route::post('/{id}/request-data', [\App\Http\Controllers\Api\V1\Admin\MVUController::class, 'requestData'])->name('request-data');
        });
    });

    // Webhooks públicos (con firma HMAC verificada por controller)
    Route::post('/webhooks/didit/identity-verified', [\App\Http\Controllers\Api\V1\Webhooks\IdentityWebhookController::class, 'handleVerified'])->name('webhooks.didit.verified');

});
