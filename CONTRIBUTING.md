# Contributing

Thanks for considering a contribution to `kamz8/laravel-batch-orchestrator`.

## Requirements

- PHP 8.2, 8.3, or 8.4
- Composer
- Docker (recommended) — the test suite needs the `mbstring` and `redis`
  extensions; if your local PHP doesn't have them, run everything through a
  container that does (e.g. `docker compose exec <php-service> ...` /
  Laravel Sail) rather than the bare host PHP binary.

## Setup

```bash
composer install
```

## Running the tests

```bash
vendor/bin/phpunit
```

To check a specific Laravel major, temporarily float the constraints before
installing:

```bash
composer require "illuminate/bus:^11.0" "illuminate/support:^11.0" --no-update
composer require --dev "orchestra/testbench:^9.0" --no-update
composer update
vendor/bin/phpunit
```

See [`docs/COMPATIBILITY.md`](docs/COMPATIBILITY.md) for the full PHP ×
Laravel matrix and which `orchestra/testbench` line matches each Laravel
major.

## Code style

Pint enforces the code style; run it before committing:

```bash
vendor/bin/pint
```

## Making changes

- Keep the public API backward compatible unless a breaking change is
  genuinely unavoidable — call it out clearly in the PR description if so.
- Add or update tests for any behavioral change; `tests/Fixtures/` has
  reusable fakes (`FakeChunkableTask`, `FakeChunkJob`, etc.) — prefer
  extending those over writing new ones from scratch.
- If you're changing anything under `src/`, check
  [`docs/AI_GUIDE.md`](docs/AI_GUIDE.md)'s invariant rules first — several
  behaviors (exactly-once callbacks, progress capping at 80%, chunk-count
  consistency) are relied upon by consuming applications and are not
  incidental.
- Update `docs/AI_GUIDE.md` / `docs/LIFECYCLE_EVENTS.md` /
  `docs/BUFFERED_PAYLOADS.md` if your change affects the behavior they
  document — PHPDoc in `src/` is the source of truth for exact contracts;
  these docs should stay in sync with it, not drift.
- Add a `CHANGELOG.md` entry under `[Unreleased]` following
  [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Reporting bugs / requesting features

Open a GitHub issue with a minimal reproduction (a failing test is ideal) —
include your PHP and Laravel versions.

## No pressure

This is a small, focused package maintained in spare time — issues and PRs
are welcome, but there's no SLA on response time.
