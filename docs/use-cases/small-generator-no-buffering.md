# Use case: generator bez `ShouldBufferPayloads`

**Kiedy to wystarcza:** liczba chunków jest umiarkowana i **każdy pojedynczy
chunk payload** jest mały (np. kilkadziesiąt/kilkaset ID-ków albo mały wycinek
danych) — źródło samo w sobie może być duże (np. strumieniowe czytanie z
bazy przez kursor), ale to, co trafia do kolejki na chunk job, jest lekkie.

W takim wypadku generator w `getChunks()` sam wystarcza. Nie trzeba
implementować `ShouldBufferPayloads` — payload chunk joba trafia bezpośrednio
do tabeli `jobs`/`failed_jobs` tak jak przy zwykłej tablicy, po prostu bez
budowania całej kolekcji chunków na starcie.

**Kiedy NIE wystarcza (użyj `ShouldBufferPayloads`, patrz
`docs/use-cases/streaming-xml-import.md`):** pojedynczy chunk sam w sobie jest
ciężki (duże obiekty, długie stringi, zagnieżdżone struktury) — wtedy payload
kolejki (SQS/Redis/database driver) rośnie proporcjonalnie i trzeba go
odciążyć do Redis.

## Przykład: eksport ID-ków rekordów spełniających warunek

```php
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;

class ExportFilteredRecordsTask implements ChunkableTask
{
    private const int IDS_PER_CHUNK = 500;

    public function __construct(
        private readonly int $exportId,
        private readonly string $status, // skalar — bezpieczny do serializacji obiektu tasku
    ) {}

    /**
     * @return iterable<int, array<int, int>>
     */
    public function getChunks(): iterable
    {
        $buffer = [];

        // cursor() strumieniuje wiersze z bazy jeden po drugim,
        // nigdy nie materializuje całego wyniku w pamięci
        foreach (Record::where('status', $this->status)->cursor() as $record) {
            $buffer[] = $record->id;

            if (count($buffer) >= self::IDS_PER_CHUNK) {
                yield $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield $buffer;
        }
    }

    public function getChunkJobClass(): string
    {
        return ExportRecordChunkJob::class;
    }

    public function queue(): string
    {
        return 'exports';
    }

    public function onBatchFinished(string $batchId): void
    {
        MergeExportJob::dispatch($this->exportId)->onQueue($this->queue());
    }

    public function onBatchFailed(\Throwable $e): void
    {
        Export::find($this->exportId)?->markFailed($e->getMessage());
    }
}
```

Chunk job odbiera surową tablicę ID-ków wprost — bez `InteractsWithBufferedPayload`,
bo nie ma żadnej referencji do rozwiązywania:

```php
class ExportRecordChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly array $ids) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Record::whereIn('id', $this->ids)->chunk(100, function ($records) {
            // ... zapis do pliku eksportu, append-only
        });
    }
}
```

## Reguła wyboru

| Sytuacja | Rozwiązanie |
|---|---|
| Źródło duże, ale pojedynczy chunk mały (ID-ki, krótkie skalary) | sam generator w `getChunks()`, bez `ShouldBufferPayloads` |
| Źródło duże **i** pojedynczy chunk sam w sobie ciężki (sparsowane node'y XML, duże tablice zagnieżdżone) | generator + `ShouldBufferPayloads` (`docs/use-cases/streaming-xml-import.md`) |
| Źródło małe (mieści się w pamięci bez problemu) | zwykła `array` z `getChunks()` — nie ma powodu komplikować |

Więcej technicznych szczegółów o tym co dokładnie robi buforowanie i czego
nie robi (np. że liczba jobów w batchu i tak jest równa liczbie chunków) —
patrz `docs/BUFFERED_PAYLOADS.md`.
