# Lifecycle events (`src/Events/`)

> This document describes the 8 events the package **dispatches** (via
> `Illuminate\Support\Facades\Event::dispatch()`) at key points of batch
> orchestration and buffered-payload operations. The package only emits them —
> it registers no listeners, never broadcasts (`ShouldBroadcast`), and never
> queues (`ShouldQueue`) them. It's up to the consumer to decide whether to
> log them, turn them into their own broadcasted event, update a UI, or
> ignore them. The full field/guarantee description of each event lives in
> PHPDoc on the class in `src/Events/` — this document doesn't duplicate that,
> it only shows the order and how to integrate.

## Lifecycle diagram

```
dispatch()
  → payload stored × N                    (BufferedPayloadStored, ShouldBufferPayloads only)
  → Laravel Batch dispatched
  → BatchOrchestrationStarted              (only after dispatch(), with the real batchId)
  → (in chunk jobs) BufferedPayloadResolved × N / BufferedPayloadResolutionFailed
  → BatchProgressUpdated × N               (from setProgress()/incrementChunkProgress() on the model)
  ├─ success:
  │    → payload cleanup (in batches)
  │    → BufferedPayloadCleanupCompleted    (ShouldBufferPayloads only)
  │    → BatchOrchestrationFinished
  │    → ChunkableTask::onBatchFinished()
  └─ failure:
       → payload cleanup (in batches)
       → BufferedPayloadCleanupCompleted    (ShouldBufferPayloads only)
       → BatchOrchestrationFailed
       → ChunkableTask::onBatchFailed()
```

**Caveat (`sync` queue):** with `queue.default = sync`, the entire batch —
including the success/failure branch and `onBatchFinished()`/
`onBatchFailed()` — executes **inside** the `->dispatch()` call in
`Bus::batch(...)->dispatch()`. `BatchOrchestrationStarted` is only emitted
**after** that call returns (since only then does a real `batchId` exist), so
on sync queue you observe it **after** the finalization events. On a real
(asynchronous) queue, `dispatch()` returns as soon as the batch is persisted
and jobs are pushed to the queue — `Started` precedes chunk execution, as the
name implies. The only ordering guaranteed regardless of queue type is the
finalization sub-sequence: `cleanup → BufferedPayloadCleanupCompleted →
Finished/Failed → onBatchFinished()/onBatchFailed()`.

Related framework quirk: on Laravel 10, 12, and 13, a chunk job throwing under
`queue.default=sync` would otherwise re-throw out of `dispatch()` itself
*after* `BatchOrchestrationFailed`/`onBatchFailed()` have already fired
correctly (Laravel 11 alone swallows this internally). `BaseOrchestrator`
catches that redundant, version-dependent exception so `dispatch()` always
returns the batch ID rather than throwing — see the "Common Mistakes"
section in [`docs/AI_GUIDE.md`](AI_GUIDE.md) for why, and
[`docs/COMPATIBILITY.md`](COMPATIBILITY.md) for the underlying framework
difference.

## Event table

