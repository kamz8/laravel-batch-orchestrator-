# Use case: a generator without `ShouldBufferPayloads`

**When this is enough:** the number of chunks is moderate and **each
individual chunk payload** is small (e.g. a few dozen/hundred IDs, or a small
slice of data) — the source itself can be large (e.g. streamed reading from
a database via a cursor), but what actually goes into the queue per chunk job
is light.

In that case, a generator in `getChunks()` is enough by itself. There's no
need to implement `ShouldBufferPayloads` — the chunk job's payload goes
directly into the `jobs`/`failed_jobs` table just like with a plain array,
just without building the whole chunk collection up front.

**When it's NOT enough** (use `ShouldBufferPayloads`, see
`docs/use-cases/streaming-xml-import.md`): a single chunk is itself heavy
(large objects, long strings, nested structures) — then the queue payload
(SQS/Redis/database driver) grows proportionally and needs to be offloaded to
Redis.

## Example: exporting IDs of records matching a condition

```php
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;

class ExportFilteredRecordsTask implements ChunkableTask
{
    private const int IDS_PER_CHUNK = 500;

    public function __construct(
        private readonly int $exportId,
        private readonly string $status, // scalar — safe to serialize the task object
    ) {}

    /**
     * @return iterable<int, array<int, int>>
     */
    public function getChunks(): iterable
    {
        $buffer = [];

        // cursor() streams rows from the database one at a time,
        // never materializes the whole result set in memory
        foreach (Record::where('status', $this->status)->cursor() as $record) {
            $buffer[] = $record->id;

            if (count($buffer) >= self::IDS_PER_CHUNK) {
                yield $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield $buffer;
        }
    }

    public function getChunkJobClass(): string
    {
        return ExportRecordChunkJob::class;
    }

    public function queue(): string
    {
        return 'exports';
    }

    public function onBatchFinished(string $batchId): void
    {
        MergeExportJob::dispatch($this->exportId)->onQueue($this->queue());
    }

    public function onBatchFailed(\Throwable $e): void
    {
        Export::find($this->exportId)?->markFailed($e->getMessage());
    }
}
```

The chunk job receives the raw array of IDs directly — no
`InteractsWithBufferedPayload`, since there's no reference to resolve:

```php
class ExportRecordChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $ids) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Record::whereIn('id', $this->ids)->chunk(100, function ($records) {
            // ... write to the export file, append-only
        });
    }
}
```

## Selection rule

| Situation | Solution |
|---|---|
| Large source, but each chunk itself is small (IDs, short scalars) | a plain generator in `getChunks()`, without `ShouldBufferPayloads` |
| Large source **and** each chunk itself is heavy (parsed XML nodes, large nested arrays) | generator + `ShouldBufferPayloads` (`docs/use-cases/streaming-xml-import.md`) |
| Small source (comfortably fits in memory) | a plain `array` from `getChunks()` — no reason to complicate things |

For more technical detail on exactly what buffering does and doesn't do
(e.g. that the number of jobs in the batch always equals the number of
chunks) — see `docs/BUFFERED_PAYLOADS.md`.
