# Eventy cyklu życia (`src/Events/`)

> Ten dokument opisuje 8 eventów, które pakiet **dispatchuje** (przez
> `Illuminate\Support\Facades\Event::dispatch()`) w kluczowych momentach
> orchestracji batcha i operacji na buforowanych payloadach. Pakiet tylko
> emituje — nie rejestruje żadnych listenerów, nie broadcastuje
> (`ShouldBroadcast`) i nie kolejkuje (`ShouldQueue`) ich. Konsument sam
> decyduje, czy je zaloguje, przerobi na własny broadcastowany event, zaktualizuje
> UI, czy zignoruje. Pełny opis pól/gwarancji każdego eventu żyje w PHPDoc przy
> klasie w `src/Events/` — ten dokument tego nie duplikuje, tylko pokazuje
> kolejność i sposób integracji.

## Diagram cyklu życia

```
dispatch()
  → payload stored × N                    (BufferedPayloadStored, tylko ShouldBufferPayloads)
  → Laravel Batch dispatched
  → BatchOrchestrationStarted              (dopiero po dispatch(), z prawdziwym batchId)
  → (w chunk jobach) BufferedPayloadResolved × N / BufferedPayloadResolutionFailed
  → BatchProgressUpdated × N               (z setProgress()/incrementChunkProgress() na modelu)
  ├─ success:
  │    → payload cleanup (partiami)
  │    → BufferedPayloadCleanupCompleted    (tylko ShouldBufferPayloads)
  │    → BatchOrchestrationFinished
  │    → ChunkableTask::onBatchFinished()
  └─ failure:
       → payload cleanup (partiami)
       → BufferedPayloadCleanupCompleted    (tylko ShouldBufferPayloads)
       → BatchOrchestrationFailed
       → ChunkableTask::onBatchFailed()
```

**Zastrzeżenie (queue `sync`):** przy `queue.default = sync` cały batch —
łącznie z gałęzią sukcesu/porażki i `onBatchFinished()`/`onBatchFailed()` —
wykonuje się **wewnątrz** wywołania `->dispatch()` w `Bus::batch(...)->dispatch()`.
`BatchOrchestrationStarted` jest emitowany dopiero **po** tym wywołaniu (bo
dopiero wtedy istnieje prawdziwy `batchId`), więc w trybie sync obserwujesz go
**po** zdarzeniach finalizujących. Na realnej (asynchronicznej) kolejce
`dispatch()` wraca zaraz po zapisaniu batcha i wypchnięciu jobów do kolejki —
`Started` poprzedza wykonanie chunków, tak jak nazwa sugeruje. Jedyna
gwarantowana kolejność niezależnie od typu kolejki to pod-sekwencja
finalizacji: `cleanup → BufferedPayloadCleanupCompleted → Finished/Failed →
onBatchFinished()/onBatchFailed()`.

## Tabela eventów

