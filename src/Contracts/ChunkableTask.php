<?php

namespace Kamz8\BatchOrchestrator\Contracts;

use Throwable;

interface ChunkableTask
{
    /**
     * Data for each chunk; one array element is passed to the chunk job constructor.
     *
     * @return array<int, mixed>
     */
    public function getChunks(): array;

    /**
     * Fully-qualified class name of the job processing a single chunk.
     *
     * @return class-string
     */
    public function getChunkJobClass(): string;

    /**
     * Queue name the batch (and its follow-up jobs) should run on.
     */
    public function queue(): string;

    public function onBatchFinished(string $batchId): void;

    public function onBatchFailed(Throwable $e): void;
}
