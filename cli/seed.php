<?php
/**
 * Seeds the single fixed access key from ENV into access_keys.
 * Run once on container startup (see docker/app/entrypoint.sh); idempotent.
 */

$accessKeyId = getenv('ACCESS_KEY_ID');
$secretAccessKey = getenv('SECRET_ACCESS_KEY');

if (!$accessKeyId || !$secretAccessKey) {
    fwrite(STDERR, "[seed] ACCESS_KEY_ID / SECRET_ACCESS_KEY not set, skipping seed.\n");
    exit(0);
}

$mysqli = @mysqli_connect(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USERNAME') ?: '',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_DATABASE') ?: '',
    (int) (getenv('DB_PORT') ?: 3306)
);

if (!$mysqli) {
    fwrite(STDERR, "[seed] Could not connect to MySQL: " . mysqli_connect_error() . "\n");
    exit(1);
}

$hash = password_hash($secretAccessKey, PASSWORD_BCRYPT);

$stmt = $mysqli->prepare(
    'INSERT INTO access_keys (access_key_id, secret_access_key_hash, is_active)
     VALUES (?, ?, 1)
     ON DUPLICATE KEY UPDATE secret_access_key_hash = VALUES(secret_access_key_hash), is_active = 1'
);
$stmt->bind_param('ss', $accessKeyId, $hash);
$stmt->execute();
$stmt->close();
$mysqli->close();

fwrite(STDOUT, "[seed] access key '{$accessKeyId}' seeded.\n");
