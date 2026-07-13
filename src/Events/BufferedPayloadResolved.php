<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

/**
 * Dispatched by {@see \Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload::resolvePayload()}
 * after a {@see \Kamz8\BatchOrchestrator\Support\BufferedPayloadReference} has
 * been successfully read from Redis and unserialized back into the original
 * chunk payload.
 *
 * Emission point: inside `resolvePayload()`, after `unserialize()` succeeds,
 * typically called from a chunk job's `handle()`.
 *
 * Guarantees:
 * - Fired only on successful resolution; a missing/corrupt payload dispatches
 *   {@see BufferedPayloadResolutionFailed} instead and never this event.
 * - Does not carry the resolved payload itself — only the reference's metadata.
 *
 * Does NOT guarantee:
 * - That the chunk job's `handle()` will go on to succeed — resolution is
 *   only the first step of processing a buffered chunk.
 */
final readonly class BufferedPayloadResolved
{
    /**
     * @param  string  $key  Redis key the payload was resolved from.
     * @param  string|null  $batchKey  The orchestrator's per-dispatch batch key, if present on the reference.
     * @param  int|null  $index  Position of this chunk within the original `getChunks()`, if present on the reference.
     */
    public function __construct(
        public string $key,
        public ?string $batchKey,
        public ?int $index,
    ) {}
}
