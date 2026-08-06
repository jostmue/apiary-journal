<?php
/**
 * Schema migrations.
 *
 * The app is installed by copying files, so an update has to bring the
 * database along by itself: on every request the schema version recorded in
 * `settings` is compared with SCHEMA_VERSION below, and any missing step is
 * applied once. Steps are written to be safe to re-run, and a database lock
 * keeps two concurrent requests from applying the same step twice.
 *
 * Adding a step: append to migrations() with the next number and raise
 * SCHEMA_VERSION. Never edit a step that has been released - installations
 * that already ran it would not pick up the change.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 2;

/**
 * Version 1 is the schema db/schema.sql creates. An installation from before
 * migrations existed has the tables but no version marker, and is recognised
 * by its users table.
 */
function schema_version(PDO $pdo): int
{
    if (!table_exists($pdo, 'settings')) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $stmt->execute(['db_version']);
    $v = $stmt->fetchColumn();
    if ($v !== false && $v !== null && $v !== '') {
        return (int)$v;
    }
    return table_exists($pdo, 'users') ? 1 : 0;
}

function schema_version_set(PDO $pdo, int $version): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
    );
    $stmt->execute(['db_version', (string)$version]);
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * One entry per version, keyed by the version it produces.
 *
 * MySQL cannot roll DDL back, and "ADD COLUMN IF NOT EXISTS" is MariaDB only,
 * so each step checks the current state itself rather than relying on either.
 */
function migrations(): array
{
    return [
        2 => function (PDO $pdo): void {
            // Password reset tokens. Only the hash is stored, so a leaked
            // database row cannot be turned back into a usable link.
            if (!table_exists($pdo, 'password_resets')) {
                $pdo->exec(
                    "CREATE TABLE password_resets (
                       id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                       user_id     INT UNSIGNED NOT NULL,
                       token_hash  CHAR(64)     NOT NULL,
                       expires_at  DATETIME     NOT NULL,
                       used_at     DATETIME     NULL,
                       created_ip  VARCHAR(45)  NULL,
                       created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                       PRIMARY KEY (id),
                       UNIQUE KEY uq_reset_token (token_hash),
                       KEY ix_reset_user (user_id),
                       CONSTRAINT fk_reset_user FOREIGN KEY (user_id)
                         REFERENCES users(id) ON DELETE CASCADE
                     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            }
            // A reset link is delivered by e-mail, so the address has to be
            // unambiguous. Existing duplicates are left alone; the index is
            // only added when the data allows it.
            if (!index_exists($pdo, 'users', 'uq_users_email') && !has_duplicate_emails($pdo)) {
                $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)');
            }
        },
    ];
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function has_duplicate_emails(PDO $pdo): bool
{
    $sql = "SELECT COUNT(*) FROM (
              SELECT email FROM users
              WHERE email IS NOT NULL AND email <> ''
              GROUP BY email HAVING COUNT(*) > 1
            ) d";
    return (int)$pdo->query($sql)->fetchColumn() > 0;
}

/**
 * Bring the database up to SCHEMA_VERSION.
 *
 * Returns the version now in place. A fresh database (version 0) is left to
 * install.php; upgrading is only ever done on an installation that exists.
 */
function migrate_if_needed(): int
{
    $pdo  = db();
    $from = schema_version($pdo);
    if ($from === 0 || $from >= SCHEMA_VERSION) {
        return $from;
    }

    // Two requests arriving together must not run the same step twice.
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['apiary_journal_migrate']);
    if ((int)$lock->fetchColumn() !== 1) {
        error_log('[apiary-journal] migration lock busy, skipping this request');
        return $from;
    }

    try {
        $from = schema_version($pdo);   // may have moved while we waited
        foreach (migrations() as $version => $step) {
            if ($version <= $from) {
                continue;
            }
            $step($pdo);
            schema_version_set($pdo, $version);
            $from = $version;
            error_log('[apiary-journal] migrated database to version ' . $version);
        }
    } catch (Throwable $e) {
        error_log('[apiary-journal] migration to version > ' . $from . ' failed: ' . $e->getMessage());
        throw $e;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['apiary_journal_migrate']);
    }

    return $from;
}
