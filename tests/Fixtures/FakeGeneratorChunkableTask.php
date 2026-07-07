<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Throwable;

class FakeGeneratorChunkableTask implements ChunkableTask
{
    public static ?string $finishedBatchId = null;

    public static ?string $failureMessage = null;

    public static int $generatorCalls = 0;

    public function __construct(
        private readonly int $count = 3,
        private readonly string $jobClass = FakeChunkJob::class,
        private readonly string $queueName = 'default',
    ) {}

    public function getChunks(): iterable
    {
        self::$generatorCalls++;
        for ($i = 1; $i <= $this->count; $i++) {
            yield ['id' => $i];
        }
    }

    public function getChunkJobClass(): string
    {
        return $this->jobClass;
    }

    public function queue(): string
    {
        return $this->queueName;
    }

    public function onBatchFinished(string $batchId): void
    {
        static::$finishedBatchId = $batchId;
    }

    public function onBatchFailed(Throwable $e): void
    {
        static::$failureMessage = $e->getMessage();
    }
}
