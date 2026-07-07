<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Kamz8\BatchOrchestrator\Concerns\HasBatchProgress;

class FakeProgressModel
{
    use HasBatchProgress;

    public static int $progressUpdatedCalls = 0;

    public function __construct(private readonly int|string $id) {}

    public function getKey(): int|string
    {
        return $this->id;
    }

    protected function onProgressUpdated(): void
    {
        static::$progressUpdatedCalls++;
    }
}
