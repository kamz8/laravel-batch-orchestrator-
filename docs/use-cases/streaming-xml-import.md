# Use case: streaming import of a large XML file

**Problem:** a product feed in XML, several hundred MB, several hundred
thousand items. You can't load the whole thing into memory
(`SimpleXMLElement::load()` on the entire file) or build one giant PHP array
before dispatching the batch.

**Solution:** an `XMLReader` reading node by node inside a generator in
`getChunks()`, grouping N products per chunk, and `ShouldBufferPayloads` so
each chunk job gets only a lightweight Redis reference instead of the full
data package.

The package **requires no XML-streaming implementation of its own** — this
pattern relies entirely on `getChunks(): iterable` being a generator and on
`ShouldBufferPayloads`. Nothing else needs to be added to the orchestrator.

## 1. Task

```php
use Kamz8\BatchOrchestrator\Concerns\BuffersPayloads;
use Kamz8\BatchOrchestrator\Contracts\ChunkableTask;
use Kamz8\BatchOrchestrator\Contracts\ShouldBufferPayloads;

class ImportProductFeedTask implements ChunkableTask, ShouldBufferPayloads
{
    use BuffersPayloads; // default TTL/prefix from config/batch-orchestrator.php

    private const int PRODUCTS_PER_CHUNK = 200;

    public function __construct(
        private readonly int $feedId,
        private readonly string $xmlPath, // path only — a string, safe to serialize
    ) {}

    /**
     * Generator: reads the file node by node, never holds the whole XML in memory.
     * Yields raw data every PRODUCTS_PER_CHUNK products.
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
                yield $buffer;   // <-- the orchestrator receives this as one "chunkData"
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            yield $buffer; // final, partial group
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

Important: `$xmlPath` and `$feedId` are the object's only properties — both
scalar. The `XMLReader`/generator itself **is not** stored as a property, so
copying the task for the callback
(`BaseOrchestrator::taskWithoutBufferedPayloads()`) doesn't need to strip
anything from it beyond the chunk collection itself.

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

        // The same code works whether ShouldBufferPayloads is enabled or not.
        $products = $this->resolvePayload($this->chunkData);

        foreach ($products as $product) {
            Product::updateOrCreate(['sku' => $product['sku']], $product);
        }
    }
}
```

## 3. Merge/finalization job (rule #4 from `AI_GUIDE.md` — NOT `Batchable`)

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

## What exactly happens in memory

- The XML file: a stream, constant memory regardless of file size (`XMLReader`).
- Every generator yield → immediately `Redis::setex()`, the original group of
  200 products can be freed by the GC right after.
- The chunk job only holds a `BufferedPayloadReference` (a Redis key + index)
  — a few dozen bytes, not 200 product records.
- The number of chunk jobs in the batch = `ceil(product_count / 200)` — this
  is unavoidable, `Bus::batch` must know the total job count (see
  `docs/BUFFERED_PAYLOADS.md`, "What this does NOT do").

## TTL configuration for large imports

If importing 500k products realistically takes longer than the default 4h
TTL, raise `BATCH_ORCHESTRATOR_PAYLOAD_TTL` in `.env` or override
`payloadTtl()` directly on `ImportProductFeedTask` (instead of relying on
`BuffersPayloads`'s default implementation), so you don't share a TTL with
other tasks in the application that use the default config.
