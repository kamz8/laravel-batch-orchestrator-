<?php

namespace Kamz8\BatchOrchestrator\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFailed;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFinished;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationStarted;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadCleanupCompleted;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadResolutionFailed;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadResolved;
use Kamz8\BatchOrchestrator\Events\BufferedPayloadStored;
use Kamz8\BatchOrchestrator\Services\BatchProcessOrchestrator;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeBufferedChunkableTask;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeBufferedChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeBufferedTaskWithUnsafeState;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeChunkableTask;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeFailingChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeGeneratorChunkableTask;
use Kamz8\BatchOrchestrator\Tests\TestCase;

class OrchestrationEventsTest extends TestCase
{
    private const ORCHESTRATION_EVENTS = [
        BatchOrchestrationStarted::class,
        BatchOrchestrationFinished::class,
        BatchOrchestrationFailed::class,
        BufferedPayloadStored::class,
        BufferedPayloadResolved::class,
        BufferedPayloadResolutionFailed::class,
        BufferedPayloadCleanupCompleted::class,
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.batching.database', 'testbench');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('job_batches', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        FakeChunkJob::$processed = [];
        FakeChunkableTask::$finishedBatchId = null;
        FakeChunkableTask::$failureMessage = null;
        FakeGeneratorChunkableTask::$finishedBatchId = null;
        FakeGeneratorChunkableTask::$failureMessage = null;
        FakeGeneratorChunkableTask::$generatorCalls = 0;
        FakeBufferedChunkJob::$processed = [];
        FakeBufferedChunkJob::$instantiatedChunkData = [];
        FakeBufferedChunkableTask::$finishedBatchId = null;
        FakeBufferedChunkableTask::$failureMessage = null;
        FakeBufferedTaskWithUnsafeState::$finishedBatchId = null;
        FakeBufferedTaskWithUnsafeState::$failureMessage = null;
        FakeBufferedTaskWithUnsafeState::$closureWasInitializedOnCallback = null;
        FakeBufferedTaskWithUnsafeState::$generatorWasInitializedOnCallback = null;
    }

    private function setupRedisMock(array &$redisStorage): void
    {
        Redis::shouldReceive('setex')
            ->andReturnUsing(function ($key, $ttl, $value) use (&$redisStorage) {
                $redisStorage[$key] = $value;

                return true;
            });

        Redis::shouldReceive('get')
            ->andReturnUsing(function ($key) use (&$redisStorage) {
                return $redisStorage[$key] ?? null;
            });

        Redis::shouldReceive('del')
            ->andReturnUsing(function (...$keys) use (&$redisStorage) {
                if (isset($keys[0]) && is_array($keys[0])) {
                    $keys = $keys[0];
                }
                foreach ($keys as $key) {
                    unset($redisStorage[$key]);
                }

                return count($keys);
            });
    }

    public function test_batch_orchestration_started_is_dispatched_exactly_once_with_correct_context(): void
    {
        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeChunkableTask(
            chunks: [['id' => 1], ['id' => 2], ['id' => 3]],
            jobClass: FakeChunkJob::class,
            queueName: 'reports',
        );

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertDispatchedTimes(BatchOrchestrationStarted::class, 1);
        Event::assertDispatched(BatchOrchestrationStarted::class, function (BatchOrchestrationStarted $event) use ($batchId, $task) {
            return $event->context->batchId === $batchId
                && $event->context->taskClass === $task::class
                && $event->context->queue === 'reports'
                && $event->context->totalChunks === 3
                && $event->buffered === false;
        });
    }

