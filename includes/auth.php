<?php
/**
 * Session-Handling, Login/Logout, Rollenprüfung und CSRF-Schutz.
 */

require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/db.php';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => defined('SESSION_SECURE_COOKIE') ? SESSION_SECURE_COOKIE : false,
        'samesite' => 'Lax',
    ]);
    session_name('imkerei_session');
    session_start();
}

function current_user(): ?array
{
    start_secure_session();
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        json_error('Nicht angemeldet.', 401);
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        json_error('Diese Aktion erfordert Administratorrechte.', 403);
    }
    return $user;
}

function attempt_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :u AND active = 1 LIMIT 1');
    $stmt->execute(['u' => $username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')
        ->execute(['id' => $user['id']]);

    start_secure_session();
    session_regenerate_id(true);

    $safeUser = [
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'name'     => $user['name'],
        'email'    => $user['email'],
        'role'     => $user['role'],
    ];
    $_SESSION['user'] = $safeUser;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));

    return $safeUser;
}

function do_logout(): void
{
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path']);
    }
    session_destroy();
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_secure_session();
    $method = $_SERVER['REQUEST_METHOD'];
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return; // lesende Zugriffe brauchen kein CSRF-Token
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !hash_equals($expected, $sent)) {
        json_error('Ungültiges Sicherheitstoken (CSRF). Bitte Seite neu laden.', 419);
    }
}

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_ok($data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
