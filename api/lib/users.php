<?php
/**
 * User management. Listing is available to every signed in user (so records
 * can be attributed and tasks assigned); creating, editing and disabling
 * accounts is reserved for administrators.
 */

declare(strict_types=1);

function handle_users_list(): void
{
    $me = require_login();
    $rows = db()->query(
        'SELECT id, username, full_name, email, role, locale, is_active, last_login_at, created_at
         FROM users ORDER BY username'
    )->fetchAll();

    if ($me['role'] !== 'admin') {
        // Non-admins only need names for filters and assignments.
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'username' => $r['username'],
            'full_name' => $r['full_name'], 'role' => $r['role'],
            'is_active' => (int)$r['is_active'],
        ], $rows);
    }
    ok($rows);
}

function handle_users_save(): void
{
    require_role('admin');
    require_csrf();
    $rec = (array)param('record', []);
    $id  = isset($rec['id']) && $rec['id'] !== '' ? (int)$rec['id'] : 0;

    $username = trim((string)($rec['username'] ?? ''));
    if ($username === '' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        fail('invalid_username');
    }
    $role   = in_array($rec['role'] ?? '', ['admin', 'beekeeper', 'viewer'], true) ? $rec['role'] : 'beekeeper';
    $locale = in_array($rec['locale'] ?? '', ['de', 'en'], true) ? $rec['locale'] : 'de';
    $active = !empty($rec['is_active']) ? 1 : 0;
    $pass   = (string)($rec['password'] ?? '');

    if ($id === 0 && strlen($pass) < 8) {
        fail('password_too_short');
    }
    if ($pass !== '' && strlen($pass) < 8) {
        fail('password_too_short');
    }

    if ($id > 0) {
        $sql  = 'UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, locale = ?, is_active = ?';
        $args = [$username, $rec['full_name'] ?? null, $rec['email'] ?? null, $role, $locale, $active];
        if ($pass !== '') {
            $sql   .= ', password_hash = ?';
            $args[] = password_hash($pass, PASSWORD_DEFAULT);
        }
        $sql   .= ' WHERE id = ?';
        $args[] = $id;

        // Never lock the last administrator out of the system.
        if (($role !== 'admin' || !$active) && is_last_admin($id)) {
            fail('last_admin');
        }
        db()->prepare($sql)->execute($args);
        log_activity('user_update', 'users', $id, $username);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO users (username, full_name, email, password_hash, role, locale, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                $username, $rec['full_name'] ?? null, $rec['email'] ?? null,
                password_hash($pass, PASSWORD_DEFAULT), $role, $locale, $active,
            ]);
        } catch (PDOException $e) {
            fail('username_taken');
        }
        $id = (int)db()->lastInsertId();
        log_activity('user_create', 'users', $id, $username);
    }
    ok(['id' => $id]);
}

function is_last_admin(int $id): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
    $stmt->execute([$id]);
    return (int)$stmt->fetch()['n'] === 0;
}

function handle_users_delete(): void
{
    $me = require_role('admin');
    require_csrf();
    $id = param_int('id', 0);
    if (!$id) {
        fail('missing_id');
    }
    if ($id === (int)$me['id']) {
        fail('cannot_delete_self');
    }
    if (is_last_admin($id)) {
        fail('last_admin');
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    log_activity('user_delete', 'users', $id);
    ok(['deleted' => true]);
}

/** Every user may change their own password and interface language. */
function handle_profile_save(): void
{
    $me  = require_login();
    require_csrf();
    $rec = (array)param('record', []);

    $locale = in_array($rec['locale'] ?? '', ['de', 'en'], true) ? $rec['locale'] : $me['locale'];
    $args   = [$rec['full_name'] ?? null, $rec['email'] ?? null, $locale];
    $sql    = 'UPDATE users SET full_name = ?, email = ?, locale = ?';

    $new = (string)($rec['new_password'] ?? '');
    if ($new !== '') {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify((string)($rec['current_password'] ?? ''), $row['password_hash'])) {
            fail('current_password_wrong');
        }
        if (strlen($new) < 8) {
            fail('password_too_short');
        }
        $sql   .= ', password_hash = ?';
        $args[] = password_hash($new, PASSWORD_DEFAULT);
    }
    $sql   .= ' WHERE id = ?';
    $args[] = $me['id'];
    db()->prepare($sql)->execute($args);

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$me['id']]);
    $_SESSION['user'] = user_public($stmt->fetch());
    log_activity('profile_update', 'users', (int)$me['id']);
    ok(['user' => $_SESSION['user']]);
}

function handle_activity_log(): void
{
    require_role('admin');
    $rows = db()->query(
        "SELECT l.*, COALESCE(NULLIF(u.full_name, ''), u.username) AS username
         FROM activity_log l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC LIMIT 300"
    )->fetchAll();
    ok($rows);
}
