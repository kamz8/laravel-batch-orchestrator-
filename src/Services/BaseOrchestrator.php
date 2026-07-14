<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Services;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFailed;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFinished;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationStarted;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadCleanupCompleted;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadStored;
use Kamz8\BatchOrchestrator\Support\BatchContext;
use Kamz8\BatchOrchestrator\Support\BufferedPayloadReference;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use Traversable;

/**
 * Builds and dispatches a {@see \Illuminate\Bus\Batch} from a {@see ChunkableTask}.
 *
 * One chunk job instance is created per element of `$task->getChunks()`; the
 * batch name is the task's short class name (visible in `job_batches` /
 * Horizon), and it runs on `$task->queue()`. Bind
 * {@see \Kamz8\BatchOrchestrator\Services\BatchProcessOrchestrator} (the
 * concrete subclass) via the container rather than depending on this
 * abstract class directly.
 */
abstract class BaseOrchestrator
{
    /**
     * Set by the `->catch()` callback below, read back after `dispatch()`
     * potentially re-throws on Laravel 10/12/13 (see the try/catch in
     * {@see self::dispatch()}). Must be a static property, not a `use (&$var)`
     * closure capture: Illuminate\Bus\PendingBatch wraps `then`/`catch` in
     * {@see \Laravel\SerializableClosure\SerializableClosure} unconditionally
     * (even on `queue.default=sync`), and the batch repository reloads/
     * unserializes a fresh copy of that closure to invoke it — so a captured
     * PHP variable reference never reaches the real caller. A static property
     * survives that round-trip because the closure keeps its original class
     * scope (`self`) after being unserialized.
     */
    private static ?string $lastCaughtBatchId = null;

