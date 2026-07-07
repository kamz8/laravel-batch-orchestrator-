# kamz8/laravel-batch-orchestrator — przewodnik (AI-ready)

> Cel: szybkie, jednoznaczne instrukcje jak **skonsumować pakiet w nowym projekcie** i jak **dodać nowy `ChunkableTask`**, bez czytania całego źródła.
> Pełny opis API (parametry, zachowanie brzegowe) żyje w **PHPDoc** przy każdej klasie/metodzie w `src/` — ten dokument tego nie duplikuje, tylko mapuje "co gdzie" i wypisuje reguły niezmienne.
> Historia ekstrakcji z app-deine: [`../../../docs/reports/BATCH_ORCHESTRATOR_TECHNICAL.md`](../../../docs/reports/BATCH_ORCHESTRATOR_TECHNICAL.md), [`../../../docs/reports/CHUNKING_ARCHITECTURE.md`](../../../docs/reports/CHUNKING_ARCHITECTURE.md).

## TL;DR — założenia

- Pakiet dzieli duże zadanie na chunki, odpala je jako jeden `Illuminate\Bus\Batch`, i po zakończeniu/błędzie woła z powrotem dokładnie jedną metodę na Twoim tasku (`onBatchFinished` / `onBatchFailed`).
- Progres (0-100%) trzymany jest w Redis przez trait `HasBatchProgress` — **chunki nigdy nie przekraczają 80%**, reszta (85%, 95%, 100%) to Twoja odpowiedzialność w kroku finalizującym (merge job).
- Pakiet nie wie nic o Twoim modelu domenowym (np. `Report`) — cała integracja idzie przez interfejs `ChunkableTask` i trait `HasBatchProgress`.

## Komponenty (ścieżki plików)

| Rola | Klasa | Plik |
|------|-------|------|
| Kontrakt zadania chunkowanego | `ChunkableTask` | `src/Contracts/ChunkableTask.php` |
| Silnik (abstrakcyjny) | `BaseOrchestrator` | `src/Services/BaseOrchestrator.php` |
| Silnik (do wstrzykiwania) | `BatchProcessOrchestrator` | `src/Services/BatchProcessOrchestrator.php` |
| Progres w Redis (trait) | `HasBatchProgress` | `src/Concerns/HasBatchProgress.php` |
| Service Provider | `BatchOrchestratorServiceProvider` | `src/BatchOrchestratorServiceProvider.php` |
| Konfiguracja | `batch-orchestrator.progress_ttl` | `config/batch-orchestrator.php` |

### Przepływ

```
$orchestrator->dispatch($task)                         // BatchProcessOrchestrator::dispatch()
  └─ Bus::batch([ChunkJob × N])  → onQueue($task->queue())     chunki: incrementChunkProgress() → 0–80 %
       ├─ then()  → $task->onBatchFinished($batchId)     // np. dispatch mergeJob   → setProgress(85→95) → 100%
       └─ catch() → $task->onBatchFailed($exception)     // np. markFailed() + purge chunków
```

## ⛔ Reguły niezmienne (NIE łamać)

1. **Klasa z `getChunkJobClass()` MUSI przyjmować payload chunku jako jedyny argument konstruktora** i używać `Illuminate\Bus\Batchable`, żeby móc sprawdzić `$this->batch()?->cancelled()`.
2. **`onBatchFinished`/`onBatchFailed` wywoła się dokładnie raz** — nie zakładaj retry na tym poziomie; logikę retry/idempotencji trzeba wbudować w merge/finalizację.
3. **`incrementChunkProgress($totalChunks)` musi dostać tę samą wartość `$totalChunks` przy każdym wywołaniu w ramach jednego batcha** — inaczej procent się rozjedzie (dzielenie przez inną liczbę za każdym razem).
4. **Merge/finalizacja NIE dziedziczy z `Batchable`** (to osobny, samodzielny job) — musi mieć własny `failed(Throwable $e)`, inaczej wyjątek ginie cicho w `failed_jobs`, a progres wisi na etapie przed 100%. (Wzorzec: `MergePdfChunksJob` w app-deine.)
5. **Klasa hosta traita `HasBatchProgress` musi mieć `getKey()`** (Eloquent ma to natywnie; dla nie-Eloquent klas dopisz metodę zwracającą stabilny identyfikator).
6. **Zmieniając `progressKeyPrefix()` na już działającym modelu — nie zmieniaj go bez migracji danych**, bo zmienia to nazwy kluczy Redis i traci się dostęp do już zapisanego postępu (nieszkodliwe, bo to tylko cache z TTL, ale UI pokaże "brak postępu" do czasu następnego zapisu).

