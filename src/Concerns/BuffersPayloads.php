<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Concerns;

/**
 * Default configuration-backed implementation for Redis staged payload tasks.
 *
 * Use this on a task implementing
 * {@see \Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads}. Override the
 * methods when a specific workload needs a longer retry window or an isolated
 * Redis namespace. The TTL must cover the full lifecycle from dispatch through
 * the last possible queued retry; otherwise workers will fail with a missing
 * payload error instead of processing the original chunk.
 */
trait BuffersPayloads
{
    public function payloadTtl(): int
    {
        return (int) (config('batch-orchestrator.payload_ttl') ?? 14400);
    }

    public function payloadKeyPrefix(): string
    {
        return (string) (config('batch-orchestrator.payload_key_prefix') ?? 'batch-orchestrator:payload');
    }
}
