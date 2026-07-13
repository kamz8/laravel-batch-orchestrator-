<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Closure;
use Generator;
use Kamz8\BatchOrchestrator\Concerns\BuffersPayloads;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;
use Throwable;

/**
 * Holds a Closure and a Generator (Traversable) property in addition to the
 * chunk source, to prove BaseOrchestrator::taskWithoutBufferedPayloads()
 * strips unsafe callback state instead of copying it onto the callback task.
 */
class FakeBufferedTaskWithUnsafeState implements ChunkableTask, ShouldBufferPayloads
{
    use BuffersPayloads;

    public static ?string $finishedBatchId = null;

    public static ?string $failureMessage = null;

    public static ?bool $closureWasInitializedOnCallback = null;

    public static ?bool $generatorWasInitializedOnCallback = null;

    private Closure $closure;

    private Generator $extraGenerator;

    public function __construct(
        private readonly array $chunks,
        private readonly string $jobClass = FakeBufferedChunkJob::class,
        private readonly string $queueName = 'default',
    ) {
        $this->closure = static fn () => throw new \RuntimeException('closure must not be invoked from a callback copy');
        $this->extraGenerator = (static function () {
            yield 'unused';
        })();
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
        static::$closureWasInitializedOnCallback = (new \ReflectionProperty($this, 'closure'))->isInitialized($this);
        static::$generatorWasInitializedOnCallback = (new \ReflectionProperty($this, 'extraGenerator'))->isInitialized($this);
    }

    public function onBatchFailed(Throwable $e): void
    {
        static::$failureMessage = $e->getMessage();
        static::$closureWasInitializedOnCallback = (new \ReflectionProperty($this, 'closure'))->isInitialized($this);
        static::$generatorWasInitializedOnCallback = (new \ReflectionProperty($this, 'extraGenerator'))->isInitialized($this);
    }
}