    /**
     * Dispatch the task's chunks as a single batch.
     *
     * Requires the `job_batches` table (`php artisan queue:batches-table`)
     * and a queue connection that supports batching.
     *
     * @return string ID of the dispatched {@see \Illuminate\Bus\Batch}, persisted by callers
     *                (e.g. on a `batch_id` column) so it can be looked up or cancelled later
     *                via {@see \Illuminate\Support\Facades\Bus::findBatch()}.
     */
    public function dispatch(ChunkableTask $task): string
    {
        $jobClass = $task->getChunkJobClass();
        $payloadKeys = [];
        $buffered = $task instanceof ShouldBufferPayloads;

        $pendingBatch = Bus::batch([]);
        $jobBuffer = [];
        $flushSize = (int) (config('batch-orchestrator.payload_chunk_flush_size') ?? 100);
        $flushSize = max(1, $flushSize);
        $batchKey = (string) Str::uuid();

        $chunks = $task->getChunks();
        $totalChunks = 0;

        foreach ($chunks as $index => $chunkData) {
            $payload = $chunkData;
            $totalChunks++;

            if ($buffered) {
                $key = sprintf('%s:%s:%s', $task->payloadKeyPrefix(), $batchKey, $index);
                $ttl = $task->payloadTtl();

                Redis::setex($key, $ttl, serialize($chunkData));

                $payloadKeys[] = $key;
                $chunkIndex = is_int($index) ? $index : null;
                $payload = new BufferedPayloadReference($key, $batchKey, $chunkIndex);

                Event::dispatch(new BufferedPayloadStored($key, $batchKey, $chunkIndex, $ttl));
            }

            $jobBuffer[] = new $jobClass($payload);

            if (count($jobBuffer) >= $flushSize) {
                $pendingBatch->add($jobBuffer);
                $jobBuffer = [];
            }
        }

        if ($jobBuffer !== []) {
            $pendingBatch->add($jobBuffer);
        }

        $callbackTask = $buffered
            ? $this->taskWithoutBufferedPayloads($task, $chunks)
            : $task;

        $baseContext = new BatchContext(
            taskClass: get_class($task),
            queue: $task->queue(),
            batchId: null,
            batchKey: $batchKey,
            totalChunks: $totalChunks,
        );

        $cleanupChunkSize = max(1, (int) (config('batch-orchestrator.payload_cleanup_chunk_size') ?? 500));

        $cleanupPayloads = static function () use ($payloadKeys, $cleanupChunkSize): int {
            foreach (array_chunk($payloadKeys, $cleanupChunkSize) as $keysChunk) {
                Redis::del(...$keysChunk);
            }

            return count($payloadKeys);
        };

        self::$lastCaughtBatchId = null;

        $pendingBatch = $pendingBatch
            ->then(function (Batch $batch) use ($callbackTask, $cleanupPayloads, $baseContext, $buffered): void {
                $context = $baseContext->withBatchId($batch->id);
                $deletedKeys = $cleanupPayloads();

                if ($buffered) {
                    Event::dispatch(new BufferedPayloadCleanupCompleted($context, $deletedKeys));
                }

                Event::dispatch(new BatchOrchestrationFinished($context));
                $callbackTask->onBatchFinished($batch->id);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($callbackTask, $cleanupPayloads, $baseContext, $buffered): void {
                self::$lastCaughtBatchId = $batch->id;
                $context = $baseContext->withBatchId($batch->id);
                $deletedKeys = $cleanupPayloads();

                if ($buffered) {
                    Event::dispatch(new BufferedPayloadCleanupCompleted($context, $deletedKeys));
                }

                $code = $e->getCode();

                Event::dispatch(new BatchOrchestrationFailed(
                    $context,
                    get_class($e),
                    $e->getMessage(),
                    is_int($code) && $code !== 0 ? $code : null,
                ));
                $callbackTask->onBatchFailed($e);
            })
            ->name(class_basename($task))
            ->onQueue($task->queue());

        try {
            $batch = $pendingBatch->dispatch();
        } catch (Throwable $e) {
            // On `queue.default=sync`, Illuminate\Bus\Batch::add() re-throws a
            // chunk job's exception after already running our ->catch() callback
            // above on Laravel 10/12/13 (Laravel 11 alone swallows it internally).
            // self::$lastCaughtBatchId is only set once that callback has run, so
            // by this point onBatchFailed()/BatchOrchestrationFailed have already
            // fired correctly — re-throwing here would just be a redundant,
            // Laravel-version-dependent exception leaking into the caller.
            // Anything else (e.g. the batch record itself failing to persist)
            // leaves it null and is rethrown untouched.
            $failedBatchId = self::$lastCaughtBatchId;
            self::$lastCaughtBatchId = null;

            if ($failedBatchId === null) {
                throw $e;
            }

            Event::dispatch(new BatchOrchestrationStarted($baseContext->withBatchId($failedBatchId), $buffered));

            return $failedBatchId;
        }

        Event::dispatch(new BatchOrchestrationStarted($baseContext->withBatchId($batch->id), $buffered));

        return $batch->id;
    }

    private function taskWithoutBufferedPayloads(ChunkableTask $task, iterable $chunks): ChunkableTask
    {
        $reflection = new ReflectionClass($task);
        $copy = $reflection->newInstanceWithoutConstructor();

        foreach ($this->propertiesFor($reflection) as $property) {
            if (! $property->isInitialized($task)) {
                continue;
            }

            $value = $property->getValue($task);

            if ($this->isChunkSource($value, $chunks) || $this->isUnsafeCallbackState($value)) {
                continue;
            }

            $property->setValue($copy, $value);
        }

        return $copy;
    }

    /**
     * @return array<int, ReflectionProperty>
     */
    private function propertiesFor(ReflectionClass $reflection): array
    {
        $properties = [];

        do {
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
                $properties[] = $property;
            }
        } while ($reflection = $reflection->getParentClass());

        return $properties;
    }

    private function isChunkSource(mixed $value, iterable $chunks): bool
    {
        if (is_array($value) && is_array($chunks)) {
            return $value === $chunks;
        }

        return is_object($value) && $value === $chunks;
    }

    private function isUnsafeCallbackState(mixed $value): bool
    {
        if ($value instanceof Traversable || $value instanceof Closure) {
            return true;
        }

        try {
            serialize($value);
        } catch (Throwable) {
            return true;
        }

        return false;
    }
}
