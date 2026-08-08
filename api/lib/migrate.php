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

const SCHEMA_VERSION = 4;

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

        3 => function (PDO $pdo): void {
            migrate_add_groups($pdo);
        },

        4 => function (PDO $pdo): void {
            migrate_add_registration($pdo);
        },
    ];
}

/**
 * Version 3: ownership and groups.
 *
 * Apiaries and colonies gain an owner and an optional group; everything below
 * a colony inherits its visibility from there. Existing data is moved into one
 * group holding every existing account, so an installation behaves exactly as
 * it did before the upgrade and can be tightened afterwards.
 */
function migrate_add_groups(PDO $pdo): void
{
    if (!table_exists($pdo, 'user_groups')) {
        $pdo->exec(
            "CREATE TABLE user_groups (
               id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
               name        VARCHAR(120) NOT NULL,
               description VARCHAR(255) NULL,
               created_by  INT UNSIGNED NULL,
               created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               CONSTRAINT fk_group_creator FOREIGN KEY (created_by)
                 REFERENCES users(id) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'group_members')) {
        $pdo->exec(
            "CREATE TABLE group_members (
               group_id  INT UNSIGNED NOT NULL,
               user_id   INT UNSIGNED NOT NULL,
               role      ENUM('owner','member','viewer') NOT NULL DEFAULT 'member',
               joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (group_id, user_id),
               KEY ix_gm_user (user_id),
               CONSTRAINT fk_gm_group FOREIGN KEY (group_id)
                 REFERENCES user_groups(id) ON DELETE CASCADE,
               CONSTRAINT fk_gm_user FOREIGN KEY (user_id)
                 REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'group_invites')) {
        $pdo->exec(
            "CREATE TABLE group_invites (
               id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
               group_id    INT UNSIGNED NOT NULL,
               email       VARCHAR(160) NOT NULL,
               role        ENUM('owner','member','viewer') NOT NULL DEFAULT 'member',
               token_hash  CHAR(64)     NOT NULL,
               invited_by  INT UNSIGNED NULL,
               expires_at  DATETIME     NOT NULL,
               accepted_at DATETIME     NULL,
               created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_invite_token (token_hash),
               KEY ix_invite_group (group_id),
               CONSTRAINT fk_invite_group FOREIGN KEY (group_id)
                 REFERENCES user_groups(id) ON DELETE CASCADE,
               CONSTRAINT fk_invite_user FOREIGN KEY (invited_by)
                 REFERENCES users(id) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    foreach (['apiaries', 'colonies'] as $table) {
        if (!column_exists($pdo, $table, 'owner_id')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN owner_id INT UNSIGNED NULL AFTER id");
            $pdo->exec("ALTER TABLE {$table} ADD KEY ix_{$table}_owner (owner_id)");
            $pdo->exec(
                "ALTER TABLE {$table} ADD CONSTRAINT fk_{$table}_owner
                 FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL"
            );
        }
        if (!column_exists($pdo, $table, 'group_id')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN group_id INT UNSIGNED NULL AFTER owner_id");
            $pdo->exec("ALTER TABLE {$table} ADD KEY ix_{$table}_group (group_id)");
            $pdo->exec(
                "ALTER TABLE {$table} ADD CONSTRAINT fk_{$table}_group
                 FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE SET NULL"
            );
        }
        // Whoever entered a record keeps it; anything orphaned goes to the
        // oldest administrator so that nothing ends up without an owner.
        $pdo->exec("UPDATE {$table} SET owner_id = created_by WHERE owner_id IS NULL");
        $pdo->exec(
            "UPDATE {$table} SET owner_id =
               (SELECT id FROM (SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1) x)
             WHERE owner_id IS NULL"
        );
    }

    // Nothing to share out if the installation is still empty.
    $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $rows  = (int)$pdo->query('SELECT COUNT(*) FROM apiaries')->fetchColumn();
    if ($users === 0 || $rows === 0) {
        return;
    }

    // One group with everyone in it, so the upgrade changes nothing that is
    // visible to the people using it. Splitting things off is a decision for
    // afterwards, not something an update should make on its own.
    $existing = $pdo->query('SELECT id FROM user_groups LIMIT 1')->fetchColumn();
    if ($existing !== false) {
        return;
    }
    $admin = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare(
        'INSERT INTO user_groups (name, description, created_by) VALUES (?, ?, ?)'
    )->execute([
        'Alle',
        'Automatically created during the upgrade so that existing records stay visible to everyone who could already see them.',
        $admin !== false ? $admin : null,
    ]);
    $groupId = (int)$pdo->lastInsertId();

    $pdo->exec(
        "INSERT INTO group_members (group_id, user_id, role)
         SELECT {$groupId}, id, CASE WHEN role = 'admin' THEN 'owner' ELSE 'member' END FROM users"
    );
    $pdo->exec("UPDATE apiaries SET group_id = {$groupId} WHERE group_id IS NULL");
    $pdo->exec("UPDATE colonies SET group_id = {$groupId} WHERE group_id IS NULL");
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

/**
 * Version 4: self-registration.
 *
 * Accounts gain the two dates the open mode needs: when the address was
 * confirmed and when the terms were accepted. Everyone who exists at this
 * point was created by an administrator, so their address counts as confirmed
 * - otherwise an upgrade would lock out every user at once.
 */
function migrate_add_registration(PDO $pdo): void
{
    if (!column_exists($pdo, 'users', 'email_verified_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER email');
        $pdo->exec('UPDATE users SET email_verified_at = created_at');
    }
    if (!column_exists($pdo, 'users', 'terms_accepted_at')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL AFTER email_verified_at');
    }
    if (!table_exists($pdo, 'email_verifications')) {
        $pdo->exec(
            "CREATE TABLE email_verifications (
               id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
               user_id     INT UNSIGNED NOT NULL,
               token_hash  CHAR(64)     NOT NULL,
               expires_at  DATETIME     NOT NULL,
               used_at     DATETIME     NULL,
               created_ip  VARCHAR(45)  NULL,
               created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_verify_token (token_hash),
               KEY ix_verify_user (user_id),
               CONSTRAINT fk_verify_user FOREIGN KEY (user_id)
                 REFERENCES users(id) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}
