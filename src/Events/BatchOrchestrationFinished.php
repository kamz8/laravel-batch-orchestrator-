<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

use Kamz8\BatchOrchestrator\Support\BatchContext;

/**
 * Dispatched exactly once, when every chunk job in the batch has completed
 * successfully — from the Laravel Batch `then()` callback.
 *
 * Emission order within that callback:
 * buffered payload cleanup (if any) → {@see \Kamz8\BatchOrchestrator\Events\BufferedPayloadCleanupCompleted}
 * (buffered tasks only) → **this event** → `ChunkableTask::onBatchFinished()`.
 * Cleanup and this event both run before the task's own callback so that
 * listeners observing orchestration-level completion never race the
 * domain-level side effects `onBatchFinished()` triggers (e.g. dispatching a
 * merge job).
 *
 * Guarantees:
 * - Fired exactly once per batch, and never together with
 *   {@see BatchOrchestrationFailed} for the same batch (mutually exclusive).
 * - `$context->batchId` is the completed batch's real ID.
 *
 * Does NOT guarantee:
 * - That the domain process is fully finished. This marks only the end of
 *   the chunk-job phase — a merge/finalization job dispatched from
 *   `onBatchFinished()` may still be running or even queued. Do not treat
 *   this event as "the report/import/etc. is done".
 */
final readonly class BatchOrchestrationFinished
{
    public function __construct(
        public BatchContext $context,
    ) {}
}
