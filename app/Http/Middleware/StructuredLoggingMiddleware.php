<?php

namespace App\Http\Middleware;

use App\Infrastructure\Observability\Context\CorrelationContext;
use App\Infrastructure\Observability\Services\SanitizerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StructuredLoggingMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $status = $response->getStatusCode();

        // Log requests that end in error (4xx / 5xx)
        if ($status >= 400) {
            $correlationId = CorrelationContext::get();
            $sanitizedParams = SanitizerService::sanitizePayload($request->except(['password', 'password_confirmation', 'token']));

            $logPayload = [
                'event' => 'http_request_error',
                'correlation_id' => $correlationId,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $status,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'request_params' => $sanitizedParams,
            ];

            if ($status >= 500) {
                Log::error(json_encode($logPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } elseif ($status === 422) {
                // Business rule validation rejection
                $logPayload['event'] = 'business_rule_rejection_validation';
                Log::warning(json_encode($logPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                Log::warning(json_encode($logPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $response;
    }
}
