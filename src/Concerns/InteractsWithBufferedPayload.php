<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Concerns;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadResolutionFailed;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadResolved;
use Kamz8\BatchOrchestrator\Support\BufferedPayloadReference;
use RuntimeException;

/**
 * Resolves queue payloads that may be lightweight Redis references.
 *
 * Chunk jobs can call `resolvePayload($this->payload)` at the start of `handle()`.
 * Non-buffered payloads are returned unchanged for backwards compatibility.
 * Buffered references are read from Redis and unserialized. A missing key means
 * the payload was cleaned up or its TTL expired before this attempt/retry, and a
 * corrupt value means the staged payload cannot safely be reconstructed; both
 * conditions throw a clear exception so Laravel marks the chunk job failed.
 */
trait InteractsWithBufferedPayload
{
    protected function resolvePayload(mixed $payload): mixed
    {
        if (! $payload instanceof BufferedPayloadReference) {
            return $payload;
        }

        $serialized = Redis::get($payload->key);

        if ($serialized === null) {
            $reason = 'missing or expired';

            Event::dispatch(new BufferedPayloadResolutionFailed($payload->key, $payload->batchKey, $payload->index, $reason));

            throw new RuntimeException("Buffered payload missing or expired in Redis: {$payload->key}");
        }

        $resolved = @unserialize((string) $serialized);

        if ($resolved === false && $serialized !== serialize(false)) {
            $reason = 'corrupt payload';

            Event::dispatch(new BufferedPayloadResolutionFailed($payload->key, $payload->batchKey, $payload->index, $reason));

            throw new RuntimeException("Buffered payload is corrupt in Redis: {$payload->key}");
        }

        Event::dispatch(new BufferedPayloadResolved($payload->key, $payload->batchKey, $payload->index));

        return $resolved;
    }
}
