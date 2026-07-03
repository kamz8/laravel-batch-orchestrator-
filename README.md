# kamz8/laravel-batch-orchestrator

Dynamiczne chunkowanie i orkiestracja Laravel `Bus::batch()` dla długotrwałych zadań (raporty, eksporty), z opcjonalnym śledzeniem postępu w Redis.

## Komponenty

- `Contracts\ChunkableTask` — kontrakt zadania: `getChunks()`, `getChunkJobClass()`, `queue()`, `onBatchFinished()`, `onBatchFailed()`.
- `Services\BaseOrchestrator` / `Services\BatchProcessOrchestrator` — dispatchuje batch, po zakończeniu/błędzie wywołuje odpowiednie metody taska.
- `Concerns\HasBatchProgress` — trait dla modeli, śledzenie procentowego postępu w Redis (chunki liczą się do 80%, reszta na etapie merge).

## Instalacja w tym monorepo

Pakiet jest częścią monorepo `app-deine` i podpięty przez `path` repository w root `composer.json`. Zmiany w `packages/kamz8/laravel-batch-orchestrator` wchodzą razem z commitem w `app-deine`.

## Eksport do GitLab (gitlab.com/veritas-group)

Repo GitLab tworzone jest manualnie. Po utworzeniu pustego repo `veritas-group/laravel-batch-orchestrator`, dodaj remote i wypchnij historię tego podkatalogu przez `git subtree`:

```bash
# jednorazowo
git remote add veritas-batch-orchestrator git@gitlab.com:veritas-group/laravel-batch-orchestrator.git

# po każdej zmianie w packages/kamz8/laravel-batch-orchestrator, po commicie w app-deine
git subtree push --prefix=packages/kamz8/laravel-batch-orchestrator veritas-batch-orchestrator main
```

Projekt konsumujący pakiet dodaje w swoim `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "git@gitlab.com:veritas-group/laravel-batch-orchestrator.git" }
]
```

i `composer require kamz8/laravel-batch-orchestrator`.
