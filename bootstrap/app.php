<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => null);
        $middleware->api(append: [
            \App\Http\Middleware\CorrelationIdMiddleware::class,
            \App\Http\Middleware\StructuredLoggingMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->report(function (\Throwable $e) {
            try {
                /** @var \App\Domain\Observability\Services\ErrorTrackerInterface $tracker */
                $tracker = app(\App\Domain\Observability\Services\ErrorTrackerInterface::class);
                $tracker->captureException($e);
            } catch (\Throwable $ignored) {}
        });
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No tenés permiso para realizar esta acción.',
                ], 403);
            }
        });
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'No tenés permiso para realizar esta acción.',
                ], 403);
            }
        });
        $exceptions->render(function (\App\Domain\Shared\Exceptions\InvalidStateTransitionException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Transición de estado inválida.',
                ], 409);
            }
        });
        $exceptions->render(function (\App\Domain\Offers\Exceptions\ContactInfoDetectedException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        });
        $exceptions->render(function (\App\Domain\Offers\Exceptions\MaxCounterOfferRoundsExceededException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }
        });
    })->create();
