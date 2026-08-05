<?php
/**
 * Session handling, login, CSRF protection and role checks.
 *
 * Roles:
 *   admin      - everything, plus user management, backup/restore
 *   beekeeper  - create, edit and delete journal records
 *   viewer     - read-only access and reports
 */

declare(strict_types=1);

function session_start_app(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $minutes = (int)(config()['app']['session_minutes'] ?? 480);
    $secure  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => $minutes * 60,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('BJSESSID');
    session_start();

    // Idle timeout.
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $minutes * 60) {
        session_unset();
        session_destroy();
        session_start();
    }
    $_SESSION['last_seen'] = time();
}

function current_user(): array
{
    return $_SESSION['user'] ?? [];
}

function require_login(): array
{
    $u = current_user();
    if (empty($u['id'])) {
        fail('auth_required', 401);
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        fail('forbidden', 403);
    }
    return $u;
}

/** Anything that changes data needs at least the beekeeper role. */
function require_write(): array
{
    return require_role('admin', 'beekeeper');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? (string)param('csrf', ''));
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
        fail('csrf_invalid', 419);
    }
}

function user_public(array $row): array
{
    return [
        'id'        => (int)$row['id'],
        'username'  => $row['username'],
        'full_name' => $row['full_name'],
        'email'     => $row['email'],
        'role'      => $row['role'],
        'locale'    => $row['locale'],
        'is_active' => (int)$row['is_active'],
    ];
}

/** Failed attempts within this window lock the account name / address. */
const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_WINDOW_MINUTES = 15;

function login_is_locked(string $username): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM activity_log
         WHERE action = 'login_failed'
           AND created_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)
           AND (detail = ? OR ip = ?)"
    );
    $stmt->execute([$username, $_SERVER['REMOTE_ADDR'] ?? '']);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function do_login(string $username, string $password): void
{
    // Small delay makes brute forcing over the LAN unattractive.
    usleep(250000);

    if (login_is_locked($username)) {
        log_activity('login_locked', 'users', null, $username);
        fail('too_many_attempts', 429);
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        log_activity('login_failed', 'users', null, $username);
        fail('login_invalid', 401);
    }
    if ((int)$row['is_active'] !== 1) {
        fail('account_disabled', 403);
    }

    // Refresh the hash if PHP's default cost changed.
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        $up = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $up->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user'] = user_public($row);
    $_SESSION['csrf'] = bin2hex(random_bytes(24));

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$row['id']]);
    log_activity('login', 'users', (int)$row['id']);

    ok(['user' => $_SESSION['user'], 'csrf' => $_SESSION['csrf']]);
}

function do_logout(): void
{
    log_activity('logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    session_destroy();
    ok(['logged_out' => true]);
}
