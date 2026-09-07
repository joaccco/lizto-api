<?php

namespace App\Domain\Observability\Services;

use Throwable;

interface ErrorTrackerInterface
{
    /**
     * Determine if the error tracking service is active and configured.
     */
    public function isEnabled(): bool;

    /**
     * Capture and report an exception to the external tracking service.
     * Must never throw an exception or crash business execution.
     *
     * @param Throwable $exception
     * @param array<string, mixed> $context
     * @return string|null Captured event ID or null
     */
    public function captureException(Throwable $exception, array $context = []): ?string;

    /**
     * Capture and report a message or error event.
     *
     * @param string $message
     * @param string $level 'error'|'warning'|'info'
     * @param array<string, mixed> $context
     * @return string|null Captured event ID or null
     */
    public function captureMessage(string $message, string $level = 'error', array $context = []): ?string;
}
