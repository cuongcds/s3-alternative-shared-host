<?php
/**
 * Creates the app's database (if missing) on the shared MySQL server and
 * applies db/schema.sql (idempotent — every statement is CREATE ... IF NOT
 * EXISTS). Run on every container start (see docker/app/entrypoint.sh) since
 * MySQL now lives in a separate, generic stack (../services/mysql) that
 * knows nothing about this project's schema.
 */

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_DATABASE') ?: 'open_s3';

$mysqli = @mysqli_connect($host, $user, $pass, '', $port);
if (!$mysqli) {
    fwrite(STDERR, "[migrate] Could not connect to MySQL: " . mysqli_connect_error() . "\n");
    exit(1);
}

if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci")) {
    fwrite(STDERR, "[migrate] Could not create database: {$mysqli->error}\n");
    exit(1);
}
$mysqli->select_db($database);

$sql = file_get_contents(__DIR__ . '/../db/schema.sql');
$mysqli->multi_query($sql);
do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
} while ($mysqli->more_results() && $mysqli->next_result());

if ($mysqli->errno) {
    fwrite(STDERR, "[migrate] schema error: {$mysqli->error}\n");
    exit(1);
}

fwrite(STDOUT, "[migrate] schema ensured on database '{$database}'.\n");
$mysqli->close();

// Everything past the baseline (see docs/plans_v2.md section 2) is a real
// CI migration under application/migrations/ — run those to latest via the
// CLI-only Cli_migrate controller (bootstraps the full CI app, so it must
// go through index.php rather than loading the Migration library directly).
$exitCode = 0;
passthru('php ' . escapeshellarg(__DIR__ . '/../index.php') . ' cli_migrate run', $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "[migrate] CodeIgniter migrations failed (exit {$exitCode})\n");
    exit(1);
}
