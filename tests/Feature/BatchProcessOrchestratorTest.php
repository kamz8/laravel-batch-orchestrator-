<?php

namespace Kamz8\BatchOrchestrator\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Kamz8\BatchOrchestrator\Services\BatchProcessOrchestrator;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeChunkableTask;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeChunkJob;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeFailingChunkJob;
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

        (new BatchProcessOrchestrator)->dispatch($task);

        $this->assertNotNull(FakeChunkableTask::$failureMessage);
        $this->assertStringContainsString('boom', FakeChunkableTask::$failureMessage);
        $this->assertNull(FakeChunkableTask::$finishedBatchId);
    }
}
