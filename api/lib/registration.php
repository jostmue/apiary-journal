<?php
/**
 * Self-registration, for installations running in open mode.
 *
 * An account is created straight away but cannot sign in until the address
 * behind it has been confirmed - otherwise anyone could register with someone
 * else's address, and a password reset would then land in a stranger's inbox.
 *
 * Nothing in here is reachable in private mode; there the routes refuse and an
 * administrator creates accounts as before.
 */

declare(strict_types=1);

const VERIFY_TTL_HOURS       = 48;
const VERIFY_UNCONFIRMED_DAYS = 7;
const REGISTER_MAX_PER_ADDRESS = 5;   // within LOGIN_WINDOW_MINUTES

function app_mode(): string
{
    return normalise_mode(config()['app']['mode'] ?? null);
}

/**
 * Anything that is not exactly 'open' counts as private.
 *
 * A typo in the configuration must not accidentally open an installation to
 * the world, so the permissive value is the one that has to be spelled right.
 */
function normalise_mode($value): string
{
    return (is_string($value) && strtolower(trim($value)) === 'open') ? 'open' : 'private';
}

function registration_open(): bool
{
    // Without mail there is no way to confirm an address, so the form stays
    // hidden however the mode is set.
    return app_mode() === 'open' && mail_enabled();
}

function require_open_mode(): void
{
    if (!registration_open()) {
        fail('registration_closed', 403);
    }
}

function legal_urls(): array
{
    $c = config()['app'];
    return [
        'terms'   => trim((string)($c['terms_url'] ?? '')) ?: 'legal/terms.html',
        'privacy' => trim((string)($c['privacy_url'] ?? '')) ?: 'legal/privacy.html',
    ];
}

/** POST auth/register  {username, email, full_name, password, terms, website} */
function handle_register(): void
{
    require_open_mode();
    usleep(250000);

    // "website" is a field the form keeps out of sight. A person never fills
    // it in; a script that submits every input it finds does.
    if (trim((string)param('website', '')) !== '') {
        log_activity('register_bot', 'users', null, client_ip());
        ok(['registered' => true]);
    }

    $ip = client_ip();
    if ($ip !== '' && reset_requests_recent_action('register', 'ip', $ip) >= REGISTER_MAX_PER_ADDRESS) {
        fail('too_many_attempts', 429);
    }

    $username = trim((string)param('username', ''));
    $email    = trim((string)param('email', ''));
    $fullName = trim((string)param('full_name', ''));
    $password = (string)param('password', '');

    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        fail('invalid_username');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('invalid_email');
    }
    if (strlen($password) < 8) {
        fail('password_too_short');
    }
    if (empty(param('terms'))) {
        fail('terms_required');
    }

    log_activity('register', 'users', null, $ip);

    // An address or name that is taken must not be reported as such, or the
    // form becomes a way to test who has an account here. The reply is the
    // same either way; a genuine owner learns about it by mail.
    $stmt = db()->prepare('SELECT id, email, email_verified_at FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        if (!empty($existing['email']) && $existing['email'] === $email) {
            notify_duplicate_registration($existing, $email);
        }
        ok(['registered' => true]);
    }

    $ins = db()->prepare(
        'INSERT INTO users (username, full_name, email, password_hash, role, locale, is_active, terms_accepted_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())'
    );
    $locale = in_array(param('locale'), ['de', 'en'], true) ? param('locale') : (config()['app']['default_locale'] ?? 'de');
    try {
        $ins->execute([$username, $fullName ?: null, $email,
                       password_hash($password, PASSWORD_DEFAULT), 'beekeeper', $locale]);
    } catch (PDOException $e) {
        // Lost a race against a parallel registration; same silent answer.
        ok(['registered' => true]);
    }
    $userId = (int)db()->lastInsertId();
    send_verification_mail($userId, $email, $fullName ?: $username, (string)$locale);
    ok(['registered' => true]);
}

/** Someone tried to register with an address that already has an account. */
function notify_duplicate_registration(array $user, string $email): void
{
    $subject = 'Apiary-Journal: Registrierungsversuch mit deiner Adresse';
    $body = "Hallo,\n\n"
          . "jemand hat versucht, sich mit deiner Adresse zu registrieren. "
          . "Es gibt bereits ein Konto, daher wurde kein neues angelegt.\n\n"
          . "Warst du das und hast dein Passwort vergessen, nutze bitte "
          . "\"Passwort vergessen?\" auf der Anmeldeseite.\n\n"
          . "Warst du das nicht, ist nichts passiert.\n";
    send_mail($email, $subject, $body);
}

function send_verification_mail(int $userId, string $email, string $name, string $locale): void
{
    db()->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$userId]);

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_ip)
         VALUES (?, ?, (NOW() + INTERVAL ' . VERIFY_TTL_HOURS . ' HOUR), ?)'
    )->execute([$userId, hash('sha256', $token), client_ip() ?: null]);

    $link = reset_base_url() . '/index.html#/verify/' . $token;

    if ($locale === 'en') {
        $subject = 'Confirm your Apiary-Journal account';
        $body = "Hello {$name},\n\n"
              . "please confirm this address to finish creating your account:\n\n{$link}\n\n"
              . 'The link is valid for ' . VERIFY_TTL_HOURS . " hours.\n\n"
              . "If you did not sign up, ignore this mail - the account stays unusable\n"
              . 'and is removed after ' . VERIFY_UNCONFIRMED_DAYS . " days.\n";
    } else {
        $subject = 'Apiary-Journal: Adresse bestätigen';
        $body = "Hallo {$name},\n\n"
              . "bitte bestätige diese Adresse, um dein Konto fertig einzurichten:\n\n{$link}\n\n"
              . 'Der Link gilt ' . VERIFY_TTL_HOURS . " Stunden.\n\n"
              . "Hast du dich nicht angemeldet, ignoriere diese Nachricht. Das Konto\n"
              . 'bleibt unbenutzbar und wird nach ' . VERIFY_UNCONFIRMED_DAYS . " Tagen entfernt.\n";
    }
    send_mail($email, $subject, $body);
}

/** POST auth/verify  {token} */
function handle_verify_email(): void
{
    usleep(150000);
    $token = (string)param('token', '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        fail('verify_token_invalid', 400);
    }

    $stmt = db()->prepare(
        'SELECT v.id, v.user_id FROM email_verifications v
         JOIN users u ON u.id = v.user_id
         WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('verify_token_invalid', 400);
    }

    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL')
        ->execute([$row['user_id']]);
    db()->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    log_activity('email_verified', 'users', (int)$row['user_id']);

    ok(['verified' => true]);
}

/**
 * Accounts that never confirmed their address are removed, which also frees
 * the name and the address for someone else. Runs occasionally rather than on
 * a schedule, since the app has no cron of its own.
 */
function purge_unconfirmed_accounts(): void
{
    if (app_mode() !== 'open') {
        return;
    }
    try {
        db()->prepare(
            'DELETE FROM users
             WHERE email_verified_at IS NULL
               AND created_at < (NOW() - INTERVAL ' . VERIFY_UNCONFIRMED_DAYS . ' DAY)'
        )->execute();
    } catch (Throwable $e) {
        error_log('[apiary-journal] purging unconfirmed accounts failed: ' . $e->getMessage());
    }
}
