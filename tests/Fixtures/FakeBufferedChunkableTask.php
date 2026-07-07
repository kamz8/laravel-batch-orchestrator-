<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Kamz8\BatchOrchestrator\Concerns\BuffersPayloads;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;
use Throwable;

class FakeBufferedChunkableTask implements ChunkableTask, ShouldBufferPayloads
{
    use BuffersPayloads;

    public static ?string $finishedBatchId = null;

    public static ?string $failureMessage = null;

    private iterable $chunks;

    private string $jobClass;

    private string $queueName;

    public function __construct(
        iterable $chunks,
        string $jobClass = FakeBufferedChunkJob::class,
        string $queueName = 'default',
    ) {
        $this->chunks = $chunks;
        $this->jobClass = $jobClass;
        $this->queueName = $queueName;
    }

    public function getChunks(): iterable
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

    public function __serialize(): array
    {
        return [
            'jobClass' => $this->jobClass,
            'queueName' => $this->queueName,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->jobClass = $data['jobClass'];
        $this->queueName = $data['queueName'];
        $this->chunks = [];
    }
}
