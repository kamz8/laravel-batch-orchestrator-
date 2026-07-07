# kamz8/laravel-batch-orchestrator

Dynamiczne chunkowanie i orkiestracja Laravel `Bus::batch()` dla długotrwałych zadań (raporty, eksporty), z opcjonalnym śledzeniem postępu w Redis.

> Przewodnik AI-ready (co gdzie, reguły niezmienne, recepta na nowy task): [`docs/AI_GUIDE.md`](docs/AI_GUIDE.md). Pełny opis API (parametry, zachowanie brzegowe) — PHPDoc w `src/`.

## Komponenty

- `Contracts\ChunkableTask` — kontrakt zadania: `getChunks()`, `getChunkJobClass()`, `queue()`, `onBatchFinished()`, `onBatchFailed()`.
- `Services\BaseOrchestrator` / `Services\BatchProcessOrchestrator` — dispatchuje batch, po zakończeniu/błędzie wywołuje odpowiednie metody taska.
- `Concerns\HasBatchProgress` — trait dla modeli, śledzenie procentowego postępu w Redis (chunki liczą się do 80%, reszta na etapie merge).

## Instalacja w tym monorepo

Pakiet jest częścią monorepo `app-deine` i podpięty przez `path` repository w root `composer.json`. Zmiany w `packages/kamz8/laravel-batch-orchestrator` wchodzą razem z commitem w `app-deine`.

## Eksport do GitLab (gitlab.com/veritas-group)

Repo już istnieje: [`gitlab.com/veritas-group/laravel-batch-orchestrator`](https://gitlab.com/veritas-group/laravel-batch-orchestrator).

```bash
# jednorazowo
git remote add veritas-batch-orchestrator https://gitlab.com/veritas-group/laravel-batch-orchestrator.git
```

**`git subtree push` wprost zadziała tylko dopóki historia na GitLabie jest liniowym potomkiem naszej.** Jeśli remote ma commity, których nie mamy lokalnie (np. ktoś ręcznie coś tam wypchnął), dostaniesz `non-fast-forward` i trzeba scalić przez tymczasowy worktree:

```bash
git subtree split --prefix=packages/kamz8/laravel-batch-orchestrator -b batch-orchestrator-split
git worktree add /tmp/batch-orch-split batch-orchestrator-split
cd /tmp/batch-orch-split
git fetch veritas-batch-orchestrator main
git merge --allow-unrelated-histories -X ours veritas-batch-orchestrator/main -m "merge: scal historię z GitLab"
git push veritas-batch-orchestrator HEAD:main
cd -
git worktree remove /tmp/batch-orch-split --force
git branch -D batch-orchestrator-split
```

Gdy historie się nie rozjadą (typowy przypadek po pierwszym scaleniu), wystarczy zwykłe:

```bash
git subtree push --prefix=packages/kamz8/laravel-batch-orchestrator veritas-batch-orchestrator main
```

Projekt konsumujący pakiet dodaje w swoim `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "git@gitlab.com:veritas-group/laravel-batch-orchestrator.git" }
]
```

i `composer require kamz8/laravel-batch-orchestrator`.
