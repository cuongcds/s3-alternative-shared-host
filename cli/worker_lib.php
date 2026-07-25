<?php
/**
 * Shared event-processing logic (usecase 3 — hybrid architecture) for all 3
 * deploy options:
 * - `worker.php` — long-running daemon, BLPOP loop (docker-compose.yml)
 * - `worker_cron.php` — drain-and-exit, invoked periodically by a real cron
 *   daemon (docker-compose.apache.yml)
 * - `application/controllers/Cronjobs.php` — drain-and-exit over HTTP, for
 *   shared hosting where cron can only `wget` a URL (docker-compose
 *   shared-hosting.yml / a real cPanel-style host) — no Redis there, so it
 *   polls the `events` table directly instead of reading a queue id
 *
 * Does thumbnailing / async virus scan / webhook notification, and updates
 * the `events` row. Usable both standalone (no CodeIgniter bootstrap, from
 * the two CLI entrypoints) and included from within an already-booted CI3
 * request (Cronjobs.php) — every require below is idempotent either way.
 *
 * Reuses the CI library classes directly since Filesystem_driver,
 * Virus_scanner, Image_processor and Redis_queue all support being
 * constructed without a CI instance (see their constructors).
 */

if (!defined('BASEPATH')) {
    define('BASEPATH', TRUE);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../application/libraries/Filesystem_driver.php';
require_once __DIR__ . '/../application/libraries/Virus_scanner.php';
require_once __DIR__ . '/../application/libraries/Image_processor.php';
require_once __DIR__ . '/../application/libraries/Redis_queue.php';

const MAX_ATTEMPTS = 5;

/**
 * STDOUT/STDERR only exist under the CLI SAPI — this file also runs inside
 * a normal web request from Cronjobs.php (deploy option 3), where writing
 * to those undefined constants would be a fatal error. Falls back to
 * error_log() (the web server's error log) there.
 */
function worker_log($message, $isError = FALSE)
{
    if (defined('STDOUT') && defined('STDERR')) {
        fwrite($isError ? STDERR : STDOUT, $message);
    } else {
        error_log(rtrim($message, "\n"));
    }
}

function db_connect()
{
    $mysqli = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USERNAME') ?: '',
        getenv('DB_PASSWORD') ?: '',
        getenv('DB_DATABASE') ?: '',
        (int) (getenv('DB_PORT') ?: 3306)
    );
    $mysqli->set_charset('utf8mb4');
    return $mysqli;
}

