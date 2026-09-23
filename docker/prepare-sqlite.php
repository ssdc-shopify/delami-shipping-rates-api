<?php

declare(strict_types=1);

if (strtolower(trim((string) getenv('DB_CONNECTION'))) !== 'sqlite') {
    exit(0);
}

$database = trim((string) getenv('DB_DATABASE'));
$database = $database === '' ? 'delami.sqlite' : basename($database);
$path     = '/var/www/html/writable/database/' . $database;

if (! is_file($path)) {
    fwrite(STDERR, "SQLite database was not created at {$path}.\n");
    exit(1);
}

try {
    $connection = new SQLite3($path, SQLITE3_OPEN_READWRITE);
    $connection->enableExceptions(true);
    $mode       = strtolower((string) $connection->querySingle('PRAGMA journal_mode = WAL'));
    $connection->close();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not enable SQLite WAL: ' . $exception->getMessage() . "\n");
    exit(1);
}

if ($mode !== 'wal') {
    fwrite(STDERR, "Could not enable SQLite WAL (got {$mode}).\n");
    exit(1);
}

fwrite(STDOUT, "SQLite WAL is enabled for {$database}.\n");
