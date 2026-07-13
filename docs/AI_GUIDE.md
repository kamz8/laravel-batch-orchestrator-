# kamz8/laravel-batch-orchestrator — AI Guide

> **Goal:** fast, unambiguous instructions for how to **consume this package in a new project** and how to **add a new `ChunkableTask`**, without reading the entire source tree.
> The full API description (parameters, edge-case behavior) lives in **PHPDoc** on every class/method in `src/` — this document does not duplicate that, it only maps "what lives where" and lists the invariant rules.
>
> **For LLM agents:** this file is written so that reading it alone is enough to implement a new `ChunkableTask` correctly. If you need to verify a specific claim, cross-check the cited file/line — this document is a map, PHPDoc is the authority.

## TL;DR — assumptions

- The package splits a large task into chunks, runs them as a single `Illuminate\Bus\Batch`, and on completion/failure calls back exactly one method on your task (`onBatchFinished` / `onBatchFailed`).
- Progress (0-100%) is kept in Redis via the `HasBatchProgress` trait — **chunks never exceed 80%**; the rest (85%, 95%, 100%) is your responsibility in the finalization step (merge job).
- The package knows nothing about your domain model (e.g. `Report`) — all integration goes through the `ChunkableTask` interface and the `HasBatchProgress` trait.

### Guarantees / Non-guarantees

