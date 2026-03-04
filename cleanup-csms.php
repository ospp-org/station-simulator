#!/usr/bin/env php
<?php
/**
 * Cleans stale CSMS state (sessions, reservations, firmware, bays) via direct DB connection.
 * Used between scenario runs to prevent state leakage.
 */

$host = getenv('CSMS_DB_HOST') ?: 'csms-postgres';
$port = getenv('CSMS_DB_PORT') ?: '5432';
$db = getenv('CSMS_DB_NAME') ?: 'csms';
$user = getenv('CSMS_DB_USER') ?: 'csms';
$pass = getenv('CSMS_DB_PASS') ?: 'secret';

try {
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $queries = [
        "UPDATE sessions SET status='failed', stopped_at=NOW() WHERE status IN ('pending','active','stopping','authorized')",
        "UPDATE reservations SET status='cancelled', cancelled_at=NOW() WHERE status IN ('pending','confirmed')",
        "DELETE FROM firmware_updates WHERE status NOT IN ('installed','activated','failed')",
        "DELETE FROM diagnostics_uploads WHERE status NOT IN ('uploaded','failed')",
        "UPDATE diagnostics_uploads SET status='failed' WHERE status != 'failed'",
        "UPDATE bays SET status='available'",
    ];

    foreach ($queries as $sql) {
        $affected = $pdo->exec($sql);
        if ($affected > 0) {
            echo "  {$affected} row(s): " . substr($sql, 0, 60) . "...\n";
        }
    }

    echo "  [cleanup] Done.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "  [cleanup] DB connection failed: " . $e->getMessage() . "\n");
    exit(0); // Don't fail the suite
}
