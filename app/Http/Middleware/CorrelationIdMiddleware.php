<?php

namespace App\Http\Middleware;

use App\Infrastructure\Observability\Context\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $headerName = config('observability.correlation_header', 'X-Correlation-ID');
        $correlationId = $request->header($headerName);

        if (empty($correlationId) || !is_string($correlationId)) {
            $correlationId = (string) Str::uuid();
        }

        CorrelationContext::set($correlationId);
        $request->headers->set($headerName, $correlationId);

        // Bind correlation_id to Laravel logger context
        Log::shareContext([
            'correlation_id' => $correlationId,
        ]);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set($headerName, $correlationId);

        return $response;
    }
}
