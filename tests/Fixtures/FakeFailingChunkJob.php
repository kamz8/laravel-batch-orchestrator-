<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class FakeFailingChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly mixed $chunkData) {}

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}
