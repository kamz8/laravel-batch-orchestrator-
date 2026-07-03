<?php

namespace Kamz8\BatchOrchestrator\Tests;

use Kamz8\BatchOrchestrator\BatchOrchestratorServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [BatchOrchestratorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }
}
