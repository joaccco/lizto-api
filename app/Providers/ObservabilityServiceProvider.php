<?php

namespace App\Providers;

use App\Domain\Observability\Services\ErrorTrackerInterface;
use App\Infrastructure\Observability\Services\ConfigurableErrorTracker;
use App\Listeners\LogFailedQueueJobListener;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorTrackerInterface::class, function () {
            return new ConfigurableErrorTracker();
        });
    }

    public function boot(): void
    {
        Event::listen(JobFailed::class, LogFailedQueueJobListener::class);
    }
}
