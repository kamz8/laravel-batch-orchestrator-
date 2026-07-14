<?php

namespace Kamz8\BatchOrchestrator\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Kamz8\BatchOrchestrator\Events\BatchProgressUpdated;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeProgressModel;
use Kamz8\BatchOrchestrator\Tests\TestCase;

class BatchProgressEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeProgressModel::$progressUpdatedCalls = 0;
    }

    public function test_set_progress_dispatches_batch_progress_updated_with_set_type(): void
    {
        Event::fake([BatchProgressUpdated::class]);
        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:progress:7', 14400, 60);

        (new FakeProgressModel(7))->setProgress(60);

        Event::assertDispatchedTimes(BatchProgressUpdated::class, 1);
        Event::assertDispatched(BatchProgressUpdated::class, function (BatchProgressUpdated $event) {
            return $event->subjectKey === '7'
                && $event->keyPrefix === 'batch-orchestrator'
                && $event->progress === 60
                && $event->previousProgress === null
                && $event->updateType === 'set';
        });
    }

    public function test_increment_chunk_progress_dispatches_batch_progress_updated_with_increment_type(): void
    {
        Event::fake([BatchProgressUpdated::class]);

        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:chunks:total:8', 14400, 4);
        Redis::shouldReceive('incr')->once()->with('batch-orchestrator:chunks:completed:8')->andReturn(2);
        Redis::shouldReceive('expire')->once()->with('batch-orchestrator:chunks:completed:8', 14400);
        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:progress:8', 14400, 40);

        (new FakeProgressModel(8))->incrementChunkProgress(4);

        Event::assertDispatchedTimes(BatchProgressUpdated::class, 1);
        Event::assertDispatched(BatchProgressUpdated::class, function (BatchProgressUpdated $event) {
            return $event->subjectKey === '8'
                && $event->progress === 40
                && $event->updateType === 'increment';
        });
    }

    public function test_progress_event_carries_stable_subject_key_string_not_the_host_object(): void
    {
        Event::fake([BatchProgressUpdated::class]);
        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:progress:9', 14400, 50);

        (new FakeProgressModel(9))->setProgress(50);

        Event::assertDispatched(BatchProgressUpdated::class, function (BatchProgressUpdated $event) {
            return is_string($event->subjectKey) && $event->subjectKey === '9';
        });
    }

    public function test_on_progress_updated_hook_still_fires_alongside_the_event(): void
    {
        Event::fake([BatchProgressUpdated::class]);
        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:progress:10', 14400, 30);

        (new FakeProgressModel(10))->setProgress(30);

        $this->assertSame(1, FakeProgressModel::$progressUpdatedCalls);
    }
}
