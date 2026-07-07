# kamz8/laravel-batch-orchestrator

Dynamic chunking and orchestration of Laravel `Bus::batch()` for long-running jobs (reports, exports), with optional progress tracking in Redis.

## Requirements

- PHP `^8.2` or `^8.3`
- Laravel `^10.0` or `^11.0` (`illuminate/bus`, `illuminate/support`)

## Installation

```bash
composer require kamz8/laravel-batch-orchestrator
```

Requires a `job_batches` table and a queue connection that supports batching:

```bash
php artisan queue:batches-table
php artisan migrate
```

Publish the config if you need to change the progress TTL:

```bash
php artisan vendor:publish --tag=batch-orchestrator-config
```

## Components

- `Contracts\ChunkableTask` — the task contract: `getChunks()`, `getChunkJobClass()`, `queue()`, `onBatchFinished()`, `onBatchFailed()`.
- `Services\BaseOrchestrator` / `Services\BatchProcessOrchestrator` — dispatches the batch and calls the corresponding task method on completion/failure.
- `Concerns\HasBatchProgress` — trait for models that tracks percentage progress in Redis (chunks count toward 80%, the remainder is the merge stage).

> Full API description (parameters, edge-case behavior) lives in the PHPDoc in `src/`. See [`docs/AI_GUIDE.md`](docs/AI_GUIDE.md) for a deeper guide on wiring up a new `ChunkableTask`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

Proprietary.