function fetch_event(mysqli $db, $id)
{
    $stmt = $db->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function fetch_bucket(mysqli $db, $id)
{
    $stmt = $db->prepare('SELECT * FROM buckets WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function fetch_current_object(mysqli $db, $bucketId, $key)
{
    $stmt = $db->prepare('SELECT * FROM objects WHERE bucket_id = ? AND object_key = ? AND version_id IS NULL AND is_deleted = 0 ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('is', $bucketId, $key);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function mark_event(mysqli $db, $id, $status, $error = NULL)
{
    $stmt = $db->prepare('UPDATE events SET status = ?, last_error = ?, updated_at = ? WHERE id = ?');
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('sssi', $status, $error, $now, $id);
    $stmt->execute();
}

function increment_attempts(mysqli $db, $id)
{
    $db->query('UPDATE events SET attempts = attempts + 1 WHERE id = ' . (int) $id);
}

function delete_object_row(mysqli $db, $bucketId, $key)
{
    $stmt = $db->prepare('DELETE FROM objects WHERE bucket_id = ? AND object_key = ? AND version_id IS NULL');
    $stmt->bind_param('is', $bucketId, $key);
    $stmt->execute();
}

function upsert_object_row(mysqli $db, $bucketId, $key, $size, $etag, $contentType, $path)
{
    delete_object_row($db, $bucketId, $key);
    $stmt = $db->prepare('INSERT INTO objects (bucket_id, object_key, version_id, size, etag, content_type, storage_path, is_deleted, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, 0, ?)');
    $now = date('Y-m-d H:i:s');
    $stmt->bind_param('isissss', $bucketId, $key, $size, $etag, $contentType, $path, $now);
    $stmt->execute();
}

function post_webhook($url, array $payload, $secret)
{
    $body = json_encode($payload);
    $signature = hash_hmac('sha256', $body, $secret);
    $context = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Os3-Signature: {$signature}\r\n",
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => TRUE,
    )));
    $result = @file_get_contents($url, FALSE, $context);
    return $result !== FALSE;
}

/**
 * S3-compatible event notification shape (matches AWS S3 Event Notifications /
 * MinIO bucket notifications: { Records: [{ eventName, s3: { bucket, object } }] }),
 * so any consumer built against the real S3 event format — not just this
 * project's own client — can subscribe to notification_url without change.
 */
function build_s3_event_payload($eventName, $bucketName, $key, array $extra = array())
{
    $record = array(
        'eventVersion' => '2.1',
        'eventSource' => 'openS3',
        'eventName' => $eventName,
        'eventTime' => gmdate('Y-m-d\TH:i:s.000\Z'),
        's3' => array(
            'bucket' => array('name' => $bucketName),
            'object' => array('key' => $key),
        ),
    );
    if (!empty($extra)) {
        $record['openS3'] = $extra;
    }
    return array('Records' => array($record));
}

function process_event(mysqli $db, Filesystem_driver $fs, Virus_scanner $scanner, Image_processor $imgProc, $secret, $eventId)
{
    $event = fetch_event($db, $eventId);
    if (!$event) {
        return;
    }

    $bucket = fetch_bucket($db, $event['bucket_id']);
    if (!$bucket) {
        mark_event($db, $eventId, 'failed', 'bucket not found');
        return;
    }

    $key = $event['object_key'];
    $payload = json_decode($event['payload'], TRUE) ?: array();

    if ($event['event_type'] === 'object.created') {
        $object = fetch_current_object($db, $bucket['id'], $key);

        if ($object && $scanner->isEnabled() && substr($key, -10) !== '.thumb.jpg') {
            $scan = $scanner->scanFile($object['storage_path']);
            if (!$scan['clean']) {
                $fs->deleteObjectFile($object['storage_path']);
                delete_object_row($db, $bucket['id'], $key);
                worker_log("[worker] quarantined infected object {$bucket['name']}/{$key}: {$scan['signature']}\n");
                if ($bucket['notification_url']) {
                    post_webhook($bucket['notification_url'], array(
                        'event' => 'object.quarantined',
                        'bucket' => $bucket['name'],
                        'key' => $key,
                        'signature' => $scan['signature'],
                    ), $secret);
                }
                mark_event($db, $eventId, 'done');
                return;
            }
        }

        if ($object && $imgProc->isImage($object['content_type']) && substr($key, -10) !== '.thumb.jpg') {
            $thumbBytes = $imgProc->thumbnail($object['storage_path']);
            if ($thumbBytes !== NULL) {
                $tmp = fopen('php://temp', 'r+b');
                fwrite($tmp, $thumbBytes);
                rewind($tmp);
                $result = $fs->putObjectFromStream($bucket['name'], $key . '.thumb.jpg', $tmp);
                fclose($tmp);
                upsert_object_row($db, $bucket['id'], $key . '.thumb.jpg', $result['size'], $result['etag'], 'image/jpeg', $result['path']);
                worker_log("[worker] generated thumbnail for {$bucket['name']}/{$key}\n");
            }
        }

        if ($bucket['notification_url']) {
            post_webhook(
                $bucket['notification_url'],
                build_s3_event_payload('ObjectCreated:Put', $bucket['name'], $key, $payload),
                $secret
            );
        }
    } elseif ($event['event_type'] === 'object.removed') {
        if ($bucket['notification_url']) {
            post_webhook($bucket['notification_url'], array(
                'event' => 'object.removed',
                'bucket' => $bucket['name'],
                'key' => $key,
            ), $secret);
        }
    }

    mark_event($db, $eventId, 'done');
}

function wait_for_db($maxAttempts = 30)
{
    for ($i = 0; $i < $maxAttempts; $i++) {
        try {
            return db_connect();
        } catch (Throwable $e) {
            worker_log("[worker] waiting for mysql... ({$e->getMessage()})\n");
            sleep(2);
        }
    }
    worker_log("[worker] could not reach mysql after {$maxAttempts} attempts, exiting\n", TRUE);
    exit(1);
}

/**
 * Common bootstrap for both entrypoints: connect (retrying until MySQL is
 * reachable) and construct the shared dependencies.
 *
 * @return array{db:mysqli,fs:Filesystem_driver,scanner:Virus_scanner,imgProc:Image_processor,queue:Redis_queue,secret:string}
 */
function worker_bootstrap()
{
    return array(
        'db' => wait_for_db(),
        'fs' => new Filesystem_driver(getenv('STORAGE_ROOT') ?: '/data'),
        'scanner' => new Virus_scanner(),
        'imgProc' => new Image_processor(),
        'queue' => new Redis_queue(),
        'secret' => getenv('SECRET_ACCESS_KEY') ?: '',
    );
}

/**
 * Process one event id, with the same retry/requeue/give-up bookkeeping
 * every entrypoint shares. `$ctx['queue']` may be NULL (deploy option 3 —
 * no Redis): marking the row back to 'pending' is enough on its own for a
 * DB-polling consumer to pick it up again, the Redis push is only an
 * optional "wake up the daemon immediately" fast path for options 1/2.
 */
function handle_event_with_retry(array $ctx, $eventId)
{
    try {
        process_event($ctx['db'], $ctx['fs'], $ctx['scanner'], $ctx['imgProc'], $ctx['secret'], $eventId);
    } catch (Throwable $e) {
        increment_attempts($ctx['db'], $eventId);
        $event = fetch_event($ctx['db'], $eventId);
        $attempts = $event ? (int) $event['attempts'] : MAX_ATTEMPTS;
        if ($attempts >= MAX_ATTEMPTS) {
            mark_event($ctx['db'], $eventId, 'failed', $e->getMessage());
            worker_log("[worker] event {$eventId} failed permanently: {$e->getMessage()}\n", TRUE);
        } else {
            mark_event($ctx['db'], $eventId, 'pending', $e->getMessage());
            if ($ctx['queue']) {
                $ctx['queue']->push('events_queue', $eventId);
            }
            worker_log("[worker] event {$eventId} failed (attempt {$attempts}), re-queued: {$e->getMessage()}\n", TRUE);
        }
    }
}
