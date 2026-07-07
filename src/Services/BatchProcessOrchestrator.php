<?php

namespace Kamz8\BatchOrchestrator\Services;

/**
 * Concrete, container-bindable orchestrator. Inject this class (not
 * {@see BaseOrchestrator}) into generators/services that dispatch chunked work.
 */
class BatchProcessOrchestrator extends BaseOrchestrator {}
