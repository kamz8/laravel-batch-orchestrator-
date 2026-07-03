<?php

namespace Kamz8\BatchOrchestrator\Tests\Unit;

use Illuminate\Support\Facades\Redis;
use Kamz8\BatchOrchestrator\Tests\Fixtures\FakeProgressModel;
use Kamz8\BatchOrchestrator\Tests\TestCase;

class HasBatchProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeProgressModel::$progressUpdatedCalls = 0;
    }

    public function test_set_progress_clamps_upper_bound_and_persists(): void
    {
        Redis::shouldReceive('setex')
            ->once()
            ->with('batch-orchestrator:progress:1', 14400, 100);

        (new FakeProgressModel(1))->setProgress(150);

        $this->assertSame(1, FakeProgressModel::$progressUpdatedCalls);
    }

    public function test_set_progress_clamps_lower_bound(): void
    {
        Redis::shouldReceive('setex')
            ->once()
            ->with('batch-orchestrator:progress:1', 14400, 0);

        (new FakeProgressModel(1))->setProgress(-10);
    }

    public function test_increment_chunk_progress_caps_at_eighty_percent_when_all_chunks_done(): void
    {
        $model = new FakeProgressModel(2);

        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:chunks:total:2', 14400, 4);
        Redis::shouldReceive('incr')->once()->with('batch-orchestrator:chunks:completed:2')->andReturn(4);
        Redis::shouldReceive('expire')->once()->with('batch-orchestrator:chunks:completed:2', 14400);
        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:progress:2', 14400, 80);

        $model->incrementChunkProgress(4);
    }

    public function test_increment_chunk_progress_is_proportional_to_completed_chunks(): void
    {
        $model = new FakeProgressModel(3);

        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:chunks:total:3', 14400, 4);
        Redis::shouldReceive('incr')->once()->with('batch-orchestrator:chunks:completed:3')->andReturn(1);
        Redis::shouldReceive('expire')->once()->with('batch-orchestrator:chunks:completed:3', 14400);
        Redis::shouldReceive('setex')->once()->with('batch-orchestrator:progress:3', 14400, 20);

        $model->incrementChunkProgress(4);
    }

    public function test_current_progress_returns_null_when_key_missing(): void
    {
        Redis::shouldReceive('get')->once()->with('batch-orchestrator:progress:4')->andReturn(null);

        $this->assertNull((new FakeProgressModel(4))->currentProgress());
    }

    public function test_current_progress_returns_stored_integer(): void
    {
        Redis::shouldReceive('get')->once()->with('batch-orchestrator:progress:5')->andReturn('42');

        $this->assertSame(42, (new FakeProgressModel(5))->currentProgress());
    }

    public function test_clear_progress_keys_deletes_all_three_keys(): void
    {
        Redis::shouldReceive('del')->once()->with(
            'batch-orchestrator:progress:6',
            'batch-orchestrator:chunks:completed:6',
            'batch-orchestrator:chunks:total:6',
        );

        (new FakeProgressModel(6))->clearProgressKeys();
    }
}
