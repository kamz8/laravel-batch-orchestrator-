<?php

namespace Kamz8\BatchOrchestrator;

use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered via `composer.json` (`extra.laravel.providers`). Merges
 * `config/batch-orchestrator.php` under the `batch-orchestrator` key so
 * {@see \Kamz8\BatchOrchestrator\Concerns\HasBatchProgress::progressTtl()}
 * resolves even without publishing the config file.
 */
class BatchOrchestratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/batch-orchestrator.php', 'batch-orchestrator');
    }

    /**
     * Registers `php artisan vendor:publish --tag=batch-orchestrator-config`.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/batch-orchestrator.php' => config_path('batch-orchestrator.php'),
            ], 'batch-orchestrator-config');
        }
    }
}