    public function test_batch_orchestration_finished_is_dispatched_on_success_and_failed_is_not(): void
    {
        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeChunkableTask(chunks: [['id' => 1], ['id' => 2]], jobClass: FakeChunkJob::class);

        (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertDispatchedTimes(BatchOrchestrationFinished::class, 1);
        Event::assertNotDispatched(BatchOrchestrationFailed::class);
    }

    public function test_batch_orchestration_failed_is_dispatched_on_failure_and_finished_is_not(): void
    {
        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeChunkableTask(chunks: [['id' => 1]], jobClass: FakeFailingChunkJob::class);

        (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertDispatchedTimes(BatchOrchestrationFailed::class, 1);
        Event::assertNotDispatched(BatchOrchestrationFinished::class);
        Event::assertDispatched(BatchOrchestrationFailed::class, function (BatchOrchestrationFailed $event) {
            return $event->exceptionClass === \RuntimeException::class
                && $event->message === 'boom';
        });
    }

    public function test_buffered_payload_stored_is_dispatched_per_chunk_without_original_payload(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);
        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeBufferedChunkableTask(chunks: [['id' => 10], ['id' => 20]]);

        (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertDispatchedTimes(BufferedPayloadStored::class, 2);
        Event::assertDispatched(BufferedPayloadStored::class, function (BufferedPayloadStored $event) {
            $publicProps = get_object_vars($event);

            return ! array_key_exists('payload', $publicProps)
                && ! array_key_exists('chunkData', $publicProps)
                && $event->key !== ''
                && $event->ttl > 0;
        });
    }

    public function test_buffered_payload_cleanup_completed_is_dispatched_with_deleted_key_count_only_for_buffered_tasks(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);
        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeBufferedChunkableTask(chunks: [['id' => 10], ['id' => 20]]);

        (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertDispatchedTimes(BufferedPayloadCleanupCompleted::class, 1);
        Event::assertDispatched(BufferedPayloadCleanupCompleted::class, function (BufferedPayloadCleanupCompleted $event) {
            return $event->deletedKeys === 2;
        });
    }

    public function test_non_buffered_task_never_emits_cleanup_completed(): void
    {
        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeChunkableTask(chunks: [['id' => 1]], jobClass: FakeChunkJob::class);

        (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertNotDispatched(BufferedPayloadCleanupCompleted::class);
    }

    public function test_cleanup_deletes_redis_keys_in_configurable_batches(): void
    {
        config(['batch-orchestrator.payload_cleanup_chunk_size' => 2]);

        $redisStorage = [];

        Redis::shouldReceive('setex')
            ->andReturnUsing(function ($key, $ttl, $value) use (&$redisStorage) {
                $redisStorage[$key] = $value;

                return true;
            });

        Redis::shouldReceive('get')
            ->andReturnUsing(fn ($key) => $redisStorage[$key] ?? null);

        Redis::shouldReceive('del')
            ->times(3)
            ->andReturnUsing(function (...$keys) use (&$redisStorage) {
                foreach ($keys as $key) {
                    unset($redisStorage[$key]);
                }

                return count($keys);
            });

        Event::fake(self::ORCHESTRATION_EVENTS);

        $task = new FakeBufferedChunkableTask(
            chunks: [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4], ['id' => 5]],
        );

        (new BatchProcessOrchestrator)->dispatch($task);

        Event::assertDispatched(BufferedPayloadCleanupCompleted::class, function (BufferedPayloadCleanupCompleted $event) {
            return $event->deletedKeys === 5;
        });
        $this->assertEmpty($redisStorage);
    }

    public function test_events_are_dispatched_for_generator_based_tasks_without_eager_materialization(): void
    {
        Event::fake(self::ORCHESTRATION_EVENTS);

        FakeGeneratorChunkableTask::$generatorCalls = 0;

        $task = new FakeGeneratorChunkableTask(count: 3, jobClass: FakeChunkJob::class);

        (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertSame(1, FakeGeneratorChunkableTask::$generatorCalls);
        Event::assertDispatchedTimes(BatchOrchestrationStarted::class, 1);
        Event::assertDispatched(BatchOrchestrationStarted::class, fn (BatchOrchestrationStarted $e) => $e->context->totalChunks === 3);
        Event::assertDispatchedTimes(BatchOrchestrationFinished::class, 1);
    }

    public function test_unsafe_closure_and_generator_state_is_stripped_before_reaching_callback(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $task = new FakeBufferedTaskWithUnsafeState(chunks: [['id' => 1], ['id' => 2]]);

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertSame($batchId, FakeBufferedTaskWithUnsafeState::$finishedBatchId);
        $this->assertFalse(FakeBufferedTaskWithUnsafeState::$closureWasInitializedOnCallback);
        $this->assertFalse(FakeBufferedTaskWithUnsafeState::$generatorWasInitializedOnCallback);
    }

    public function test_dispatch_works_without_any_registered_listeners(): void
    {
        $task = new FakeChunkableTask(chunks: [['id' => 1], ['id' => 2]], jobClass: FakeChunkJob::class);

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotEmpty($batchId);
        $this->assertSame($batchId, FakeChunkableTask::$finishedBatchId);
        $this->assertCount(2, FakeChunkJob::$processed);
    }

    public function test_real_listeners_observe_cleanup_before_completion_events_in_correct_order(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $order = [];

        Event::listen(BufferedPayloadStored::class, function () use (&$order) {
            $order[] = 'stored';
        });
        Event::listen(BufferedPayloadCleanupCompleted::class, function () use (&$order) {
            $order[] = 'cleanup_completed';
        });
        Event::listen(BatchOrchestrationFinished::class, function () use (&$order) {
            $order[] = 'finished';
        });
        Event::listen(BatchOrchestrationStarted::class, function () use (&$order) {
            $order[] = 'started';
        });

        $task = new FakeBufferedChunkableTask(chunks: [['id' => 1], ['id' => 2]]);

        (new BatchProcessOrchestrator)->dispatch($task);

        // The queue runs synchronously in tests, so the whole batch (and its
        // `then()` callback) executes inside `dispatch()`, before
        // BatchOrchestrationStarted (emitted only after `dispatch()` returns
        // with a real batch ID) can fire. What is always guaranteed — on sync
        // and async queues alike — is the finalization sub-order asserted below.
        $cleanupIndex = array_search('cleanup_completed', $order, true);
        $finishedIndex = array_search('finished', $order, true);

        $this->assertNotFalse($cleanupIndex);
        $this->assertNotFalse($finishedIndex);
        $this->assertLessThan($finishedIndex, $cleanupIndex);
        $this->assertSame(['stored', 'stored', 'cleanup_completed', 'finished', 'started'], $order);
    }
}
