# Buforowane payloady i strumieniowanie (`ShouldBufferPayloads`)

> Ten dokument odpowiada precyzyjnie na pytanie: "czy mogę strumieniować duże
> źródło danych (np. plik XML) przez `getChunks()` bez trzymania całości w
> pamięci, i co dokładnie robi `ShouldBufferPayloads`?". Wszystkie twierdzenia
> poniżej są oparte na kodzie w `src/`, nie na domysłach — ścieżki i numery
> linii wskazują dokładnie gdzie to się dzieje.

## TL;DR

1. `getChunks(): iterable` **może** być generatorem. Orchestrator konsumuje go
   przez zwykłe `foreach`, nigdy nie robi `iterator_to_array()`.
2. Zbuforowany payload trafia **do Redis** (`Illuminate\Support\Facades\Redis::setex()`),
   nie na dysk (`Storage`) i nie do generycznej fasady `Cache`. TTL i prefiks
   klucza są konfigurowalne w `config/batch-orchestrator.php`.
3. Buforowanie jest **w pełni automatyczne po stronie orchestratora**. Task
   implementuje `ShouldBufferPayloads` i yielduje surowe dane tak samo jak bez
   buforowania — nie wywołuje żadnej metody "zapisz payload". Orchestrator sam
   robi `Redis::setex()` i podmienia argument konstruktora chunk joba na
   `BufferedPayloadReference`. Chunk job musi jedynie wywołać
   `resolvePayload()` na starcie `handle()`.

## Co się dzieje krok po kroku (`BaseOrchestrator::dispatch()`)

Plik: `src/Services/BaseOrchestrator.php`

```php
$chunks = $task->getChunks();                          // (1) generator albo array — nic jeszcze nie jest konsumowane

foreach ($chunks as $index => $chunkData) {             // (2) leniwa konsumpcja — jeden element na iterację
    $payload = $chunkData;

    if ($task instanceof ShouldBufferPayloads) {
        $key = sprintf('%s:%s:%s', $task->payloadKeyPrefix(), $batchKey, $index);
        Redis::setex($key, $task->payloadTtl(), serialize($chunkData));   // (3) surowe dane lecą do Redis i mogą zostać zwolnione z PHP
        $payload = new BufferedPayloadReference($key, $batchKey, $index); // (4) job dostaje tylko lekki wskaźnik
    }

    $jobBuffer[] = new $jobClass($payload);

    if (count($jobBuffer) >= $flushSize) {               // (5) domyślnie 100 — patrz sekcja "Czego to NIE robi"
        $pendingBatch->add($jobBuffer);
        $jobBuffer = [];
    }
}
```

Chunk job (musi mieć `use InteractsWithBufferedPayload;`):

```php
public function handle(): void
{
    $data = $this->resolvePayload($this->chunkData); // zwraca oryginał (non-buffered) albo odczytuje+unserializuje z Redis
    // ... normalna logika na $data
}
```

`resolvePayload()` (`src/Concerns/InteractsWithBufferedPayload.php`):
- jeśli argument nie jest `BufferedPayloadReference` → zwraca go bez zmian (ta sama klasa joba działa buforowana i niebuforowana),
- jeśli jest → `Redis::get($key)`, `unserialize()`; brakujący klucz lub zepsute dane → `RuntimeException`, co ląduje w `onBatchFailed()` przez normalny mechanizm batcha.

## Gdzie to jest konfigurowalne

Plik: `config/batch-orchestrator.php`

| Klucz | Domyślna wartość | Co robi |
|---|---|---|
| `payload_ttl` | `14400` (4h) | TTL klucza Redis; musi pokrywać cały cykl życia chunk joba **łącznie z ewentualnymi retry** — zbyt krótki TTL = `RuntimeException` przy retry po wygaśnięciu klucza. |
| `payload_key_prefix` | `batch-orchestrator:payload` | Prefiks klucza Redis (`{prefix}:{batchKey}:{index}`). |
| `payload_chunk_flush_size` | `100` | Ile obiektów chunk joba czeka w PHP-owej tablicy `$jobBuffer` zanim trafi do `$pendingBatch->add()`. Patrz zastrzeżenie niżej — to NIE jest budżet pamięci na cały batch. |

