# Buffered payloads and streaming (`ShouldBufferPayloads`)

> This document answers precisely: "can I stream a large data source (e.g. an
> XML file) through `getChunks()` without holding the whole thing in memory,
> and what exactly does `ShouldBufferPayloads` do?" Every claim below is based
> on the code in `src/`, not guesswork — paths and line numbers point exactly
> to where it happens.

## TL;DR

1. `getChunks(): iterable` **can** be a generator. The orchestrator consumes
   it via a plain `foreach`, never `iterator_to_array()`.
2. A buffered payload lands **in Redis** (`Illuminate\Support\Facades\Redis::setex()`),
   not on disk (`Storage`) and not in the generic `Cache` facade. TTL and key
   prefix are configurable in `config/batch-orchestrator.php`.
3. Buffering is **fully automatic on the orchestrator's side**. The task
   implements `ShouldBufferPayloads` and yields raw data exactly as it would
   without buffering — it never calls a "store this payload" method itself.
   The orchestrator does the `Redis::setex()` and swaps the chunk job's
   constructor argument for a `BufferedPayloadReference`. The chunk job only
   needs to call `resolvePayload()` at the start of `handle()`.

## What happens step by step (`BaseOrchestrator::dispatch()`)

File: `src/Services/BaseOrchestrator.php`

```php
$chunks = $task->getChunks();                          // (1) generator or array — nothing consumed yet

foreach ($chunks as $index => $chunkData) {             // (2) lazy consumption — one element per iteration
    $payload = $chunkData;

    if ($task instanceof ShouldBufferPayloads) {
        $key = sprintf('%s:%s:%s', $task->payloadKeyPrefix(), $batchKey, $index);
        Redis::setex($key, $task->payloadTtl(), serialize($chunkData));   // (3) raw data goes to Redis and can be freed from PHP
        $payload = new BufferedPayloadReference($key, $batchKey, $index); // (4) the job gets only a lightweight pointer
    }

    $jobBuffer[] = new $jobClass($payload);

    if (count($jobBuffer) >= $flushSize) {               // (5) 100 by default — see "What this does NOT do"
        $pendingBatch->add($jobBuffer);
        $jobBuffer = [];
    }
}
```

Chunk job (must `use InteractsWithBufferedPayload;`):

```php
public function handle(): void
{
    $data = $this->resolvePayload($this->chunkData); // returns the original (non-buffered) or reads+unserializes from Redis
    // ... normal logic on $data
}
```

`resolvePayload()` (`src/Concerns/InteractsWithBufferedPayload.php`):
- if the argument isn't a `BufferedPayloadReference` → returns it unchanged (the same job class works buffered and non-buffered),
- if it is → `Redis::get($key)`, `unserialize()`; a missing key or corrupted data → `RuntimeException`, which lands in `onBatchFailed()` through the batch's normal mechanism.

## Where this is configurable

File: `config/batch-orchestrator.php`

| Key | Default | What it does |
|---|---|---|
| `payload_ttl` | `14400` (4h) | The Redis key's TTL; must cover the chunk job's entire lifecycle **including any retries** — too short a TTL means a `RuntimeException` on retry after the key expires. |
| `payload_key_prefix` | `batch-orchestrator:payload` | The Redis key prefix (`{prefix}:{batchKey}:{index}`). |
| `payload_chunk_flush_size` | `100` | How many chunk-job objects wait in the PHP array `$jobBuffer` before being handed to `$pendingBatch->add()`. See the caveat below — this is NOT a memory budget for the whole batch. |

A task usually doesn't override these methods manually — `use BuffersPayloads;`
reads them from config. Only override `payloadTtl()`/`payloadKeyPrefix()` on
the task itself when a specific workload needs a longer retry window or an
isolated Redis key space.

## What this does NOT do (important for a correct plan)

`Illuminate\Bus\Batch` needs to know the **total number of jobs** to track
progress (`$batch->totalJobs`) — so all N chunk-job objects still get built
and handed to `PendingBatch` before `dispatch()`. A generator **does not
reduce the number of jobs in the batch**. `payload_chunk_flush_size` only
controls the size of the helper array `$jobBuffer` between successive
`add()` calls — it is not a "memory window" for the whole batch, since
`PendingBatch` still holds references to every added job until `dispatch()`.

What streaming and buffering actually give you:

- **The data source is never materialized in full** — you read/group/yield
  on the fly (e.g. an `XMLReader` reading node by node), so a 500 MB XML file
  never lands in one big PHP array.
- **Each job's payload is small** — with `ShouldBufferPayloads`, a job holds a
  `BufferedPayloadReference` (a string + an optional int), not a full slice
  of data. That makes the memory used by all N job objects in `PendingBatch`
  on the order of N × a few dozen bytes, not N × the chunk's size.

In other words: the number of jobs stays N (unavoidable for `Bus::batch`),
but their "weight" in the dispatching process's memory and in the queue
(payload in `jobs`/`failed_jobs`) is drastically smaller, and the source
parser never holds the whole dataset at once.

## Trap: a task cannot hold a generator as a property

Closures passed to `->then()`/`->catch()` in `BaseOrchestrator::dispatch()`
capture `$callbackTask` via `use (...)`. For a real (non-`sync`) queue, this
callback is serialized along with the batch. If the task implements
`ShouldBufferPayloads`, the orchestrator automatically builds a "clean" copy
of the task without the chunk source (see
`BaseOrchestrator::taskWithoutBufferedPayloads()`), so unsafe properties
(e.g. `Traversable`, `Closure`) are stripped from the copy used in the
callback.

**Tasks without `ShouldBufferPayloads` don't get this protection.** Rule: do
not hold a generator/iterator as a property on the task object. Build it
on the fly inside `getChunks()` from immutable, serializable data (e.g. a
file path passed in the constructor), the way `FakeGeneratorChunkableTask`
does in the tests — the constructor holds only scalar
`count`/`jobClass`/`queueName`, and the generator is built fresh on every
`getChunks()` call.

## Retry and TTL — a contract caveat

Full description in the PHPDoc of `src/Contracts/ShouldBufferPayloads.php`:
a queued retry can only read the payload as long as the Redis key still
exists. The TTL must be longer than the maximum queue delay + retry window +
expected duration of the whole batch. A missing or corrupted key is treated
as a job error (`RuntimeException`), so the batch correctly transitions into
`onBatchFailed()` instead of failing silently.

## See also

- `docs/use-cases/streaming-xml-import.md` — a full example: a large XML file → grouping N records → `ShouldBufferPayloads` → chunk job → merge job.
- `docs/use-cases/small-generator-no-buffering.md` — when a plain generator is enough, without buffering in Redis.
- `docs/AI_GUIDE.md` — the package's general invariant rules (progress, merge job, `getKey()`, etc.).
