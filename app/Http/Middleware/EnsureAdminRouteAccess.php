<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRouteAccess
{
    /**
     * Handle an incoming request.
     * Fails closed: any request targeting admin routes requires an authenticated user with admin role.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin/*', 'api/admin/*', 'api/*/admin/*', '*/admin/*')) {
            $user = $request->user() ?? auth('sanctum')->user();

            if (! $user || ! $user->hasRole('admin')) {
                return response()->json([
                    'message' => 'No tenés permiso para realizar esta acción.',
                ], 403);
            }
        }

        return $next($request);
    }
}