**Guarantees:**
- `onBatchFinished()` / `onBatchFailed()` is called **exactly once** per batch, on every supported Laravel version (10-13) and queue driver, including `sync` — see [Common Mistakes](#common-mistakes) for a related framework quirk this package normalizes.
- Chunk-phase progress never exceeds 80%.
- Lifecycle events (see [Lifecycle Events](#lifecycle-events)) carry only serializable scalars/DTOs — never full models, generators, or closures.
- `dispatch()` always returns a real batch ID or throws — it never returns a falsy/placeholder value.

**Non-guarantees:**
- No retry orchestration beyond whatever the underlying Laravel queue driver already provides — this package does not add its own retry layer.
- No ordering guarantee between chunk jobs; `Bus::batch()` runs them in parallel/out-of-order by design.
- No guarantee that `BatchOrchestrationStarted` precedes chunk execution under `queue.default=sync` — see the sync-queue caveat in [`docs/LIFECYCLE_EVENTS.md`](LIFECYCLE_EVENTS.md).
- No guarantee that all N chunk jobs execute if one throws under `queue.default=sync` — see [Common Mistakes](#common-mistakes).

## Components (file paths)

| Role | Class | File |
|------|-------|------|
| Chunked task contract | `ChunkableTask` | `src/Contracts/ChunkableTask.php` |
| Engine (abstract) | `BaseOrchestrator` | `src/Services/BaseOrchestrator.php` |
| Engine (injectable) | `BatchProcessOrchestrator` | `src/Services/BatchProcessOrchestrator.php` |
| Redis progress (trait) | `HasBatchProgress` | `src/Concerns/HasBatchProgress.php` |
| Service Provider | `BatchOrchestratorServiceProvider` | `src/BatchOrchestratorServiceProvider.php` |
| Configuration | `batch-orchestrator.progress_ttl` | `config/batch-orchestrator.php` |

### Flow

```
$orchestrator->dispatch($task)                         // BatchProcessOrchestrator::dispatch()
  └─ Bus::batch([ChunkJob × N])  → onQueue($task->queue())     chunks: incrementChunkProgress() → 0–80 %
       ├─ then()  → cleanup → BatchOrchestrationFinished → $task->onBatchFinished($batchId)  // e.g. dispatch mergeJob → setProgress(85→95) → 100%
       └─ catch() → cleanup → BatchOrchestrationFailed   → $task->onBatchFailed($exception)  // e.g. markFailed() + purge chunks
  → BatchOrchestrationStarted   (right after dispatch(), with the real batchId)
```

The package also dispatches Laravel events at these points (and on
`setProgress()`/`incrementChunkProgress()`/payload buffering) — see
[`docs/LIFECYCLE_EVENTS.md`](LIFECYCLE_EVENTS.md) for the full emission
order, an event table, and listener examples.

## ⛔ Invariant rules (DO NOT break)

1. **A class returned by `getChunkJobClass()` MUST accept the chunk payload as its only constructor argument** and use `Illuminate\Bus\Batchable` so it can check `$this->batch()?->cancelled()`.
2. **`onBatchFinished`/`onBatchFailed` fires exactly once** — do not assume retries happen at this level; build retry/idempotency logic into the merge/finalization step itself.
3. **`incrementChunkProgress($totalChunks)` must receive the same `$totalChunks` value on every call within one batch** — otherwise the percentage drifts (dividing by a different number each time).
4. **Merge/finalization does NOT extend `Batchable`** (it's a separate, standalone job) — it must have its own `failed(Throwable $e)`, otherwise the exception silently disappears into `failed_jobs` and progress hangs before 100%.
5. **The class hosting the `HasBatchProgress` trait must have `getKey()`** (Eloquent has this natively; for non-Eloquent classes, add a method returning a stable identifier).
6. **If you change `progressKeyPrefix()` on a model that's already in production, don't do it without a data migration** — it changes the Redis key names, and access to already-recorded progress is lost (harmless, since it's only a TTL'd cache, but the UI will show "no progress" until the next write).

## Recipe — a new `ChunkableTask`

1. **Task** implementing `ChunkableTask` (see the interface's PHPDoc for the full contract):
   ```php
   class MyTask implements ChunkableTask
   {
       public function __construct(private MyDomainRecord $record) {}
       public function queue(): string { return 'batch-processing'; }
       public function getChunks(): array { /* array of payloads, one element = one chunk job */ }
       public function getChunkJobClass(): string { return MyChunkJob::class; }
       public function onBatchFinished(string $batchId): void
       {
           MyMergeJob::dispatch($this->record->id)->onQueue($this->queue());
       }
       public function onBatchFailed(Throwable $e): void
       {
           MyChunkJob::purgeChunks($this->record->id);
           $this->record->markFailed($e->getMessage()); // your domain logic
       }
   }
   ```
2. **Chunk job** — `implements ShouldQueue`, `use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels`; guard `if ($this->batch()?->cancelled()) return;` at the top of `handle()`; at the end, `$record->incrementChunkProgress($totalChunks)` (requires the `HasBatchProgress` trait on `$record`).
3. **Merge/finalization job** — separate, **not** `Batchable`; `setProgress(85)` at start, `setProgress(95)` before writing the result, then your completion method (e.g. `markCompleted()`) and **mandatory** `failed(Throwable $e)` (rule #4).
4. **Dispatch**: `app(BatchProcessOrchestrator::class)->dispatch(new MyTask($record))` — returns `$batchId`; persist it if you want to later `Bus::findBatch($batchId)?->cancel()`.
5. Domain model/record: `use HasBatchProgress;` + when retrofitting onto an existing model, override `progressKeyPrefix()` to return the old key prefix (see rule #6) and `onProgressUpdated()` to broadcast your own event.

## Installation

```bash
composer require kamz8/laravel-batch-orchestrator
```
Requires the `job_batches` table (`php artisan queue:batches-table` + migration) and a queue connection supporting batching. Config is published under the `batch-orchestrator-config` tag (`php artisan vendor:publish --tag=batch-orchestrator-config`).

## Tests (pattern to copy)

`tests/Fixtures/` — `FakeChunkableTask`, `FakeChunkJob`, `FakeFailingChunkJob`, `FakeProgressModel`. `tests/Unit/HasBatchProgressTest.php` mocks the `Redis` facade (no real Redis needed). `tests/Feature/BatchProcessOrchestratorTest.php` runs a real `Bus::batch` on sqlite `:memory:` with the sync queue driver (manually creates the `job_batches` table in `setUp()`, since Testbench doesn't provide it by default).

## Streaming large sources (generator + `ShouldBufferPayloads`)

`getChunks()` can be a generator consumed lazily by the orchestrator — see
`docs/BUFFERED_PAYLOADS.md` (the exact mechanism, what is and isn't
"streamed") and `docs/use-cases/streaming-xml-import.md` and
`docs/use-cases/small-generator-no-buffering.md` (full examples of when a
plain generator suffices and when `ShouldBufferPayloads` is needed).

## Checklist for a new task

- [ ] Task implements `ChunkableTask` (4 methods + `onBatchFinished`/`onBatchFailed`).
- [ ] Chunk job: `Batchable`, `cancelled()` guard, single constructor argument.
- [ ] Merge/finalization: **not** `Batchable`, has `failed()` (rule #4).
- [ ] Domain model: `use HasBatchProgress` (+ optionally `progressKeyPrefix()`/`onProgressUpdated()`).
- [ ] Feature test: dispatch via `BatchProcessOrchestrator`, assert on `onBatchFinished`/`onBatchFailed` (pattern: `tests/Feature/BatchProcessOrchestratorTest.php`).
- [ ] (Optional) Listener on lifecycle events (`BatchOrchestrationFailed`, `BatchProgressUpdated`, etc.) — see [`docs/LIFECYCLE_EVENTS.md`](LIFECYCLE_EVENTS.md); not required, the package works identically with zero listeners registered.

## Architecture Overview

The package deliberately does one thing: turn `$task->getChunks()` into a
single `Illuminate\Bus\Batch` and call back exactly one terminal method on
your task. Everything else — progress tracking, payload buffering, lifecycle
events — is optional instrumentation layered on top of that one call.

Two design choices explain most of the package's shape:

- **Chunk count is only known lazily.** `BaseOrchestrator::dispatch()` counts
  chunks as it iterates `getChunks()` (a `foreach`, never
  `iterator_to_array()`), because `getChunks()` may be a generator reading a
  multi-hundred-MB file. This is why `totalChunks` in `BatchContext` isn't
  known until the loop finishes, and why the chunk-count invariant (rule #3)
  exists — nothing in the package re-derives "how many chunks" after the
  fact, so callers must supply it consistently themselves via
  `incrementChunkProgress($totalChunks)`.
- **Exactly one terminal callback, not a retry framework.** `onBatchFinished`/
  `onBatchFailed` fire once, ever, per batch (rule #2). The package has no
  opinion on what "failure" means for your domain — that's why cleanup,
  idempotency, and retry all belong in the merge/finalization job (rule #4),
  never in the chunk-execution phase itself.

The flow diagram in [Components](#components-file-paths) shows *when* things
fire; [Lifecycle Events](#lifecycle-events) shows *what you can observe*
without touching `src/`.

## Lifecycle Events

The package dispatches 8 plain Laravel events (never `ShouldBroadcast`, never
`ShouldQueue`) at key orchestration and buffered-payload points —
`BatchOrchestrationStarted/Finished/Failed`, `BatchProgressUpdated`, and four
`BufferedPayload*` events. It never registers a listener for them itself;
logging, custom domain broadcasts, UI updates, and metrics are entirely up to
the consuming application. See [`docs/LIFECYCLE_EVENTS.md`](LIFECYCLE_EVENTS.md)
for the full emission order, an event-by-event table (fields, exact firing
point, what each event does *not* mean), and listener examples.

## Buffered Payloads

`getChunks()` may be a generator, and a task implementing
`ShouldBufferPayloads` gets each yielded chunk automatically written to Redis
(`Redis::setex()`) with only a lightweight `BufferedPayloadReference` passed
to the chunk job — never a disk write, never the generic `Cache` facade. This
is what makes streaming a multi-hundred-MB source (e.g. an XML feed) safe:
the source is never materialized into one PHP array, and each queued job's
payload stays small regardless of chunk size. See
[`docs/BUFFERED_PAYLOADS.md`](BUFFERED_PAYLOADS.md) for the exact mechanism,
the config knobs (`payload_ttl`, `payload_key_prefix`,
`payload_chunk_flush_size`), and — importantly — what buffering does **not**
do (it does not reduce the number of jobs in the batch; `Bus::batch` always
needs the total job count upfront).

## Common Mistakes

- **Assuming `getChunks()` is only ever consumed once, in order.** The
  orchestrator does consume it via a single `foreach`, but nothing stops a
  *caller* from calling `getChunks()` again in `onBatchFinished`/
  `onBatchFailed` for unrelated diagnostics — if your generator has side
  effects (e.g. advancing an external cursor), a second call re-runs them.
  Keep `getChunks()` free of side effects beyond producing payloads.
- **Assuming `dispatch()` never throws on `queue.default=sync`.** This
  package normalizes a real framework difference here: on Laravel 11,
  `Illuminate\Bus\Batch::add()` internally swallows a chunk job's exception
  when the connection is `sync`; Laravel 10, 12, and 13 do not have that
  special case and would otherwise re-throw it — even though the batch's own
  `catch()` callback (and this package's `onBatchFailed()` / lifecycle
  events) had *already* run correctly by that point. `BaseOrchestrator`
  catches that redundant, version-dependent exception so `dispatch()` behaves
  identically on all four supported majors. You should never need to wrap
  `dispatch()` in try/catch to handle chunk-job failures — that's what
  `onBatchFailed()` is for.
- **Not broadcasting the full domain model directly from a package
  listener.** Lifecycle events deliberately carry only `subjectKey`/
  `BatchContext` — if your listener immediately loads the full model and
  pushes it over WebSockets, consider sending just the ID and letting the
  client fetch details instead.
- **Treating `BatchOrchestrationFinished` as the end of the merge job.** It's
  only the end of the parallel chunk phase; merge/finalization usually starts
  from `onBatchFinished()` and can run long after this event.
- **Non-idempotent listeners.** Queue-level retries, manual batch re-runs, or
  (rarely) duplicate event delivery should never corrupt state — don't assume
  exactly-once delivery at the whole-system scale, only within one batch.
- **Blocking the worker with a heavy listener.** Events are dispatched
  synchronously in the same process as the orchestrator/chunk job/trait — a
  slow listener (e.g. an external API call) extends the real processing time
  of that job.

## Anti-patterns

Things that *technically work* but defeat the point of this package:

- **Materializing `getChunks()` into an array before returning it** (e.g.
  `return iterator_to_array($this->readSource())`) when the source is large.
  This works, but it throws away the entire streaming benefit — the whole
  point of allowing a generator is to avoid holding the full source in memory
  at once.
- **Storing a generator/iterator as a task property.** `BaseOrchestrator`
  serializes a copy of a `ShouldBufferPayloads` task for the `then`/`catch`
  callbacks and strips out the chunk source specifically to avoid this trap
  (see `BaseOrchestrator::taskWithoutBufferedPayloads()`) — but that
  protection **only exists for `ShouldBufferPayloads` tasks**. A plain
  `ChunkableTask` holding a generator as a property will fail to serialize
  when Laravel needs to queue the batch's callbacks. Build the generator
  fresh inside `getChunks()` from immutable, serializable constructor state
  (a file path, an ID) instead.
- **Putting retry/idempotency logic in `onBatchFinished`/`onBatchFailed`
  instead of the merge job.** These callbacks fire exactly once (rule #2) —
  there is no package-level retry to hook into here. Idempotency belongs in
  the merge/finalization job, which can itself be retried by the queue driver
  like any other job.
- **Relying on chunk execution order.** `Bus::batch` does not guarantee chunk
  jobs run in the order they were added, even on drivers that happen to
  process them roughly in order today.

## When NOT to use this package

- **A single unit of work with no need for progress tracking.** A plain
  `ShouldQueue` job is simpler and has less machinery to reason about than a
  one-chunk batch.
- **Workloads needing ordering guarantees between steps.** `Bus::batch` runs
  chunks in parallel with no ordering contract — if step B must run strictly
  after step A finishes, use `Illuminate\Bus\Batch`'s chaining
  (`Bus::chain()`) or a pipeline pattern instead.
- **Workloads needing a synchronous return value from the merge step.** This
  package is fire-and-forget/async by design — the caller gets a `$batchId`
  back immediately, not a result. If you need to block for a result, this is
  the wrong tool.

## FAQ

**Can `getChunkJobClass()` return a different class per chunk?**
No — one job class per task; only the payload varies per chunk. If you need
heterogeneous chunk behavior, branch inside that one job class's `handle()`
based on the payload shape.

**What happens if `onBatchFinished`/`onBatchFailed` itself throws?**
It runs outside the batch's own try/catch scaffolding — the exception
propagates to whatever queued the `then()`/`catch()` callback. It will
**not** retroactively trigger `onBatchFailed()` if it was `onBatchFinished()`
that threw.

**Does cancelling a batch stop already-dispatched chunk jobs?**
No — cancellation only makes the `cancelled()` guard short-circuit
`handle()` for chunk jobs that haven't started `handle()` yet. Jobs already
executing when cancellation happens run to completion.

**Is the package safe with `queue.default=sync`?**
Yes, but with two caveats documented in detail elsewhere: event ordering
differs (see the sync-queue note in
[`docs/LIFECYCLE_EVENTS.md`](LIFECYCLE_EVENTS.md)), and if a chunk job throws,
any chunk jobs after it in the same internal flush never execute — inherent
to how `Bus::batch` + sync queue works, not something this package can change
(see [Common Mistakes](#common-mistakes)). Production usage on a real async
queue backend does not have that second limitation, since each job's
execution is isolated by the queue worker.

**Which Laravel versions are supported, and does anything differ between
them?**
Laravel 10, 11, 12, and 13 — see [`docs/COMPATIBILITY.md`](COMPATIBILITY.md)
for the PHP × Laravel matrix and the one framework-level behavior difference
(the sync-queue exception handling above) this package normalizes so you
don't have to think about it.