## Recepta — nowy `ChunkableTask`

1. **Task** implementujący `ChunkableTask` (patrz PHPDoc interfejsu dla pełnego kontraktu):
   ```php
   class MyTask implements ChunkableTask
   {
       public function __construct(private MyDomainRecord $record) {}
       public function queue(): string { return 'batch-processing'; }
       public function getChunks(): array { /* tablica payloadów, jeden element = jeden chunk job */ }
       public function getChunkJobClass(): string { return MyChunkJob::class; }
       public function onBatchFinished(string $batchId): void
       {
           MyMergeJob::dispatch($this->record->id)->onQueue($this->queue());
       }
       public function onBatchFailed(Throwable $e): void
       {
           MyChunkJob::purgeChunks($this->record->id);
           $this->record->markFailed($e->getMessage()); // Twoja logika domenowa
       }
   }
   ```
2. **Chunk job** — `implements ShouldQueue`, `use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels`; guard `if ($this->batch()?->cancelled()) return;` na początku `handle()`; na końcu `$record->incrementChunkProgress($totalChunks)` (wymaga traita `HasBatchProgress` na `$record`).
3. **Merge/finalizacja job** — osobny, **nie** `Batchable`; `setProgress(85)` na starcie, `setProgress(95)` przed zapisem wyniku, potem Twoja metoda kończąca (np. `markCompleted()`) i **obowiązkowo** `failed(Throwable $e)` (reguła #4).
4. **Dispatch**: `app(BatchProcessOrchestrator::class)->dispatch(new MyTask($record))` — zwraca `$batchId`, zapisz go jeśli chcesz później `Bus::findBatch($batchId)?->cancel()`.
5. Model/rekord domenowy: `use HasBatchProgress;` + w razie retrofitu na istniejący model, nadpisz `progressKeyPrefix()` zwracając stary prefiks kluczy (patrz reguła #6) i `onProgressUpdated()` żeby wybroadcastować własny event.

## Instalacja poza monorepo app-deine

```json
"repositories": [
    { "type": "vcs", "url": "git@gitlab.com:veritas-group/laravel-batch-orchestrator.git" }
],
"require": {
    "kamz8/laravel-batch-orchestrator": "^1.0"
}
```
Wymaga tabeli `job_batches` (`php artisan queue:batches-table` + migracja) i queue connection wspierającego batching. Config publikowany tagiem `batch-orchestrator-config` (`php artisan vendor:publish --tag=batch-orchestrator-config`).

## Testy (wzorzec do skopiowania)

`tests/Fixtures/` — `FakeChunkableTask`, `FakeChunkJob`, `FakeFailingChunkJob`, `FakeProgressModel`. `tests/Unit/HasBatchProgressTest.php` mockuje fasadę `Redis` (bez realnego Redisa). `tests/Feature/BatchProcessOrchestratorTest.php` odpala realny `Bus::batch` na sqlite `:memory:` + queue sync (ręcznie tworzy tabelę `job_batches` w `setUp()`, bo Testbench jej nie dostarcza domyślnie).

## Checklist nowego taska

- [ ] Task implementuje `ChunkableTask` (4 metody + `onBatchFinished`/`onBatchFailed`).
- [ ] Chunk job: `Batchable`, guard `cancelled()`, jeden argument konstruktora.
- [ ] Merge/finalizacja: **nie** `Batchable`, ma `failed()` (reguła #4).
- [ ] Model domenowy: `use HasBatchProgress` (+ ew. `progressKeyPrefix()`/`onProgressUpdated()`).
- [ ] Test feature: dispatch przez `BatchProcessOrchestrator`, assert na `onBatchFinished`/`onBatchFailed` (wzorzec: `tests/Feature/BatchProcessOrchestratorTest.php`).
