<?php

namespace Kamz8\BatchOrchestrator\Tests\Fixtures;

use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload;

class FakeFailingBufferedChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithBufferedPayload, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly mixed $chunkData) {}

    public function handle(): void
    {
        $this->resolvePayload($this->chunkData);
        throw new Exception('boom from buffered job');
    }
}
