<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbStatus = 'ok';
        $redisStatus = 'ok';
        $queueStatus = 'ok';

        // 1. Check Database connection
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $dbStatus = 'error';
        }

        // 2. Check Redis connection if redis is the configured queue/cache driver
        try {
            $queueDriver = config('queue.default');
            $cacheDriver = config('cache.default');
            if ($queueDriver === 'redis' || $cacheDriver === 'redis') {
                Redis::connection()->ping();
            }
        } catch (Throwable $e) {
            $redisStatus = 'error';
        }

        // 3. Check Queue processing / table status
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                DB::table('failed_jobs')->limit(1)->get();
            }
        } catch (Throwable $e) {
            $queueStatus = 'error';
        }

        $allHealthy = ($dbStatus === 'ok' && $redisStatus === 'ok' && $queueStatus === 'ok');

        $httpCode = $allHealthy ? 200 : 503;

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'unhealthy',
            'checks' => [
                'database' => $dbStatus,
                'redis' => $redisStatus,
                'queue' => $queueStatus,
            ],
        ], $httpCode);
    }
}