Task zwykle nie nadpisuje tych metod ręcznie — `use BuffersPayloads;` czyta je
z configu. Nadpisz `payloadTtl()`/`payloadKeyPrefix()` na tasku tylko gdy
konkretny workload potrzebuje dłuższego okna retry albo izolowanej przestrzeni
kluczy Redis.

## Czego to NIE robi (ważne dla poprawnego planu)

`Illuminate\Bus\Batch` musi znać **całkowitą liczbę jobów** żeby śledzić
postęp (`$batch->totalJobs`) — więc wszystkie N obiektów chunk joba i tak
zostają zbudowane i przekazane do `PendingBatch` przed `dispatch()`. Generator
**nie zmniejsza liczby jobów w batchu**. `payload_chunk_flush_size` kontroluje
tylko rozmiar pomocniczej tablicy `$jobBuffer` między kolejnymi wywołaniami
`add()` — nie jest to "okno pamięci" całego batcha, bo `PendingBatch` i tak
trzyma referencje do wszystkich dodanych jobów aż do `dispatch()`.

To, co faktycznie daje strumieniowanie i buforowanie:

- **Źródło danych nigdy nie jest materializowane w całości** — czytasz/grupujesz/yieldujesz na bieżąco (np. `XMLReader` czytający węzeł po węźle), więc plik XML na 500 MB nie ląduje w jednym dużym PHP-owym array.
- **Payload każdego joba jest mały** — z `ShouldBufferPayloads` job trzyma `BufferedPayloadReference` (string + opcjonalny int), a nie pełny wycinek danych. To sprawia, że pamięć zajęta przez wszystkie N obiektów jobów w `PendingBatch` jest rzędu N × kilkadziesiąt bajtów, a nie N × rozmiar chunku.

Innymi słowy: liczba jobów pozostaje N (to nieuniknione dla `Bus::batch`),
ale ich "waga" w pamięci procesu dispatchującego oraz w kolejce (payload w
`jobs`/`failed_jobs`) jest drastycznie mniejsza, a parser źródła nigdy nie
trzyma całego zbioru na raz.

## Pułapka: task nie może trzymać generatora jako property

Zamknięcia (`Closure`) przekazane do `->then()`/`->catch()` w
`BaseOrchestrator::dispatch()` przechwytują `$callbackTask` przez `use (...)`.
Dla realnej (nie-`sync`) kolejki ten callback jest serializowany razem z
batchem. Jeśli task implementuje `ShouldBufferPayloads`, orchestrator
automatycznie tworzy "czystą" kopię tasku bez chunk-source (patrz
`BaseOrchestrator::taskWithoutBufferedPayloads()`), więc niebezpieczne
właściwości (np. `Traversable`, `Closure`) są usuwane z kopii używanej w
callbacku.

**Dla tasków bez `ShouldBufferPayloads` tego zabezpieczenia nie ma.** Zasada:
nie trzymaj generatora/iteratora jako properties na obiekcie tasku. Buduj go
na bieżąco wewnątrz `getChunks()` z niezmiennych, serializowalnych danych
(np. ścieżka do pliku przekazana w konstruktorze), tak jak robi to
`FakeGeneratorChunkableTask` w testach — konstruktor trzyma tylko skalarne
`count`/`jobClass`/`queueName`, generator jest tworzony od nowa przy każdym
wywołaniu `getChunks()`.

## Retry i TTL — zastrzeżenie z kontraktu

Pełny opis w PHPDoc `src/Contracts/ShouldBufferPayloads.php`: kolejkowany
retry może odczytać payload tylko dopóki klucz Redis istnieje. TTL musi być
dłuższy niż maksymalne opóźnienie kolejki + okno retry + spodziewany czas
trwania całego batcha. Brakujący lub zepsuty klucz jest traktowany jak błąd
zadania (`RuntimeException`), więc batch poprawnie przechodzi w
`onBatchFailed()` zamiast ciszej awarii.

## Zobacz też

- `docs/use-cases/streaming-xml-import.md` — pełny przykład: duży plik XML → grupowanie N rekordów → `ShouldBufferPayloads` → chunk job → merge job.
- `docs/use-cases/small-generator-no-buffering.md` — kiedy generator wystarczy sam, bez buforowania w Redis.
- `docs/AI_GUIDE.md` — ogólne reguły niezmienne pakietu (progres, merge job, `getKey()` itd.).
