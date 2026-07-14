<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

use Kamz8\BatchOrchestrator\Support\BatchContext;
use Throwable;

/**
 * Dispatched exactly once, the first time any chunk job in the batch throws —
 * from the Laravel Batch `catch()` callback.
 *
 * Emission order within that callback:
 * buffered payload cleanup (if any) → {@see \Kamz8\BatchOrchestrator\Events\BufferedPayloadCleanupCompleted}
 * (buffered tasks only) → **this event** → `ChunkableTask::onBatchFailed()`.
 *
 * The original {@see Throwable} is deliberately not carried on this event:
 * exceptions can hold unserializable state (open resources, closures,
 * request/response objects, etc.), which would make the event unsafe to
 * queue-and-serialize. Only its class, message, and code are exposed.
 *
 * Guarantees:
 * - Fired exactly once per batch, and never together with
 *   {@see BatchOrchestrationFinished} for the same batch (mutually exclusive).
 * - `$context->batchId` is the failed batch's real ID.
 *
 * Does NOT guarantee:
 * - That every chunk job has stopped running; Laravel's batch `catch()`
 *   fires on the first failure while other chunk jobs may still be
 *   in-flight, depending on queue/worker concurrency.
 * - Full exception fidelity — only class/message/code are preserved, not
 *   the stack trace, previous exception chain, or exception properties.
 */
final readonly class BatchOrchestrationFailed
{
    /**
     * @param  BatchContext  $context  Snapshot of the dispatch; `batchId` is always set here.
     * @param  string  $exceptionClass  `::class` of the throwable that failed the batch.
     * @param  string  $message  `getMessage()` of the throwable.
     * @param  int|null  $code  `getCode()` of the throwable, or null when it was falsy/zero.
     */
    public function __construct(
        public BatchContext $context,
        public string $exceptionClass,
        public string $message,
        public ?int $code = null,
    ) {}
}