| Event | Moment emisji | Najważniejsze dane | Czego event NIE oznacza |
|---|---|---|---|
| `BatchOrchestrationStarted` | Zaraz po skutecznym `->dispatch()` Laravel Batcha | `BatchContext` (z prawdziwym `batchId`), `buffered: bool` | Że jakikolwiek chunk job już się wykonał (na kolejce sync może być odwrotnie — patrz zastrzeżenie wyżej) |
| `BatchOrchestrationFinished` | W callbacku `then()`, po cleanupie payloadów, przed `onBatchFinished()` | `BatchContext` z `batchId` | Zakończenia całego procesu domenowego — merge/finalizacja może nadal trwać |
| `BatchOrchestrationFailed` | W callbacku `catch()`, po cleanupie payloadów, przed `onBatchFailed()` | `BatchContext`, `exceptionClass`, `message`, `code` | Że wszystkie chunk joby na pewno przestały działać — inne mogły być w locie |
| `BatchProgressUpdated` | Po każdym udanym zapisie w `HasBatchProgress` (`setProgress()`/`incrementChunkProgress()`), przed `onProgressUpdated()` | `subjectKey` (string, nie model!), `keyPrefix`, `progress`, `previousProgress` (zawsze `null` z tego pakietu), `updateType` (`'set'`\|`'increment'`) | Że to ostatnia zmiana progresu — kolejne joby/kroki mogą jeszcze podbić wartość |
| `BufferedPayloadStored` | Zaraz po udanym `Redis::setex()` dla jednego chunku | `key`, `batchKey`, `index`, `ttl` | Że chunk job z tym payloadem już został zakolejkowany/wykonany |
| `BufferedPayloadResolved` | W `resolvePayload()`, po udanym `Redis::get()` + `unserialize()` | `key`, `batchKey`, `index` | Że dalsza logika `handle()` chunk joba się powiedzie |
| `BufferedPayloadResolutionFailed` | W `resolvePayload()`, tuż przed rzuceniem `RuntimeException` (brak klucza lub zepsute dane) | `key`, `batchKey`, `index`, `reason` | Rozróżnienia dokładnej przyczyny poza czytelnym tekstem `reason` |
| `BufferedPayloadCleanupCompleted` | Po przejściu całego (partiami) cleanupu Redis, tylko dla `ShouldBufferPayloads` | `BatchContext`, `deletedKeys` (liczba, nie lista kluczy) | Że wszystkie klucze fizycznie zniknęły z Redis — `DEL` na już wygasłym kluczu też się liczy |

## Przykład: logowanie błędu orchestracji

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

## Przykład: przekucie progresu na własny broadcastowany event domenowy

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

`ProcessProgressChanged` to Twój własny event domenowy (np. `ShouldBroadcastNow`
na kanale Reverb) — pakiet nic o nim nie wie i nie musi.

**Ważne:** `BatchOrchestrationFinished` oznacza koniec fazy chunków, a
niekoniecznie koniec merge/finalizacji domenowej — nie triggeruj z tego eventu
logiki, która zakłada, że rekord domenowy jest już w 100%/`completed`.

## Common mistakes

- **Nie broadcastuj całego modelu domenowego bezpośrednio z listenera pakietu.**
  Eventy pakietu celowo niosą tylko `subjectKey`/`BatchContext` — jeśli Twój
  listener od razu ładuje pełny model i wysyła go po WebSocketach, przemyśl,
  czy nie chcesz zamiast tego wysłać samego ID i pozwolić klientowi dociągnąć
  szczegóły.
- **Nie traktuj `BatchOrchestrationFinished` jako końca merge joba.** To tylko
  koniec fazy równoległych chunków; merge/finalizacja startuje zwykle z
  `onBatchFinished()` i może jeszcze trwać długo po tym evencie.
- **Listenery powinny być idempotentne.** Retry na poziomie kolejki, ręczne
  ponowne uruchomienie batcha, albo (rzadko) powtórzone dostarczenie eventu nie
  powinny psuć stanu — nie zakładaj dokładnie jednego wywołania w globalnej
  skali systemu, tylko w skali jednego batcha.
- **Listener nie powinien blokować workera ciężką operacją.** Eventy są
  dispatchowane synchronicznie w tym samym procesie co orchestrator/chunk
  job/trait — długi listener (np. zapytanie do zewnętrznego API) wydłuża
  realny czas przetwarzania joba.
- **Ciężkie reakcje powinny dispatchować własny queued listener albo job.**
  Jeśli reakcja na event wymaga czegoś kosztownego, zarejestruj queued
  listener (`ShouldQueue` na listenerze, nie na evencie pakietu) albo
  dispatchuj z niego osobny job — sam event z pakietu pozostaje lekki i
  synchroniczny.

## Zobacz też

- `docs/AI_GUIDE.md` — ogólne reguły niezmienne pakietu (progres, merge job, `getKey()` itd.).
- `docs/BUFFERED_PAYLOADS.md` — mechanika buforowania/strumieniowania, z którą część tych eventów jest ściśle związana.
- PHPDoc w `src/Events/*.php` i `src/Support/BatchContext.php` — pełny, autorytatywny opis pól i gwarancji każdego eventu.
