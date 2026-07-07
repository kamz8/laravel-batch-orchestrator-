<?php

namespace Kamz8\BatchOrchestrator\Concerns;

use Illuminate\Support\Facades\Redis;

/**
 * Adds Redis-backed percent progress tracking to any class exposing a
 * primary-key-like `getKey()` (Eloquent models satisfy this natively).
 *
 * Designed so callers can reserve the tail of the 0-100 range for a
 * finalization/merge step that runs after all chunks are done:
 * `incrementChunkProgress()` only ever reaches 80%, leaving 80-100% for the
 * host class to set explicitly (e.g. `setProgress(85)`, `setProgress(95)`)
 * before marking the work complete/failed.
 *
 * Host classes typically override {@see self::onProgressUpdated()} (to
 * broadcast a domain event) and {@see self::progressKeyPrefix()} (to keep
 * pre-existing Redis key names when retrofitting this trait onto an
 * existing model).
 */
trait HasBatchProgress
{
    /**
     * Persist an absolute progress percentage (clamped to 0-100) and invoke
     * {@see self::onProgressUpdated()}.
     */
    public function setProgress(int $percent): void
    {
        Redis::setex($this->progressKey(), $this->progressTtl(), max(0, min(100, $percent)));
        $this->onProgressUpdated();
    }

    /**
     * Atomically record that one more of `$totalChunks` chunk jobs has
     * completed, and derive the overall percentage from it.
     *
     * Safe to call concurrently from multiple queue workers: the completed
     * count uses `INCR`, so no two chunk jobs can double-count. The
     * resulting progress is capped at 80% (`completed / totalChunks * 80`)
     * regardless of `$totalChunks`, reserving the rest for a subsequent
     * merge/finalization step.
     *
     * @param  int  $totalChunks  Total number of chunk jobs in the batch; must match
     *                            the value passed on every call for the same batch.
     */
    public function incrementChunkProgress(int $totalChunks): void
    {
        Redis::setex($this->chunksTotalKey(), $this->progressTtl(), $totalChunks);
        $completed = Redis::incr($this->chunksCompletedKey());
        Redis::expire($this->chunksCompletedKey(), $this->progressTtl());

        $this->setProgress((int) round(($completed / max(1, $totalChunks)) * 80));
    }

    /**
     * @return int|null The last percentage written by {@see self::setProgress()},
     *                  or null if no progress key exists (never started, or
     *                  already cleared via {@see self::clearProgressKeys()}).
     */
    public function currentProgress(): ?int
    {
        $progress = Redis::get($this->progressKey());

        return $progress !== null ? (int) $progress : null;
    }

    /**
     * Delete all Redis keys backing progress/chunk-count tracking for this
     * instance. Call when the underlying work completes, fails, or is
     * cancelled, so {@see self::currentProgress()} reverts to null.
     */
    public function clearProgressKeys(): void
    {
        Redis::del($this->progressKey(), $this->chunksCompletedKey(), $this->chunksTotalKey());
    }

    /**
     * Hook invoked after every setProgress() write; override to broadcast a domain event.
     */
    protected function onProgressUpdated(): void
    {
        //
    }

    /**
     * TTL (seconds) applied to every Redis key this trait writes.
     * Override to bypass the `batch-orchestrator.progress_ttl` config value.
     */
    protected function progressTtl(): int
    {
        return config('batch-orchestrator.progress_ttl', 14400);
    }

    /**
     * Prefix used to build the three Redis keys below, as
     * `{prefix}:progress:{id}`, `{prefix}:chunks:completed:{id}`, `{prefix}:chunks:total:{id}`.
     *
     * Override to keep pre-existing Redis key names when adopting this trait
     * on a model that already tracked progress under a different prefix.
     */
    protected function progressKeyPrefix(): string
    {
        return 'batch-orchestrator';
    }

    protected function progressKey(): string
    {
        return "{$this->progressKeyPrefix()}:progress:{$this->getKey()}";
    }

    protected function chunksCompletedKey(): string
    {
        return "{$this->progressKeyPrefix()}:chunks:completed:{$this->getKey()}";
    }

    protected function chunksTotalKey(): string
    {
        return "{$this->progressKeyPrefix()}:chunks:total:{$this->getKey()}";
    }
}
