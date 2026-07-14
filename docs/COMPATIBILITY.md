# Compatibility Matrix

| Laravel | PHP 8.2 | PHP 8.3 | PHP 8.4 | Notes |
|---|---|---|---|---|
| 10.x | ✅ | ✅ | ⚠️ best-effort | Composer constraints allow PHP 8.4, but Laravel 10 predates its release — CI runs this combination with `continue-on-error`. |
| 11.x | ✅ | ✅ | ✅ | |
| 12.x | ✅ | ✅ | ✅ | |
| 13.x | ❌ unsupported | ✅ | ✅ | `orchestra/testbench ^11.0` (Laravel 13's testbench line) requires PHP `^8.3`; PHP 8.2 is excluded from CI for this row. |

## What was actually verified, and how

All 48 tests were run against **all four** Laravel majors on real, currently
published packages — not just composer-constraint math — inside this
project's PHP 8.3.30 Sail container (`shivammathur`-equivalent extensions,
including `mbstring`/`redis`, which the bare host PHP in this environment
lacks). Composer's `audit.block-insecure` gate had to be disabled for the
resolution (see the note in `.github/workflows/tests.yml`) since it otherwise
rejects every Laravel 10.x release outright due to accumulated security
advisories against the 10.x line — this affects dependency *resolution* in a
throwaway verification/CI copy, not `composer audit` reporting for real
installs.

| Laravel | Resolved `laravel/framework` | Resolved `orchestra/testbench` | Resolved `phpunit/phpunit` | Result |
|---|---|---|---|---|
| 10 | 10.50.2 | 8.37.0 | 10.5.64 | 48/48 passing |
| 11 | 11.54.0 | 9.17.0 | 12.5.31 | 48/48 passing |
| 12 | 12.63.0 | 10.11.0 | 12.5.31 | 48/48 passing |
| 13 | 13.19.0 | 11.1.0 | 12.5.31 | 48/48 passing |

PHP 8.2 and 8.4 combinations rely on the GitHub Actions matrix in
`.github/workflows/tests.yml` for coverage — this local verification only had
PHP 8.3 available.

## Framework differences requiring conditional handling

**One real difference was found and normalized in the package itself** (no
conditional/`version_compare()` branching needed in `src/` as a result —
the fix is version-agnostic):

`Illuminate\Bus\Batch::add()` swallows a chunk job's exception when the queue
connection is `sync` **only on Laravel 11** (an internal special case added
to that release). Laravel 10, 12, and 13 all lack it and would otherwise
re-throw the exception out of `Bus::batch(...)->dispatch()` — even though the
batch's own `catch()` callback (and this package's `onBatchFailed()` /
`BatchOrchestrationFailed` event) had already run correctly by that point.
`BaseOrchestrator::dispatch()` now catches that redundant, version-dependent
exception (via a static property, since `then`/`catch` callbacks are always
routed through `Laravel\SerializableClosure` and lose any `use (&$var)`
reference capture — see the PHPDoc on `BaseOrchestrator::$lastCaughtBatchId`)
so `dispatch()` behaves identically across all four supported majors.

Beyond that one case, the package's entire integration surface —
`Illuminate\Support\Facades\{Bus,Event,Redis}`,
`Illuminate\Support\ServiceProvider`, `Illuminate\Bus\{Batch,Batchable}` — has
been API-stable across Laravel 10 through 13.

## Upgrading from a Laravel 9 or earlier host application

Not supported — Laravel 9 reached end of life before this package existed.
Upgrade the host application to Laravel 10+ first.
