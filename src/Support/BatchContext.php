<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Support;

/**
 * Lightweight, immutable, serializable snapshot of a batch dispatch.
 *
 * Carries only stable scalar/nullable data describing a {@see \Kamz8\BatchOrchestrator\Contracts\ChunkableTask}
 * dispatch, so it is safe to pass into queue-safe lifecycle events without
 * dragging along the task instance itself (which may hold a generator,
 * a `Closure`, `Traversable` state, or other unserializable/heavy state).
 *
 * `batchId` is only known once {@see \Illuminate\Support\Facades\Bus::batch()}
 * has actually dispatched; before that point, use {@see self::withBatchId()}
 * to produce a copy carrying the real ID rather than mutating in place.
 */
final readonly class BatchContext
{
    public function __construct(
        public string $taskClass,
        public string $queue,
        public ?string $batchId = null,
        public ?string $batchKey = null,
        public ?int $totalChunks = null,
    ) {}

    /**
     * Return a copy of this context carrying the given Laravel {@see \Illuminate\Bus\Batch} ID.
     */
    public function withBatchId(string $batchId): self
    {
        return new self(
            taskClass: $this->taskClass,
            queue: $this->queue,
            batchId: $batchId,
            batchKey: $this->batchKey,
            totalChunks: $this->totalChunks,
        );
    }
}
