<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Support;

/**
 * Lightweight queue-safe pointer to a payload staged in Redis.
 *
 * Queue jobs should receive this object instead of the original heavy chunk.
 * The referenced Redis value must remain available until the job has completed
 * all retries. If the key expires or is cleaned up before a retry runs,
 * resolving the reference fails clearly and the surrounding batch should follow
 * its normal failure path.
 */
final class BufferedPayloadReference
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $batchKey = null,
        public readonly ?int $index = null,
    ) {}
}
