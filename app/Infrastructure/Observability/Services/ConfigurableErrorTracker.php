<?php

namespace App\Infrastructure\Observability\Services;

use App\Domain\Observability\Services\ErrorTrackerInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfigurableErrorTracker implements ErrorTrackerInterface
{
    public function isEnabled(): bool
    {
        $enabled = (bool) config('observability.error_tracker.enabled', false);
        $dsn = config('observability.error_tracker.dsn');

        return $enabled && !empty($dsn);
    }

    public function captureException(Throwable $exception, array $context = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $sanitizedContext = SanitizerService::sanitizePayload($context);

        try {
            // If Sentry SDK class exists (e.g. Sentry\Laravel\Integration or \Sentry\captureException)
            if (function_exists('Sentry\captureException')) {
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($exception, $sanitizedContext) {
                    $scope->setExtras($sanitizedContext);
                    \Sentry\captureException($exception);
                });
                return 'sentry_captured';
            }

            // Fallback for configured DSN without native Sentry package installed
            Log::error('[ERROR_TRACKER] Exception captured: ' . $exception->getMessage(), [
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'context' => $sanitizedContext,
            ]);

            return 'local_captured_' . md5($exception->getMessage() . microtime());
        } catch (Throwable $e) {
            // MANDATORY REQUIREMENT: Observability MUST NEVER crash business operations.
            // If the error tracking service fails, catch silently and log fallback.
            Log::warning('[ERROR_TRACKER] Failed to send exception to external service: ' . $e->getMessage());
            return null;
        }
    }

    public function captureMessage(string $message, string $level = 'error', array $context = []): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $sanitizedContext = SanitizerService::sanitizePayload($context);

        try {
            if (function_exists('Sentry\captureMessage')) {
                \Sentry\captureMessage($message, \Sentry\Severity::fromError($level));
                return 'sentry_msg_captured';
            }

            Log::log($level, '[ERROR_TRACKER] Message captured: ' . $message, $sanitizedContext);
            return 'local_msg_captured';
        } catch (Throwable $e) {
            Log::warning('[ERROR_TRACKER] Failed to send message to external service: ' . $e->getMessage());
            return null;
        }
    }
}
