<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FakeChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, mixed> */
    public static array $processed = [];

    public function __construct(public readonly mixed $chunkData) {}

    public function handle(): void
    {
        static::$processed[] = $this->chunkData;
    }
}
