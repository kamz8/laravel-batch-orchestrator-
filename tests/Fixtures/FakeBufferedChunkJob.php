<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload;

class FakeBufferedChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithBufferedPayload, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, mixed> */
    public static array $processed = [];

    /** @var array<int, mixed> */
    public static array $instantiatedChunkData = [];

    public static ?\Closure $onHandle = null;

    public function __construct(public readonly mixed $chunkData)
    {
        static::$instantiatedChunkData[] = $this->chunkData;
    }

    public function handle(): void
    {
        $resolved = $this->resolvePayload($this->chunkData);
        static::$processed[] = $resolved;
        if (static::$onHandle) {
            (static::$onHandle)($resolved);
        }
    }
}
