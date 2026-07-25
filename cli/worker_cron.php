<?php
/**
 * Cron entrypoint for the Apache deploy option (docker-compose.apache.yml):
 * drains whatever's currently queued in Redis and exits, instead of running
 * forever like `worker.php`'s daemon loop — see docker/worker-cron/crontab
 * for the schedule (every minute by default). Event-processing latency is
 * therefore up to ~1 minute in this deploy option, vs near-instant with the
 * daemon — an explicit tradeoff for not needing a long-running process.
 *
 * Shares all event-processing logic with worker.php via worker_lib.php.
 */

require __DIR__ . '/worker_lib.php';

$ctx = worker_bootstrap();
$processed = 0;

// Short timeout: BLPOP returns immediately while events are queued, and
// bails out quickly (rather than hanging) once it's empty.
while (($eventId = $ctx['queue']->blockingPop('events_queue', 1)) !== NULL) {
    handle_event_with_retry($ctx, $eventId);
    $processed++;
}

fwrite(STDOUT, "[worker-cron] processed {$processed} event(s)\n");
