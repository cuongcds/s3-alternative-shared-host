<?php
/**
 * Long-running event-queue worker — the default deploy option
 * (docker-compose.yml), runs as its own container/process forever, BLPOP'ing
 * the next event id off Redis. For the Apache deploy option
 * (docker-compose.apache.yml), see `worker_cron.php` instead — a
 * drain-and-exit entrypoint invoked periodically by cron.
 *
 * All the actual event-processing logic lives in `worker_lib.php`, shared by
 * both entrypoints.
 */

require __DIR__ . '/worker_lib.php';

$ctx = worker_bootstrap();

fwrite(STDOUT, "[worker] started, watching events_queue\n");

while (TRUE) {
    try {
        $eventId = $ctx['queue']->blockingPop('events_queue', 5);
        if ($eventId === NULL) {
            continue;
        }
        handle_event_with_retry($ctx, $eventId);
    } catch (Throwable $e) {
        fwrite(STDERR, "[worker] loop error: {$e->getMessage()}\n");
        sleep(2);
    }
}
