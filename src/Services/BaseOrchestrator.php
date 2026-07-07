<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Services;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;
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

        $pendingBatch = Bus::batch([]);
        $jobBuffer = [];
        $flushSize = (int) (config('batch-orchestrator.payload_chunk_flush_size') ?? 100);
        $flushSize = max(1, $flushSize);
        $batchKey = (string) Str::uuid();

        $chunks = $task->getChunks();

        foreach ($chunks as $index => $chunkData) {
            $payload = $chunkData;

            if ($task instanceof ShouldBufferPayloads) {
                $key = sprintf('%s:%s:%s', $task->payloadKeyPrefix(), $batchKey, $index);

                Redis::setex($key, $task->payloadTtl(), serialize($chunkData));

                $payloadKeys[] = $key;
                $payload = new BufferedPayloadReference($key, $batchKey, is_int($index) ? $index : null);
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

        $callbackTask = $task instanceof ShouldBufferPayloads
            ? $this->taskWithoutBufferedPayloads($task, $chunks)
            : $task;

        $cleanupPayloads = static function () use ($payloadKeys): void {
            if ($payloadKeys !== []) {
                Redis::del(...$payloadKeys);
            }
        };

        $batch = $pendingBatch
            ->then(function (Batch $batch) use ($callbackTask, $cleanupPayloads): void {
                $cleanupPayloads();
                $callbackTask->onBatchFinished($batch->id);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($callbackTask, $cleanupPayloads): void {
                $cleanupPayloads();
                $callbackTask->onBatchFailed($e);
            })
            ->name(class_basename($task))
            ->onQueue($task->queue())
            ->dispatch();

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
