<?php

namespace Kamz8\BatchOrchestrator\Services;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Throwable;

abstract class BaseOrchestrator
{
    public function dispatch(ChunkableTask $task): string
    {
        $jobClass = $task->getChunkJobClass();

        $jobs = collect($task->getChunks())
            ->map(fn ($chunkData) => new $jobClass($chunkData))
            ->all();

        $batch = Bus::batch($jobs)
            ->then(fn (Batch $batch) => $task->onBatchFinished($batch->id))
            ->catch(fn (Batch $batch, Throwable $e) => $task->onBatchFailed($e))
            ->name(class_basename($task))
            ->onQueue($task->queue())
            ->dispatch();

        return $batch->id;
    }
}
