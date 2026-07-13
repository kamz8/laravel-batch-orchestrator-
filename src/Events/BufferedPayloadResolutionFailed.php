<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

/**
 * Dispatched by {@see \Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload::resolvePayload()}
 * when a {@see \Kamz8\BatchOrchestrator\Support\BufferedPayloadReference} cannot
 * be turned back into the original chunk payload — the Redis key is missing
 * (expired or already cleaned up) or the stored value cannot be safely
 * unserialized.
 *
 * Emission point: inside `resolvePayload()`, immediately before it throws a
 * `RuntimeException` for the same condition. Listeners see this event even
 * though the calling chunk job's `handle()` is about to fail.
 *
 * Guarantees:
 * - Fired exactly once per failed resolution attempt (once per chunk job
 *   attempt/retry that hits a missing or corrupt key).
 * - Always immediately followed by a thrown `RuntimeException` in the same call.
 *
 * Does NOT guarantee:
 * - Any distinction beyond a human-readable `$reason` string between "missing/expired
 *   key" and "corrupt payload" — consumers needing to branch on the exact cause
 *   should match on the `$reason` text or catch the resulting exception instead.
 */
final readonly class BufferedPayloadResolutionFailed
{
    /**
     * @param  string  $key  Redis key that could not be resolved.
     * @param  string|null  $batchKey  The orchestrator's per-dispatch batch key, if present on the reference.
     * @param  int|null  $index  Position of this chunk within the original `getChunks()`, if present on the reference.
     * @param  string  $reason  Short, safe description of the failure (e.g. "missing or expired", "corrupt payload").
     *                          Never includes the raw Redis value.
     */
    public function __construct(
        public string $key,
        public ?string $batchKey,
        public ?int $index,
        public string $reason,
    ) {}
}
