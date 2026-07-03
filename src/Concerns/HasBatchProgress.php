<?php

namespace Kamz8\BatchOrchestrator\Concerns;

use Illuminate\Support\Facades\Redis;

trait HasBatchProgress
{
    public function setProgress(int $percent): void
    {
        Redis::setex($this->progressKey(), $this->progressTtl(), max(0, min(100, $percent)));
        $this->onProgressUpdated();
    }

    public function incrementChunkProgress(int $totalChunks): void
    {
        Redis::setex($this->chunksTotalKey(), $this->progressTtl(), $totalChunks);
        $completed = Redis::incr($this->chunksCompletedKey());
        Redis::expire($this->chunksCompletedKey(), $this->progressTtl());

        $this->setProgress((int) round(($completed / max(1, $totalChunks)) * 80));
    }

    public function currentProgress(): ?int
    {
        $progress = Redis::get($this->progressKey());

        return $progress !== null ? (int) $progress : null;
    }

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

    protected function progressTtl(): int
    {
        return config('batch-orchestrator.progress_ttl', 14400);
    }

    /**
     * Override to keep pre-existing Redis key names when adopting this trait.
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
