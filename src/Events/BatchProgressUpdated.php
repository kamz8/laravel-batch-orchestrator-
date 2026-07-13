<?php

declare(strict_types=1);

namespace Kamz8\BatchOrchestrator\Events;

/**
 * Dispatched by {@see \Kamz8\BatchOrchestrator\Concerns\HasBatchProgress} after
 * every successful progress write, from both `setProgress()` (`updateType` =
 * `'set'`) and `incrementChunkProgress()` (`updateType` = `'increment'`).
 *
 * Emission order: Redis write (`SETEX`) → **this event** →
 * `HasBatchProgress::onProgressUpdated()`. This existing hook's semantics are
 * unchanged by this event's introduction; both fire on every write.
 *
 * The host trait's class/model is intentionally not attached to this event —
 * only `$subjectKey` (its `getKey()`, stringified) and `$keyPrefix` (its
 * `progressKeyPrefix()`) are, so the event stays lightweight and serializable
 * even when the host is a heavy Eloquent model.
 *
 * Guarantees:
 * - Fired exactly once per successful `setProgress()`/`incrementChunkProgress()` call.
 * - `$progress` is already clamped to 0-100.
 *
 * Does NOT guarantee:
 * - `$previousProgress` is always `null` in this package's own emissions —
 *   reading the prior value would require an extra Redis round-trip on a
 *   hot path (chunk completion, potentially high-frequency). The field
 *   exists for forward-compatibility with consumers who track their own
 *   prior value and want to attach it via a custom listener.
 * - Ordering relative to other chunk jobs' progress events when multiple
 *   workers increment concurrently; `incrementChunkProgress()` is safe from
 *   double-counting (atomic `INCR`), but the events themselves may be
 *   observed out of order by an asynchronous listener.
 */
final readonly class BatchProgressUpdated
{
    /**
     * @param  string  $subjectKey  Stable identifier of the progress-tracked subject
     *                              (its `getKey()`, stringified) — never the model/object itself.
     * @param  string  $keyPrefix  The host's `progressKeyPrefix()`, useful for listeners
     *                             that need to reconstruct the underlying Redis key.
     * @param  int  $progress  New progress percentage, already clamped to 0-100.
     * @param  int|null  $previousProgress  Always `null` from this package's own writes; see above.
     * @param  string  $updateType  `'set'` (from `setProgress()`) or `'increment'`
     *                              (from `incrementChunkProgress()`).
     */
    public function __construct(
        public string $subjectKey,
        public string $keyPrefix,
        public int $progress,
        public ?int $previousProgress,
        public string $updateType,
    ) {}
}
