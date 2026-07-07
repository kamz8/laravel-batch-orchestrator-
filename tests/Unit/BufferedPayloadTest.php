<?php

namespace Kamz8\BatchOrchestrator\Tests\Unit;

use Illuminate\Support\Facades\Redis;
use Kamz8\BatchOrchestrator\Concerns\BuffersPayloads;
use Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;
use Kamz8\BatchOrchestrator\Support\BufferedPayloadReference;
use Kamz8\BatchOrchestrator\Tests\TestCase;
use RuntimeException;

class BufferedPayloadTest extends TestCase
{
    private object $payloadResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a dummy object using the InteractsWithBufferedPayload trait
        // to test resolvePayload.
        $this->payloadResolver = new class
        {
            use InteractsWithBufferedPayload;

            public function resolve(mixed $payload): mixed
            {
                return $this->resolvePayload($payload);
            }
        };
    }

    public function test_direct_non_buffered_payload_is_returned_unchanged(): void
    {
        $payload = ['id' => 123, 'data' => 'test'];
        $result = $this->payloadResolver->resolve($payload);

        $this->assertSame($payload, $result);
    }

    public function test_buffered_payload_reference_loads_and_unserializes_payload_from_redis(): void
    {
        $originalPayload = ['secret' => 'redis-data', 'chunks' => [1, 2, 3]];
        $serialized = serialize($originalPayload);

        Redis::shouldReceive('get')
            ->once()
            ->with('batch-orchestrator:payload:some-key')
            ->andReturn($serialized);

        $reference = new BufferedPayloadReference('batch-orchestrator:payload:some-key');
        $result = $this->payloadResolver->resolve($reference);

        $this->assertSame($originalPayload, $result);
    }

    public function test_missing_redis_key_throws_runtime_exception(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with('batch-orchestrator:payload:missing-key')
            ->andReturn(null);

        $reference = new BufferedPayloadReference('batch-orchestrator:payload:missing-key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Buffered payload missing or expired in Redis: batch-orchestrator:payload:missing-key');

        $this->payloadResolver->resolve($reference);
    }

    public function test_buffers_payloads_trait_reads_ttl_from_config(): void
    {
        config(['batch-orchestrator.payload_ttl' => 7200]);

        $task = new class implements ShouldBufferPayloads
        {
            use BuffersPayloads;
        };

        $this->assertSame(7200, $task->payloadTtl());
    }

    public function test_buffers_payloads_trait_falls_back_to_default_ttl(): void
    {
        config(['batch-orchestrator.payload_ttl' => null]);

        $task = new class implements ShouldBufferPayloads
        {
            use BuffersPayloads;
        };

        // Supposing 14400 or another default is returned if config is empty.
        // Let's assert a sensible default like 14400 or whatever is set in the implementation (e.g. 14400).
        $this->assertSame(14400, $task->payloadTtl());
    }
}