| Event | Emission point | Key data | What the event does NOT mean |
|---|---|---|---|
| `BatchOrchestrationStarted` | Right after a successful Laravel Batch `->dispatch()` | `BatchContext` (with the real `batchId`), `buffered: bool` | That any chunk job has already run (on a sync queue it may actually be the reverse — see the caveat above) |
| `BatchOrchestrationFinished` | In the `then()` callback, after payload cleanup, before `onBatchFinished()` | `BatchContext` with `batchId` | The end of the whole domain process — merge/finalization may still be running |
| `BatchOrchestrationFailed` | In the `catch()` callback, after payload cleanup, before `onBatchFailed()` | `BatchContext`, `exceptionClass`, `message`, `code` | That every chunk job has definitely stopped running — others may still be in flight |
| `BatchProgressUpdated` | After every successful write in `HasBatchProgress` (`setProgress()`/`incrementChunkProgress()`), before `onProgressUpdated()` | `subjectKey` (string, not the model!), `keyPrefix`, `progress`, `previousProgress` (always `null` from this package), `updateType` (`'set'`\|`'increment'`) | That this is the last progress change — later jobs/steps may still bump the value |
| `BufferedPayloadStored` | Right after a successful `Redis::setex()` for one chunk | `key`, `batchKey`, `index`, `ttl` | That the chunk job holding this payload has already been queued/executed |
| `BufferedPayloadResolved` | In `resolvePayload()`, after a successful `Redis::get()` + `unserialize()` | `key`, `batchKey`, `index` | That the rest of the chunk job's `handle()` logic will succeed |
| `BufferedPayloadResolutionFailed` | In `resolvePayload()`, right before throwing `RuntimeException` (missing key or corrupted data) | `key`, `batchKey`, `index`, `reason` | Distinguishing the exact cause beyond the human-readable `reason` text |
| `BufferedPayloadCleanupCompleted` | After the entire (batched) Redis cleanup pass, `ShouldBufferPayloads` only | `BatchContext`, `deletedKeys` (a count, not a list of keys) | That every key has physically disappeared from Redis — `DEL` on an already-expired key still counts |

## Example: logging an orchestration failure

```php
use Illuminate\Support\Facades\Event;
use Kamz8\BatchOrchestrator\Events\BatchOrchestrationFailed;
use Illuminate\Support\Facades\Log;

Event::listen(BatchOrchestrationFailed::class, function (BatchOrchestrationFailed $event) {
    Log::error('Batch orchestration failed', [
        'batch_id' => $event->context->batchId,
        'task' => $event->context->taskClass,
        'exception' => $event->exceptionClass,
        'message' => $event->message,
    ]);
});
```

## Example: turning progress into your own broadcasted domain event

```php
use Illuminate\Support\Facades\Event;
use Kamz8\BatchOrchestrator\Events\BatchProgressUpdated;

Event::listen(BatchProgressUpdated::class, function (BatchProgressUpdated $event) {
    ProcessProgressChanged::dispatch(
        recordId: $event->subjectKey,
        progress: $event->progress,
    );
});
```

`ProcessProgressChanged` is your own domain event (e.g. `ShouldBroadcastNow`
on a Reverb channel) — the package knows nothing about it and doesn't need to.

**Important:** `BatchOrchestrationFinished` marks the end of the chunk phase,
not necessarily the end of domain-level finalization — don't trigger logic
from this event that assumes the domain record is already at 100%/`completed`.

## Common mistakes

- **Don't broadcast the full domain model directly from a package
  listener.** The package's events deliberately carry only
  `subjectKey`/`BatchContext` — if your listener immediately loads the full
  model and pushes it over WebSockets, consider sending just the ID and
  letting the client fetch details instead.
- **Don't treat `BatchOrchestrationFinished` as the end of the merge job.**
  It's only the end of the parallel chunk phase; merge/finalization usually
  starts from `onBatchFinished()` and can still be running long after this
  event.
- **Listeners should be idempotent.** Queue-level retries, a manual batch
  re-run, or (rarely) duplicate event delivery should not corrupt state —
  don't assume exactly-once delivery at the whole-system scale, only within
  one batch.
- **A listener should not block the worker with a heavy operation.** Events
  are dispatched synchronously in the same process as the
  orchestrator/chunk job/trait — a slow listener (e.g. a call to an external
  API) extends the real processing time of that job.
- **Heavy reactions should dispatch their own queued listener or job.** If
  reacting to an event requires something expensive, register a queued
  listener (`ShouldQueue` on the listener, not on the package's event) or
  dispatch a separate job from it — the package's own event stays light and
  synchronous.

## See also

- `docs/AI_GUIDE.md` — the package's general invariant rules (progress, merge job, `getKey()`, etc.).
- `docs/BUFFERED_PAYLOADS.md` — the buffering/streaming mechanics several of these events are tied to.
- PHPDoc in `src/Events/*.php` and `src/Support/BatchContext.php` — the full, authoritative description of each event's fields and guarantees.
