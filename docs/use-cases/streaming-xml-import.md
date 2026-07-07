# Use case: strumieniowy import dużego pliku XML

**Problem:** feed produktowy XML, kilkaset MB, kilkaset tysięcy pozycji. Nie
można wczytać całości do pamięci (`SimpleXMLElement::load()` na całym pliku)
ani zbudować jednej wielkiej tablicy PHP przed dispatchem batcha.

**Rozwiązanie:** `XMLReader` czytający węzeł po węźle wewnątrz generatora w
`getChunks()`, grupowanie po N produktów na chunk, `ShouldBufferPayloads` żeby
każdy chunk job dostał tylko lekką referencję do Redis zamiast pełnej paczki
danych.

Pakiet **nie wymaga** własnej implementacji streamingu XML — ten wzorzec
opiera się wyłącznie na `getChunks(): iterable` będącym generatorem i na
`ShouldBufferPayloads`. Nic więcej nie trzeba dopisywać w orchestratorze.

## 1. Task

```php
use Kamz8\BatchOrchestrator\Concerns\BuffersPayloads;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;

class ImportProductFeedTask implements ChunkableTask, ShouldBufferPayloads
{
    use BuffersPayloads; // domyślny TTL/prefiks z config/batch-orchestrator.php

    private const int PRODUCTS_PER_CHUNK = 200;

    public function __construct(
        private readonly int $feedId,
        private readonly string $xmlPath, // tylko ścieżka — string, bezpieczny do serializacji
    ) {}

    /**
     * Generator: czyta plik węzeł po węźle, nigdy nie trzyma całego XML w pamięci.
     * Yielduje surowe dane co PRODUCTS_PER_CHUNK produktów.
     *
     * @return iterable<int, array<int, array<string, mixed>>>
     */
    public function getChunks(): iterable
    {
        $reader = new \XMLReader();
        $reader->open($this->xmlPath);

        $buffer = [];

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'product') {
                continue;
            }

            $node = new \SimpleXMLElement($reader->readOuterXml());

            $buffer[] = [
                'sku' => (string) $node->sku,
                'name' => (string) $node->name,
                'price' => (float) $node->price,
            ];

            if (count($buffer) >= self::PRODUCTS_PER_CHUNK) {
                yield $buffer;   // <-- orchestrator odbiera to jedno "chunkData"
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield $buffer; // ostatnia, niepełna grupa
        }

        $reader->close();
    }

    public function getChunkJobClass(): string
    {
        return ImportProductChunkJob::class;
    }

    public function queue(): string
    {
        return 'feed-imports';
    }

    public function onBatchFinished(string $batchId): void
    {
        MergeProductImportJob::dispatch($this->feedId)->onQueue($this->queue());
    }

    public function onBatchFailed(\Throwable $e): void
    {
        ProductFeed::find($this->feedId)?->markFailed($e->getMessage());
    }
}
```

Ważne: `$xmlPath` i `$feedId` to jedyne właściwości obiektu — oba skalarne.
Sam `XMLReader`/generator **nie jest** przechowywany jako property, więc
kopiowanie tasku do callbacku (`BaseOrchestrator::taskWithoutBufferedPayloads()`)
nie musi nic z niego usuwać poza samą kolekcją chunków.

## 2. Chunk job

```php
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kamz8\BatchOrchestrator\Concerns\InteractsWithBufferedPayload;

class ImportProductChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithBufferedPayload, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly mixed $chunkData) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Ten sam kod działa niezależnie od tego, czy ShouldBufferPayloads jest włączone.
        $products = $this->resolvePayload($this->chunkData);

        foreach ($products as $product) {
            Product::updateOrCreate(['sku' => $product['sku']], $product);
        }
    }
}
```

## 3. Merge/finalizacja job (reguła #4 z `AI_GUIDE.md` — NIE `Batchable`)

```php
class MergeProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $feedId) {}

    public function handle(): void
    {
        $feed = ProductFeed::findOrFail($this->feedId);
        $feed->setProgress(85);
        $feed->recalculateTotals();
        $feed->setProgress(95);
        $feed->markCompleted();
    }

    public function failed(\Throwable $e): void
    {
        ProductFeed::find($this->feedId)?->markFailed($e->getMessage());
    }
}
```

## 4. Dispatch

```php
$batchId = app(BatchProcessOrchestrator::class)
    ->dispatch(new ImportProductFeedTask($feed->id, $feed->xml_path));

$feed->update(['batch_id' => $batchId]);
```

## Co dokładnie dzieje się w pamięci

- Plik XML: strumień, stała pamięć niezależna od rozmiaru pliku (`XMLReader`).
- Każdy yield z generatora → natychmiast `Redis::setex()`, oryginalna grupa
  200 produktów może zostać zwolniona przez GC zaraz po tym.
- Chunk job trzyma tylko `BufferedPayloadReference` (klucz Redis + indeks) —
  kilkadziesiąt bajtów, nie 200 rekordów produktowych.
- Liczba chunk jobów w batchu = `ceil(liczba_produktów / 200)` — to
  nieuniknione, `Bus::batch` musi znać total jobs (patrz
  `docs/BUFFERED_PAYLOADS.md`, sekcja "Czego to NIE robi").

## Konfiguracja TTL dla dużych importów

Jeśli import 500k produktów realistycznie trwa dłużej niż domyślne 4h TTL,
podnieś `BATCH_ORCHESTRATOR_PAYLOAD_TTL` w `.env` albo nadpisz
`payloadTtl()` bezpośrednio na `ImportProductFeedTask` (zamiast polegać na
domyślnej implementacji z `BuffersPayloads`), żeby nie dzielić TTL z innymi
taskami w aplikacji korzystającymi z domyślnego configu.
