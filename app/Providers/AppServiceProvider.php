<?php

namespace App\Providers;

use App\Infrastructure\Persistence\Eloquent\ConversationModel;
use App\Infrastructure\Persistence\Eloquent\ServiceRequestModel;
use App\Infrastructure\Persistence\Eloquent\WorkModel;
use App\Policies\ConversationPolicy;
use App\Policies\ServiceRequestPolicy;
use App\Policies\WorkPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ServiceRequestModel::class, ServiceRequestPolicy::class);
        Gate::policy(WorkModel::class, WorkPolicy::class);
        Gate::policy(ConversationModel::class, ConversationPolicy::class);
    }
}
