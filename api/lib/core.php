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

/** Send a JSON response and stop. */
function json_out($payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
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
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[beekeeping] log failed: ' . $e->getMessage());
    }
}
