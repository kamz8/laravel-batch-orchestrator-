<?php

namespace Kamz8\BatchOrchestrator\Tests\Unit;

use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFailed;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFinished;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationStarted;
use Kamz8\BatchOrchestrator\Events\BatchProgressUpdated;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadCleanupCompleted;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadResolutionFailed;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadResolved;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadStored;
use Kamz8\BatchOrchestrator\Support\BatchContext;
use Kamz8\BatchOrchestrator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EventSerializationTest extends TestCase
{
    /**
     * @return array<string, array{0: object}>
     */
    public static function eventProvider(): array
    {
        $context = new BatchContext(
            taskClass: 'App\\Jobs\\SomeTask',
            queue: 'reports',
            batchId: 'batch-123',
            batchKey: 'batch-key-abc',
            totalChunks: 5,
        );

        return [
            'BatchOrchestrationStarted' => [new BatchOrchestrationStarted($context, true)],
            'BatchOrchestrationFinished' => [new BatchOrchestrationFinished($context)],
            'BatchOrchestrationFailed' => [new BatchOrchestrationFailed($context, \RuntimeException::class, 'boom', 500)],
            'BatchProgressUpdated' => [new BatchProgressUpdated('42', 'batch-orchestrator', 60, null, 'increment')],
            'BufferedPayloadStored' => [new BufferedPayloadStored('key:1', 'batch-key-abc', 1, 14400)],
            'BufferedPayloadResolved' => [new BufferedPayloadResolved('key:1', 'batch-key-abc', 1)],
            'BufferedPayloadResolutionFailed' => [new BufferedPayloadResolutionFailed('key:1', 'batch-key-abc', 1, 'missing or expired')],
            'BufferedPayloadCleanupCompleted' => [new BufferedPayloadCleanupCompleted($context, 5)],
        ];
    }

    #[DataProvider('eventProvider')]
    public function test_event_survives_a_serialize_unserialize_roundtrip(object $event): void
    {
        $serialized = serialize($event);
        $restored = unserialize($serialized);

        $this->assertEquals($event, $restored);
        $this->assertInstanceOf($event::class, $restored);
    }

    public function test_batch_context_itself_survives_a_serialize_unserialize_roundtrip(): void
    {
        $context = new BatchContext('App\\Jobs\\SomeTask', 'reports', 'batch-123', 'batch-key-abc', 5);

        $restored = unserialize(serialize($context));

        $this->assertEquals($context, $restored);
    }
}
