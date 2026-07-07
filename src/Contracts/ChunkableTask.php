<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Contracts;

use Throwable;

/**
 * A unit of work that can be split into independently-queued chunk jobs and
 * dispatched as a single {@see \Illuminate\Bus\Batch} via
 * {@see \Kamz8\BatchOrchestrator\Services\BaseOrchestrator::dispatch()}.
 *
 * Lifecycle: `getChunks()` + `getChunkJobClass()` build the batch, the batch
 * runs on `queue()`, then exactly one of `onBatchFinished()` /
 * `onBatchFailed()` fires once (success requires every chunk job to succeed;
 * a single unhandled chunk exception routes to `onBatchFailed()` instead).
 *
 * Implementations are typically dispatched with the constructor holding the
 * domain record (e.g. a `Report` model) they operate on — see the chunk
 * job/task recipe in `docs/AI_GUIDE.md`.
 */
interface ChunkableTask
{
    /**
     * Data for each chunk; one yielded value is passed to the chunk job constructor.
     *
     * Chunk job constructors receive a single positional argument, so each
     * element must be self-contained (no shared mutable state between chunks).
     * Implementations may return an array for small workloads or a generator
     * for streaming large datasets without materializing every chunk up front.
     *
     * @return iterable<int, mixed>
     */
    public function getChunks(): iterable;

    /**
     * Fully-qualified class name of the job processing a single chunk.
     *
     * The class must accept the chunk payload as its sole constructor
     * argument and should use {@see \Illuminate\Bus\Batchable} so it can
     * check `$this->batch()?->cancelled()`.
     *
     * @return class-string
     */
    public function getChunkJobClass(): string;

    /**
     * Queue name the batch (and its follow-up jobs, e.g. a merge job
     * dispatched from `onBatchFinished()`) should run on.
     */
    public function queue(): string;

    /**
     * Called once, after every chunk job in the batch has completed
     * successfully. Typically dispatches a merge/finalization job.
     *
     * @param  string  $batchId  ID of the completed {@see \Illuminate\Bus\Batch}.
     */
    public function onBatchFinished(string $batchId): void;

    /**
     * Called once, the first time any chunk job in the batch throws.
     * Typically purges partial chunk output and marks the domain record failed.
     *
     * @param  Throwable  $e  The exception thrown by the failing chunk job.
     */
    public function onBatchFailed(Throwable $e): void;
}
