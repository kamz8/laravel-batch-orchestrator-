# Changelog

All notable changes to `kamz8/laravel-batch-orchestrator` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows [SemVer](https://semver.org/).

## [1.1.0] - 2026-07-13

### Added
- Laravel 13 support (alongside continued Laravel 10/11/12 support). See [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) for the full PHP × Laravel matrix, verified against real released packages for all four majors.
- 8 lifecycle events (`BatchOrchestrationStarted/Finished/Failed`, `BatchProgressUpdated`, `BufferedPayloadStored/Resolved/ResolutionFailed/CleanupCompleted`) dispatched via `Illuminate\Support\Facades\Event` at key orchestration and buffered-payload points — see [`docs/LIFECYCLE_EVENTS.md`](docs/LIFECYCLE_EVENTS.md).
- `Kamz8\BatchOrchestrator\Support\BatchContext` — immutable DTO carrying stable, serializable batch identity (`taskClass`, `queue`, `batchId`, `batchKey`, `totalChunks`) used by the new lifecycle events.
- `payload_cleanup_chunk_size` config option (default `500`) controlling how many Redis keys are deleted per batched `DEL` during buffered-payload cleanup.
- `docs/COMPATIBILITY.md` — PHP × Laravel support matrix and upgrade notes.

### Changed
- `composer.json`: widened `illuminate/bus`/`illuminate/support` to `^10.0|^11.0|^12.0|^13.0`, `orchestra/testbench` to `^8.0|^9.0|^10.0|^11.0`, `phpunit/phpunit` floor raised to `^10.5` (from `^11.0`) to keep the matrix's PHPUnit ranges intersecting cleanly across all four Laravel majors. The package's own PHP floor is unchanged (`^8.2|^8.3`, plus new `^8.4` support).
- CI matrix expanded from PHP 8.2/8.3 × Laravel 10/11 to PHP 8.2/8.3/8.4 × Laravel 10/11/12/13 (12 combinations, 1 excluded, 1 best-effort — see `.github/workflows/tests.yml`).
- All user-facing documentation (`docs/AI_GUIDE.md`, `docs/LIFECYCLE_EVENTS.md`, `docs/BUFFERED_PAYLOADS.md`, `docs/use-cases/*.md`) translated from Polish to English for GitHub/international discoverability and LLM-assisted development. PHPDoc in `src/**` remains the source of truth for API details; docs link to it rather than duplicating it.
- `docs/AI_GUIDE.md` gained new sections: Architecture Overview, Lifecycle Events, Buffered Payloads, Common Mistakes, Anti-patterns, When NOT to use this package, FAQ, and an explicit Guarantees/Non-guarantees subsection.

### Fixed
- **`BaseOrchestrator::dispatch()` could throw on `queue.default=sync` on Laravel 10, 12, and 13 when a chunk job failed**, even though the batch's `onBatchFailed()`/`BatchOrchestrationFailed` had already fired correctly. Root cause: `Illuminate\Bus\Batch::add()` only swallows that exception internally on Laravel 11 (a framework-specific special case); the other three supported majors re-throw it. `dispatch()` now normalizes this so it always returns the batch ID and never leaks that redundant, Laravel-version-dependent exception to the caller. Found and fixed while verifying real (not just constraint-level) compatibility across all four supported Laravel majors for this release — see [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) for details.

## [1.0.0] - 2026-07-07

Initial public release: dynamic chunking/merge orchestration for `Illuminate\Bus\Batch`, Redis-backed progress tracking (`HasBatchProgress`), Redis-buffered/streamed payloads (`ShouldBufferPayloads`, generator support in `getChunks()`).
