<?php
/**
 * What a user may do with their own account: take their data with them, and
 * leave.
 *
 * Both exist in either operating mode. On a private installation they are
 * convenience; on an open one they are what the GDPR calls portability and
 * erasure, and they have to work without an administrator.
 */

declare(strict_types=1);

/** Tables holding journal records, keyed by the column pointing at a colony. */
const ACCOUNT_RECORD_TABLES = [
    'inspections', 'feedings', 'treatments', 'harvests', 'events',
];

/**
 * Everything belonging to one user, as a nested structure.
 *
 * Records other people wrote on the user's own colonies are included, because
 * they are part of those colonies' history. Records the user wrote on someone
 * else's colony are included as well - they authored them - but without the
 * surrounding colony, which is not theirs to take.
 */
function export_account(int $userId): array
{
    $pdo = db();

    $apiaries = $pdo->prepare('SELECT * FROM apiaries WHERE owner_id = ? ORDER BY name');
    $apiaries->execute([$userId]);

    $colonies = $pdo->prepare('SELECT * FROM colonies WHERE owner_id = ? ORDER BY name');
    $colonies->execute([$userId]);
    $colonyRows = $colonies->fetchAll();
    $colonyIds  = array_column($colonyRows, 'id');

    $out = [
        'format'     => 'apiary-journal-export',
        'version'    => 1,
        'created_at' => date('c'),
        'account'    => account_public($userId),
        'groups'     => account_groups($userId),
        'apiaries'   => $apiaries->fetchAll(),
        'colonies'   => $colonyRows,
        'queens'     => [],
        'records'    => [],
        'records_by_me_elsewhere' => [],
        'tasks'      => [],
    ];

    if ($colonyIds) {
        $in = implode(',', array_fill(0, count($colonyIds), '?'));
        $q  = $pdo->prepare("SELECT * FROM queens WHERE colony_id IN ({$in})");
        $q->execute($colonyIds);
        $out['queens'] = $q->fetchAll();

        foreach (ACCOUNT_RECORD_TABLES as $t) {
            $stmt = $pdo->prepare("SELECT * FROM {$t} WHERE colony_id IN ({$in})");
            $stmt->execute($colonyIds);
            $out['records'][$t] = $stmt->fetchAll();
        }
    } else {
        foreach (ACCOUNT_RECORD_TABLES as $t) {
            $out['records'][$t] = [];
        }
    }

    foreach (ACCOUNT_RECORD_TABLES as $t) {
        $sql  = "SELECT r.* FROM {$t} r
                 LEFT JOIN colonies c ON c.id = r.colony_id
                 WHERE r.user_id = ? AND (c.owner_id IS NULL OR c.owner_id <> ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);
        $out['records_by_me_elsewhere'][$t] = $stmt->fetchAll();
    }

    $tasks = $pdo->prepare('SELECT * FROM tasks WHERE created_by = ? OR user_id = ?');
    $tasks->execute([$userId, $userId]);
    $out['tasks'] = $tasks->fetchAll();

    return $out;
}

function account_public(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, username, full_name, email, role, locale, is_active,
                email_verified_at, terms_accepted_at, last_login_at, created_at
         FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: [];
}

function account_groups(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT g.id, g.name, g.description, m.role, m.joined_at
         FROM group_members m JOIN user_groups g ON g.id = m.group_id
         WHERE m.user_id = ? ORDER BY g.name'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** POST account/export - the user's own data as a JSON download. */
function handle_account_export(): void
{
    $me = require_login();
    require_csrf();

    $data = export_account((int)$me['id']);
    $name = 'apiary-journal-' . preg_replace('/[^A-Za-z0-9._-]/', '', (string)$me['username'])
          . '-' . date('Y-m-d') . '.json';

    log_activity('account_export', 'users', (int)$me['id']);

    send_security_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Groups this user owns alone while other members remain.
 *
 * Deleting the account must not strand them, so ownership moves to whoever
 * has been a member longest. Returns [group_id => new_owner_user_id].
 */
function group_successors(int $userId): array
{
    $stmt = db()->prepare(
        "SELECT m.group_id,
                (SELECT o.user_id FROM group_members o
                  WHERE o.group_id = m.group_id AND o.user_id <> ?
                  ORDER BY o.joined_at ASC, o.user_id ASC LIMIT 1) AS successor
         FROM group_members m
         WHERE m.user_id = ? AND m.role = 'owner'
           AND (SELECT COUNT(*) FROM group_members x
                 WHERE x.group_id = m.group_id AND x.role = 'owner' AND x.user_id <> ?) = 0"
    );
    $stmt->execute([$userId, $userId, $userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if ($row['successor'] !== null) {
            $out[(int)$row['group_id']] = (int)$row['successor'];
        }
    }
    return $out;
}

/**
 * POST account/delete  {password, confirm}
 *
 * Takes effect at once. What the user owns goes; what they wrote on colonies
 * belonging to others stays but loses the link to them, so a group's records
 * do not develop holes - a missing treatment is something a beekeeper is
 * expected to be able to show.
 */
function handle_account_delete(): void
{
    $me = require_login();
    require_csrf();
    usleep(250000);

    $userId = (int)$me['id'];

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify((string)param('password', ''), $row['password_hash'])) {
        fail('current_password_wrong');
    }
    // A typed word, so the button alone cannot do it.
    if (strtoupper(trim((string)param('confirm', ''))) !== 'DELETE') {
        fail('confirmation_missing');
    }
    if (is_last_admin($userId)) {
        fail('last_admin');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Hand over groups this user owns alone, before the membership rows
        // disappear with the account.
        foreach (group_successors($userId) as $groupId => $successor) {
            $pdo->prepare("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?")
                ->execute([$groupId, $successor]);
        }

        // Detach what the user wrote elsewhere, then remove what is theirs.
        // Colonies and apiaries cascade into their own records.
        foreach (ACCOUNT_RECORD_TABLES as $t) {
            $pdo->prepare("UPDATE {$t} SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        }
        $pdo->prepare('UPDATE tasks SET user_id = NULL WHERE user_id = ?')->execute([$userId]);

        $pdo->prepare('DELETE FROM colonies WHERE owner_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM apiaries WHERE owner_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM tasks WHERE created_by = ?')->execute([$userId]);

        // Groups left without any member at all have no purpose any more.
        $pdo->prepare(
            'DELETE g FROM user_groups g
             LEFT JOIN group_members m ON m.group_id = g.id AND m.user_id <> ?
             WHERE m.user_id IS NULL
               AND EXISTS (SELECT 1 FROM group_members o WHERE o.group_id = g.id AND o.user_id = ?)'
        )->execute([$userId, $userId]);

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[apiary-journal] account deletion failed: ' . $e->getMessage());
        fail('server_error', 500);
    }

    log_activity('account_deleted', 'users', null, (string)$me['username']);

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    ok(['deleted' => true]);
}
