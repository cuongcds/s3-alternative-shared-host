<?php
/**
 * Seeds or resets one admin panel user (docs/plans_v2.md section 3).
 * Run manually after migrations, e.g.:
 *   docker compose exec app php cli/create_admin.php admin@example.com 'a-strong-password'
 * Idempotent by email (ON DUPLICATE KEY UPDATE) — safe to re-run to reset a
 * forgotten password.
 */

$email = isset($argv[1]) ? trim($argv[1]) : NULL;
$password = isset($argv[2]) ? $argv[2] : NULL;

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "[create_admin] Usage: php cli/create_admin.php <email> <password>\n");
    exit(1);
}
if (!$password || strlen($password) < 8) {
    fwrite(STDERR, "[create_admin] Password must be at least 8 characters.\n");
    exit(1);
}

$mysqli = @mysqli_connect(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USERNAME') ?: '',
    getenv('DB_PASSWORD') ?: '',
    getenv('DB_DATABASE') ?: '',
    (int) (getenv('DB_PORT') ?: 3306)
);

if (!$mysqli) {
    fwrite(STDERR, "[create_admin] Could not connect to MySQL: " . mysqli_connect_error() . "\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $mysqli->prepare(
    'INSERT INTO admins (email, password_hash, is_active, failed_login_attempts, locked_until)
     VALUES (?, ?, 1, 0, NULL)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1,
       failed_login_attempts = 0, locked_until = NULL'
);
$stmt->bind_param('ss', $email, $hash);
$stmt->execute();
$stmt->close();
$mysqli->close();

fwrite(STDOUT, "[create_admin] admin '{$email}' created/updated.\n");
