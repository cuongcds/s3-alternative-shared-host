<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| Single fixed-account credentials + storage/queue settings, sourced from ENV
| (see .env.example). Never hardcode real secrets here.
*/

$config['s3_access_key_id']     = getenv('ACCESS_KEY_ID') ?: '';
$config['s3_secret_access_key'] = getenv('SECRET_ACCESS_KEY') ?: '';

$config['s3_storage_root'] = rtrim(getenv('STORAGE_ROOT') ?: '/data', '/');

// Public-facing base URL used when building presigned URLs (must match the
// host clients will actually connect to, so the signed "host" matches).
$config['s3_public_base_url'] = rtrim(getenv('PUBLIC_BASE_URL') ?: 'http://localhost:8080', '/');

$config['s3_max_upload_size'] = (int) (getenv('MAX_UPLOAD_SIZE') ?: 5368709120); // 5GB

// Presigned URL default/maximum lifetime (seconds)
$config['s3_presign_min_ttl'] = 60;        // 1 minute
$config['s3_presign_max_ttl'] = 900;       // 15 minutes
$config['s3_presign_default_ttl'] = 300;   // 5 minutes

// Clock skew tolerance for header-based signature verification (seconds)
$config['s3_clock_skew_tolerance'] = 300;

// Redis is optional — deploy option 3 (shared hosting, no Docker) has no
// Redis available at all. Leaving REDIS_HOST empty disables it entirely:
// events still get written to the `events` table (the durable source of
// truth for all 3 deploy options), just without the Redis "wake up the
// daemon immediately" fast path — see Event_model::push() and Cronjobs.php.
$config['redis_enabled'] = (bool) getenv('REDIS_HOST');
$config['redis_host'] = getenv('REDIS_HOST') ?: 'localhost';
$config['redis_port'] = (int) (getenv('REDIS_PORT') ?: 6379);
$config['redis_password'] = getenv('REDIS_PASSWORD') ?: NULL;

// Deploy option 3 (shared hosting): cPanel-style cron can usually only hit a
// URL (wget/curl), not run an arbitrary PHP CLI script — Cronjobs.php exposes
// the event-queue drain over HTTP instead. Required to use that endpoint.
$config['s3_cron_secret'] = getenv('CRON_SECRET') ?: '';
$config['s3_cron_batch_limit'] = (int) (getenv('CRON_BATCH_LIMIT') ?: 20);

$config['clamav_host'] = getenv('CLAMAV_HOST') ?: 'clamav';
$config['clamav_port'] = (int) (getenv('CLAMAV_PORT') ?: 3310);
$config['enable_virus_scan'] = filter_var(getenv('ENABLE_VIRUS_SCAN') ?: 'false', FILTER_VALIDATE_BOOLEAN);
