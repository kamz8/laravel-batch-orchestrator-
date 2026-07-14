<?php

namespace Kamz8\BatchOrchestrator\Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Kamz8\BatchOrchestrator\Services\BatchProcessOrchestrator;
use Kamz8\BatchOrchestrator\Support\BufferedPayloadReference;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeBufferedChunkableTask;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeBufferedChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeBufferedTaskWithCallbackState;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeChunkableTask;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeFailingBufferedChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeFailingChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeGeneratorChunkableTask;
use Kamz8\BatchOrchestrator\Tests\TestCase;

class BatchProcessOrchestratorTest extends TestCase
{
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
        FakeBufferedChunkJob::$onHandle = null;
        FakeBufferedChunkableTask::$finishedBatchId = null;
        FakeBufferedChunkableTask::$failureMessage = null;
        FakeBufferedTaskWithCallbackState::$finishedMetadata = null;
        FakeBufferedTaskWithCallbackState::$failureMetadata = null;
    }

    public function test_dispatch_runs_every_chunk_job_and_calls_on_batch_finished(): void
    {
        $task = new FakeChunkableTask(
            chunks: [['id' => 1], ['id' => 2], ['id' => 3]],
            jobClass: FakeChunkJob::class,
            queueName: 'reports',
        );

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotEmpty($batchId);
        $this->assertSame($batchId, FakeChunkableTask::$finishedBatchId);
        $this->assertCount(3, FakeChunkJob::$processed);
        $this->assertNull(FakeChunkableTask::$failureMessage);
    }

    public function test_dispatch_calls_on_batch_failed_when_a_chunk_job_throws(): void
    {
        $task = new FakeChunkableTask(chunks: [['id' => 1]], jobClass: FakeFailingChunkJob::class);

        // Regression: on `queue.default=sync`, Illuminate\Bus\Batch::add() only
        // swallows a chunk job's exception internally on Laravel 11 — Laravel
        // 10/12/13 re-throw it after our ->catch() callback has already run.
        // dispatch() must not let that framework-version-dependent exception
        // escape; it must always return the batch ID, never throw here.
        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotEmpty($batchId);
        $this->assertNotNull(FakeChunkableTask::$failureMessage);
        $this->assertStringContainsString('boom', FakeChunkableTask::$failureMessage);
        $this->assertNull(FakeChunkableTask::$finishedBatchId);
    }

    public function test_generator_returning_task_dispatches_all_chunks(): void
    {
        FakeGeneratorChunkableTask::$generatorCalls = 0;
        FakeGeneratorChunkableTask::$finishedBatchId = null;
        FakeGeneratorChunkableTask::$failureMessage = null;

        $task = new FakeGeneratorChunkableTask(
            count: 3,
            jobClass: FakeChunkJob::class,
            queueName: 'reports',
        );

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotEmpty($batchId);
        $this->assertSame($batchId, FakeGeneratorChunkableTask::$finishedBatchId);
        $this->assertCount(3, FakeChunkJob::$processed);
        $this->assertNull(FakeGeneratorChunkableTask::$failureMessage);
    }

    public function test_generator_is_iterated_exactly_once_and_not_eagerly_materialized(): void
    {
        FakeGeneratorChunkableTask::$generatorCalls = 0;

        $task = new FakeGeneratorChunkableTask(
            count: 2,
            jobClass: FakeChunkJob::class,
        );

        (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertSame(1, FakeGeneratorChunkableTask::$generatorCalls);
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

    public function test_buffered_task_stores_each_chunk_in_redis_and_queues_only_buffered_payload_reference(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $task = new FakeBufferedChunkableTask(
            chunks: [['id' => 10], ['id' => 20]],
        );

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotEmpty($batchId);
        $this->assertCount(2, FakeBufferedChunkJob::$instantiatedChunkData);
        foreach (FakeBufferedChunkJob::$instantiatedChunkData as $chunkData) {
            $this->assertInstanceOf(BufferedPayloadReference::class, $chunkData);
        }

        $this->assertCount(2, FakeBufferedChunkJob::$processed);
        $this->assertSame([['id' => 10], ['id' => 20]], FakeBufferedChunkJob::$processed);
    }

    public function test_buffered_task_success_path_calls_on_batch_finished_and_deletes_redis_payload_keys(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $task = new FakeBufferedChunkableTask(
            chunks: [['id' => 10], ['id' => 20]],
        );

        $batchId = (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotEmpty($batchId);
        $this->assertSame($batchId, FakeBufferedChunkableTask::$finishedBatchId);
        $this->assertNull(FakeBufferedChunkableTask::$failureMessage);
        $this->assertEmpty($redisStorage);
    }

    public function test_buffered_task_failure_path_calls_on_batch_failed_and_deletes_redis_payload_keys(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $task = new FakeBufferedChunkableTask(
            chunks: [['id' => 10]],
            jobClass: FakeFailingBufferedChunkJob::class,
        );

        (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotNull(FakeBufferedChunkableTask::$failureMessage);
        $this->assertStringContainsString('boom from buffered job', FakeBufferedChunkableTask::$failureMessage);
        $this->assertNull(FakeBufferedChunkableTask::$finishedBatchId);
        $this->assertEmpty($redisStorage);
    }

    public function test_buffered_generator_is_iterated_exactly_once_and_not_eagerly_materialized(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $generatorCalls = 0;
        $generator = function () use (&$generatorCalls) {
            $generatorCalls++;
            yield ['id' => 1];
            yield ['id' => 2];
        };

        $task = new FakeBufferedChunkableTask(
            chunks: $generator(),
        );

        (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertSame(1, $generatorCalls);
        $this->assertCount(2, FakeBufferedChunkJob::$processed);
    }

    public function test_buffered_task_preserves_serializable_callback_state_arrays(): void
    {
        $redisStorage = [];
        $this->setupRedisMock($redisStorage);

        $task = new FakeBufferedTaskWithCallbackState(
            chunks: [['id' => 10], ['id' => 20]],
            callbackMetadata: ['report_id' => 123, 'locale' => 'pl'],
        );

        (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertSame(
            ['report_id' => 123, 'locale' => 'pl'],
            FakeBufferedTaskWithCallbackState::$finishedMetadata,
        );
        $this->assertNull(FakeBufferedTaskWithCallbackState::$failureMetadata);
        $this->assertEmpty($redisStorage);
    }
}
