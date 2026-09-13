<?php

namespace App\Http\Middleware;

use App\Domain\Trust\Services\BanService;
use App\Domain\Users\Enums\UserStatus;
use App\Infrastructure\Persistence\Eloquent\UserModel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotSuspended
{
    public function __construct(protected BanService $banService)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof UserModel) {
            $user->refresh();
            $isBanned = $this->banService->isBanned($user);
            $isActive = $user->status === UserStatus::Active;

            // Falla cerrado: si el estado no se puede determinar, no es Active, o está baneado/suspendido -> 403
            if (!$isActive || $isBanned) {
                return response()->json([
                    'message' => 'Tu cuenta se encuentra suspendida.',
                    'errors'  => ['account' => ['Cuenta suspendida.']],
                ], 403);
            }
        }

        return $next($request);
    }
}
