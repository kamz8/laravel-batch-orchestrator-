<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

use Kamz8\BatchOrchestrator\Support\BatchContext;

/**
 * Dispatched exactly once, immediately after {@see \Illuminate\Support\Facades\Bus::batch()}
 * has successfully dispatched the chunk jobs for a {@see \Kamz8\BatchOrchestrator\Contracts\ChunkableTask}.
 *
 * Emission point: after `->dispatch()` returns a real {@see \Illuminate\Bus\Batch},
 * never before. Emitting it earlier would imply a batch exists when dispatch
 * could still fail (e.g. the queue connection rejecting the payload), so
 * `$context->batchId` is always a genuine, persisted batch ID.
 *
 * Guarantees:
 * - Emitted synchronously on the same request/process that called `dispatch()`.
 * - Fired exactly once per `dispatch()` call.
 * - `$context->totalChunks` reflects the actual number of chunk jobs created,
 *   counted while lazily consuming `getChunks()` (works for arrays and
 *   generators alike, without a second pass or eager materialization).
 *
 * Does NOT guarantee:
 * - That any chunk job has started or finished executing. On synchronous
 *   queues (`queue.default = sync`) chunk jobs — and even the whole batch's
 *   finalization — may have already run by the time this event is observed
 *   by a listener, because `dispatch()` runs them inline before returning.
 *   On real (async) queue connections this event precedes chunk execution.
 * - Anything about domain-level completion; this only concerns the
 *   orchestration/dispatch step.
 */
final readonly class BatchOrchestrationStarted
{
    /**
     * @param  BatchContext  $context  Snapshot of the dispatch; `batchId` is always set here.
     * @param  bool  $buffered  Whether chunk payloads were staged in Redis via
     *                          {@see \Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads}
     *                          instead of being embedded directly in the queued jobs.
     */
    public function __construct(
        public BatchContext $context,
        public bool $buffered,
    ) {}
}
