<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Contracts;

/**
 * Marks a chunkable task whose heavy chunk payloads should be staged outside
 * the queue payload before dispatching chunk jobs.
 *
 * Lifecycle: the dispatcher stores each serialized chunk under a Redis key with
 * {@see self::payloadTtl()}, queues only a lightweight reference, and later the
 * chunk job resolves that reference through
 * {@see \Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload}.
 *
 * Retry caveat: a queued retry can only resolve the payload while the Redis key
 * still exists. Choose a TTL longer than the maximum queue delay, retry window,
 * and expected batch runtime. Missing or expired keys are treated as failed work
 * so the batch can route through its normal failure callback.
 */
interface ShouldBufferPayloads
{
    /**
     * Seconds a staged payload may live in Redis while jobs are waiting/running.
     */
    public function payloadTtl(): int;

    /**
     * Redis key prefix used for staged payloads for this task.
     */
    public function payloadKeyPrefix(): string;
}
