<?php
/**
 * Forgotten password: request a link, then set a new password with it.
 *
 * Rules that shape this file:
 *   - The answer never reveals whether an account exists. Both routes reply
 *     the same way whatever happened, or the form becomes a way to find out
 *     who has an account here.
 *   - Only the hash of the token is stored, so a leaked database row cannot be
 *     turned back into a working link.
 *   - A token is valid once and expires, and asking again invalidates the
 *     earlier ones.
 */

declare(strict_types=1);

const RESET_TTL_MINUTES     = 60;
const RESET_MAX_PER_ACCOUNT = 3;    // within the login rate limit window
const RESET_MAX_PER_ADDRESS = 20;

/**
 * Where the link should point.
 *
 * Taken from the configuration when set. Falling back to the request means
 * trusting the Host header, which an attacker can choose - the link would then
 * carry a valid token to a site of their choosing. So the fallback is used
 * only when nothing is configured, and the setting is what the docs recommend.
 */
function reset_base_url(): string
{
    $configured = trim((string)(config()['app']['base_url'] ?? ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = request_is_https() ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    // api/index.php -> the directory holding index.html
    $dir    = rtrim(dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * How often an action was logged for one account name or address inside the
 * rate limit window. $column is a literal from the call sites, never input.
 */
function reset_requests_recent_action(string $action, string $column, string $value): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM activity_log
         WHERE action = ?
           AND created_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)
           AND {$column} = ?"
    );
    $stmt->execute([$action, $value]);
    return (int)$stmt->fetchColumn();
}

function reset_requests_recent(string $column, string $value): int
{
    return reset_requests_recent_action('password_reset_request', $column, $value);
}

/** POST auth/forgot  {login: "<user name or e-mail>"} */
function handle_forgot_password(): void
{
    $login = trim((string)param('login', ''));

    // Same delay and the same answer in every case.
    usleep(250000);
    $answer = fn() => ok(['requested' => true]);

    if ($login === '' || !mail_enabled()) {
        if (!mail_enabled()) {
            error_log('[apiary-journal] password reset requested while mail is disabled');
        }
        $answer();
    }

    $ip = client_ip();
    if (reset_requests_recent('detail', $login) >= RESET_MAX_PER_ACCOUNT
        || ($ip !== '' && reset_requests_recent('ip', $ip) >= RESET_MAX_PER_ADDRESS)) {
        $answer();
    }
    log_activity('password_reset_request', 'users', null, $login);

    $stmt = db()->prepare(
        'SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();
    if (!$user || empty($user['email'])) {
        $answer();
    }

    // Any link sent earlier stops working now.
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$user['id']]);

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, created_ip)
         VALUES (?, ?, (NOW() + INTERVAL ' . RESET_TTL_MINUTES . ' MINUTE), ?)'
    )->execute([$user['id'], hash('sha256', $token), $ip ?: null]);

    $link = reset_base_url() . '/index.html#/reset/' . $token;
    $name = trim((string)($user['full_name'] ?? '')) ?: (string)$user['username'];

    if (($user['locale'] ?? 'de') === 'en') {
        $subject = 'Reset your Apiary-Journal password';
        $body = "Hello {$name},\n\n"
              . "someone asked to reset the password for your Apiary-Journal account.\n"
              . "Open this link to choose a new one:\n\n{$link}\n\n"
              . 'The link is valid for ' . RESET_TTL_MINUTES . " minutes and works once.\n\n"
              . "If this was not you, nothing has changed and you can ignore this mail.\n";
    } else {
        $subject = 'Neues Passwort für dein Apiary-Journal';
        $body = "Hallo {$name},\n\n"
              . "es wurde ein neues Passwort für dein Apiary-Journal-Konto angefordert.\n"
              . "Über diesen Link kannst du eines vergeben:\n\n{$link}\n\n"
              . 'Der Link gilt ' . RESET_TTL_MINUTES . " Minuten und lässt sich einmal verwenden.\n\n"
              . "Warst du das nicht, ist nichts passiert und du kannst diese Nachricht ignorieren.\n";
    }

    if (!send_mail((string)$user['email'], $subject, $body)) {
        // Still the same answer outward; the reason is in the error log.
        log_activity('password_reset_mail_failed', 'users', (int)$user['id']);
    }
    $answer();
}

/** POST auth/reset  {token: "...", password: "..."} */
function handle_reset_password(): void
{
    usleep(250000);
    $token    = (string)param('token', '');
    $password = (string)param('password', '');

    if (strlen($password) < 8) {
        fail('password_too_short');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        fail('reset_token_invalid', 400);
    }

    $stmt = db()->prepare(
        'SELECT r.id, r.user_id FROM password_resets r
         JOIN users u ON u.id = r.user_id AND u.is_active = 1
         WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('reset_token_invalid', 400);
    }

    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
    db()->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);

    // Whoever locked themselves out by guessing gets a clean slate, otherwise
    // the new password could not be used for the rest of the window.
    db()->prepare(
        "DELETE FROM activity_log
         WHERE action = 'login_failed' AND detail = (SELECT username FROM users WHERE id = ?)"
    )->execute([$row['user_id']]);

    log_activity('password_reset_done', 'users', (int)$row['user_id']);
    ok(['reset' => true]);
}
