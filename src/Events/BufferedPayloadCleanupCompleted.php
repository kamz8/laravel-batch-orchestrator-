<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

use Kamz8\BatchOrchestrator\Support\BatchContext;

/**
 * Dispatched by {@see \Kamz8\BatchOrchestrator\Services\BaseOrchestrator::dispatch()}
 * once the Redis cleanup of a buffered task's staged payload keys has fully
 * completed — whether the batch succeeded or failed.
 *
 * Emission point: after every chunked `Redis::del()` batch has run (see
 * `batch-orchestrator.payload_cleanup_chunk_size`), inside both the Laravel
 * Batch `then()` and `catch()` callbacks, before
 * {@see BatchOrchestrationFinished} / {@see BatchOrchestrationFailed}
 * respectively. Only emitted for tasks implementing
 * {@see \Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads} — non-buffered
 * tasks have no keys to clean up and never emit this event.
 *
 * Guarantees:
 * - Fired exactly once per batch (success or failure path, never both),
 *   only for buffered tasks.
 * - `$deletedKeys` is the total count of keys targeted for deletion across
 *   all cleanup chunks, not necessarily verified as physically absent from
 *   Redis (a `DEL` on an already-expired key still counts).
 *
 * Does NOT guarantee:
 * - A full list of the deleted keys — for large batches that list could be
 *   arbitrarily large, so only the count is carried.
 */
final readonly class BufferedPayloadCleanupCompleted
{
    /**
     * @param  BatchContext  $context  Snapshot of the dispatch; `batchId` is always set here.
     * @param  int  $deletedKeys  Total number of Redis keys targeted for deletion.
     */
    public function __construct(
        public BatchContext $context,
        public int $deletedKeys,
    ) {}
}
