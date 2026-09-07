<?php

namespace App\Listeners;

use App\Domain\Observability\Services\ErrorTrackerInterface;
use App\Infrastructure\Observability\Context\CorrelationContext;
use App\Infrastructure\Observability\Services\SanitizerService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogFailedQueueJobListener
{
    public function __construct(
        private readonly ErrorTrackerInterface $errorTracker
    ) {}

    public function handle(JobFailed $event): void
    {
        $correlationId = CorrelationContext::get();
        $jobName = $event->job->resolveName();
        $queue = $event->job->getQueue() ?: 'default';
        $exception = $event->exception;

        $logData = [
            'event' => 'queue_job_permanent_failure',
            'correlation_id' => $correlationId,
            'job_name' => $jobName,
            'queue' => $queue,
            'connection' => $event->connectionName,
            'exception_message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
        ];

        Log::error(json_encode($logData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Report permanent job failure to external error tracking service
        try {
            $this->errorTracker->captureException($exception, SanitizerService::sanitizePayload($logData));
        } catch (Throwable $e) {
            // Must never crash job cleanup
        }
    }
}
