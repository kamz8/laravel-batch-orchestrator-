<?php

return [
    'progress_ttl' => env('BATCH_ORCHESTRATOR_PROGRESS_TTL', 14400),
    'payload_ttl' => env('BATCH_ORCHESTRATOR_PAYLOAD_TTL', 14400),
    'payload_chunk_flush_size' => env('BATCH_ORCHESTRATOR_PAYLOAD_CHUNK_FLUSH_SIZE', 100),
    'payload_key_prefix' => env('BATCH_ORCHESTRATOR_PAYLOAD_KEY_PREFIX', 'batch-orchestrator:payload'),
];
