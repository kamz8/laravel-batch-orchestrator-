<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

/**
 * Dispatched by {@see \Kamz8\BatchOrchestrator\Services\BaseOrchestrator::dispatch()}
 * immediately after a chunk's payload has been successfully written to Redis
 * via `Redis::setex()`, for tasks implementing
 * {@see \Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads}.
 *
 * Emission point: once per chunk, inside the same loop that builds chunk
 * jobs — right after the `SETEX` call for that chunk succeeds, before the
 * corresponding chunk job is queued.
 *
 * Guarantees:
 * - Fired once per buffered chunk, in the same order chunks are consumed
 *   from `getChunks()`.
 * - Does not carry the original payload — only its Redis reference metadata —
 *   so this event is always small and does not double the memory/serialization
 *   cost `ShouldBufferPayloads` exists to avoid.
 *
 * Does NOT guarantee:
 * - That the corresponding chunk job has been queued yet, or will run
 *   before the key's TTL expires — this only reflects the Redis write.
 */
final readonly class BufferedPayloadStored
{
    /**
     * @param  string  $key  Redis key the payload was stored under.
     * @param  string|null  $batchKey  The orchestrator's per-dispatch batch key
     *                                 (shared by all chunks of the same batch, distinct from the Laravel batch ID).
     * @param  int|null  $index  Position of this chunk within `getChunks()`, when the iteration key was an int.
     * @param  int  $ttl  Seconds the key was stored with (`ShouldBufferPayloads::payloadTtl()`).
     */
    public function __construct(
        public string $key,
        public ?string $batchKey,
        public ?int $index,
        public int $ttl,
    ) {}
}
