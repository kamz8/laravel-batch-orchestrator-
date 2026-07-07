<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Kamz8\BatchOrchestrator\Concerns\BuffersPayloads;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;
use Throwable;

class FakeBufferedTaskWithCallbackState implements ChunkableTask, ShouldBufferPayloads
{
    use BuffersPayloads;

    /** @var array<string, mixed>|null */
    public static ?array $finishedMetadata = null;

    /** @var array<string, mixed>|null */
    public static ?array $failureMetadata = null;

    public function __construct(
        private readonly array $chunks,
        private readonly array $callbackMetadata,
    ) {}

    public function getChunks(): iterable
    {
        return $this->chunks;
    }

    public function getChunkJobClass(): string
    {
        return FakeBufferedChunkJob::class;
    }

    public function queue(): string
    {
        return 'default';
    }

    public function onBatchFinished(string $batchId): void
    {
        static::$finishedMetadata = $this->callbackMetadata;
    }

    public function onBatchFailed(Throwable $e): void
    {
        static::$failureMetadata = $this->callbackMetadata;
    }
}
