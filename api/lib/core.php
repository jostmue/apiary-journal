<?php
/**
 * Core helpers: configuration, PDO connection, JSON in/out, logging.
 */

declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config.php';
        if (!is_file($file)) {
            json_out(['ok' => false, 'error' => 'not_installed'], 500);
        }
        $cfg = require $file;
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config()['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)$c['port'], $c['name'], $c['charset']
        );
        try {
            $pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
        } catch (PDOException $e) {
            error_log('[beekeeping] db connect failed: ' . $e->getMessage());
            json_out(['ok' => false, 'error' => 'db_unavailable'], 500);
        }
    }
    return $pdo;
}

/**
 * Whether the request reached the client over HTTPS.
 *
 * Behind a reverse proxy the connection to PHP is plain HTTP, so $_SERVER
 * ['HTTPS'] is empty and the session cookie would go out without the Secure
 * flag. X-Forwarded-Proto says otherwise - but anyone can send that header, so
 * it counts only when the request came from a proxy listed in the config.
 */
function request_is_https(): bool
{
    return is_https_from($_SERVER, trusted_proxies());
}

/**
 * The address of the actual client.
 *
 * Same rule: X-Forwarded-For is only believed when the request arrived from a
 * configured proxy, otherwise a caller could forge any address and walk around
 * the login rate limit.
 */
function client_ip(): string
{
    return client_ip_from($_SERVER, trusted_proxies());
}

function trusted_proxies(): array
{
    return array_map('strval', (array)(config()['security']['trusted_proxies'] ?? []));
}

/* The three functions below take everything they need as arguments, so the
   behaviour can be tested without a request, a config file or a database. */

function is_https_from(array $server, array $proxies): bool
{
    if (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off') {
        return true;
    }
    if (!ip_is_trusted((string)($server['REMOTE_ADDR'] ?? ''), $proxies)) {
        return false;
    }
    $proto = strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($proto !== '') {
        // A chain of proxies appends, so the client's own protocol is first.
        return trim(explode(',', $proto)[0]) === 'https';
    }
    return (int)($server['HTTP_X_FORWARDED_PORT'] ?? 0) === 443;
}

function client_ip_from(array $server, array $proxies): string
{
    $remote = (string)($server['REMOTE_ADDR'] ?? '');
    if (!ip_is_trusted($remote, $proxies)) {
        return $remote;
    }
    // Walk from the nearest hop outwards and stop at the first address that is
    // not a proxy of ours - that is the client.
    $chain = array_reverse(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($chain as $candidate) {
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) && !ip_is_trusted($candidate, $proxies)) {
            return $candidate;
        }
    }
    return $remote;
}

function ip_is_trusted(string $ip, array $proxies): bool
{
    if ($ip === '') {
        return false;
    }
    foreach ($proxies as $entry) {
        if (ip_matches($ip, (string)$entry)) {
            return true;
        }
    }
    return false;
}

/** Match a plain address or a CIDR range such as 172.16.0.0/12. */
function ip_matches(string $ip, string $pattern): bool
{
    if ($pattern === '') {
        return false;
    }
    if (strpos($pattern, '/') === false) {
        return $ip === $pattern;
    }
    [$subnet, $bits] = explode('/', $pattern, 2);
    $ipBin     = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    $bits      = (int)$bits;
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)
        || $bits < 0 || $bits > strlen($ipBin) * 8) {
        return false;
    }
    $whole = intdiv($bits, 8);
    $rest  = $bits % 8;
    if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
        return false;
    }
    if ($rest === 0) {
        return true;
    }
    $mask = ~((1 << (8 - $rest)) - 1) & 0xFF;
    return (ord($ipBin[$whole]) & $mask) === (ord($subnetBin[$whole]) & $mask);
}

/**
 * Headers every response carries.
 *
 * The HTML page is served by the web server, not by PHP, so its policy lives
 * in the meta tag in index.html and in the sample server configs under
 * deploy/. These here cover the API responses and downloads.
 */
function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if (request_is_https() && !empty(config()['security']['hsts'])) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Send a JSON response and stop. */
function json_out($payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        send_security_headers();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = null): void
{
    json_out(['ok' => true, 'data' => $data]);
}

/** $error is a translation key resolved by the browser (see i18n.js). */
function fail(string $error, int $status = 400, $detail = null): void
{
    json_out(['ok' => false, 'error' => $error, 'detail' => $detail], $status);
}

/** Decoded JSON request body. */
function input(): array
{
    static $data = null;
    if ($data === null) {
        $raw = file_get_contents('php://input');
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }
    }
    return $data;
}

function param(string $key, $default = null)
{
    $in = input();
    if (array_key_exists($key, $in)) {
        return $in[$key];
    }
    if (array_key_exists($key, $_GET)) {
        return $_GET[$key];
    }
    return $default;
}

function param_int(string $key, ?int $default = null): ?int
{
    $v = param($key, $default);
    if ($v === null || $v === '') {
        return $default;
    }
    return (int)$v;
}

/** Normalise an incoming value for a database column. */
function clean_value($value, string $type)
{
    if ($value === null || $value === '') {
        return null;
    }
    switch ($type) {
        case 'int':
            return (int)$value;
        case 'float':
            return (float)str_replace(',', '.', (string)$value);
        case 'bool':
            return ($value === true || $value === 1 || $value === '1' || $value === 'true') ? 1 : 0;
        case 'date':
            return substr((string)$value, 0, 10);
        case 'datetime':
            $v = str_replace('T', ' ', (string)$value);
            return strlen($v) === 16 ? $v . ':00' : substr($v, 0, 19);
        default:
            // "enum:a,b,c" accepts nothing but the listed values. Used for
            // fields whose value reaches an HTML attribute in the browser,
            // where a free string would be one missing escape away from XSS.
            if (strncmp($type, 'enum:', 5) === 0) {
                $allowed = explode(',', substr($type, 5));
                return in_array((string)$value, $allowed, true) ? (string)$value : null;
            }
            return (string)$value;
    }
}

function log_activity(string $action, ?string $entity = null, ?int $entityId = null, ?string $detail = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (user_id, action, entity, entity_id, detail, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            current_user()['id'] ?? null,
            $action, $entity, $entityId, $detail,
            client_ip() ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('[beekeeping] log failed: ' . $e->getMessage());
    }
}
