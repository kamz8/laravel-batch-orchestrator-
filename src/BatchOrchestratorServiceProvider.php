<?php

namespace Kamz8\BatchOrchestrator;

use Illuminate\Support\ServiceProvider;

class BatchOrchestratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/batch-orchestrator.php', 'batch-orchestrator');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/batch-orchestrator.php' => config_path('batch-orchestrator.php'),
            ], 'batch-orchestrator-config');
        }
    }
}
