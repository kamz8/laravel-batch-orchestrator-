<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Throwable;

class FakeChunkableTask implements ChunkableTask
{
    public static ?string $finishedBatchId = null;

    public static ?string $failureMessage = null;

    /**
     * @param  array<int, mixed>  $chunks
     */
    public function __construct(
        private readonly array $chunks,
        private readonly string $jobClass = FakeChunkJob::class,
        private readonly string $queueName = 'default',
    ) {}

    public function getChunks(): array
    {
        return $this->chunks;
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
