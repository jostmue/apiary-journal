<?php
/**
 * Backup and restore.
 *
 * Two formats are produced:
 *   *.ajb.json(.gz)  full snapshot, used for restore inside the app
 *   *.sql            portable dump for mysql/phpMyAdmin (export only)
 *
 * Everything is written in pure PHP, so no mysqldump binary is required -
 * which matters on DSM, where the web server user usually cannot reach it.
 */

declare(strict_types=1);

const BACKUP_TABLES = [
    'users', 'apiaries', 'colonies', 'queens', 'inspections', 'feedings',
    'treatments', 'harvests', 'events', 'tasks', 'settings',
];

function backup_dir(): string
{
    $dir = config()['app']['backup_dir'];
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        fail('backup_dir_not_writable', 500, $dir);
    }
    $dir = rtrim($dir, '/');

    // If the directory sits inside the web root anyway, an index file at
    // least prevents directory listings; nginx on DSM ignores .htaccess,
    // so the random file name suffix is the remaining protection there.
    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $real    = realpath($dir);
    if ($docRoot && $real && strpos($real, $docRoot) === 0 && !is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function backup_safe_name(string $name): string
{
    $name = basename($name);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
        fail('invalid_filename');
    }
    return $name;
}

function backup_collect(): array
{
    $data = ['format' => 'apiary-journal', 'version' => 1, 'created_at' => date('c'), 'tables' => []];
    foreach (BACKUP_TABLES as $t) {
        $rows = db()->query("SELECT * FROM {$t}")->fetchAll();
        $data['tables'][$t] = $rows;
    }
    return $data;
}

/** Create a snapshot; returns the file name. */
function backup_create(string $label = 'manual'): string
{
    $dir  = backup_dir();
    $data = backup_collect();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);

    // The random suffix makes backup file names unguessable, which matters
    // if the backup directory happens to sit inside the web root.
    $base = 'backup-' . date('Y-m-d-His') . '-' . preg_replace('/[^a-z0-9]/i', '', $label)
          . '-' . bin2hex(random_bytes(3));
    if (function_exists('gzencode')) {
        $file = $base . '.ajb.json.gz';
        file_put_contents($dir . '/' . $file, gzencode($json, 6));
    } else {
        $file = $base . '.ajb.json';
        file_put_contents($dir . '/' . $file, $json);
    }

    backup_prune();
    log_activity('backup_create', 'backup', null, $file);
    return $file;
}

function backup_prune(): void
{
    $keep = (int)(config()['app']['backup_keep'] ?? 0);
    if ($keep <= 0) {
        return;
    }
    $files = backup_list_files();
    if (count($files) <= $keep) {
        return;
    }
    $old = array_slice($files, $keep);
    foreach ($old as $f) {
        @unlink(backup_dir() . '/' . $f['name']);
    }
}

/** Newest first. */
function backup_list_files(): array
{
    $dir  = backup_dir();
    $out  = [];
    foreach (glob($dir . '/*.ajb.json*') ?: [] as $path) {
        $out[] = [
            'name'     => basename($path),
            'size'     => filesize($path),
            'created'  => date('Y-m-d H:i:s', filemtime($path)),
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
    return $out;
}

function backup_read(string $file): array
{
    $path = backup_dir() . '/' . backup_safe_name($file);
    if (!is_file($path)) {
        fail('backup_not_found', 404);
    }
    $raw = file_get_contents($path);
    if (substr($file, -3) === '.gz') {
        $raw = gzdecode($raw);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || ($data['format'] ?? '') !== 'apiary-journal') {
        fail('backup_invalid');
    }
    return $data;
}

/**
 * Restore a snapshot. The current state is saved as an extra backup first,
 * so a wrong click can always be undone.
 */
function backup_restore(array $data, bool $keepUsers): void
{
    backup_create('prerestore');

    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->beginTransaction();
    try {
        $tables = BACKUP_TABLES;
        if ($keepUsers) {
            $tables = array_values(array_diff($tables, ['users']));
        }
        foreach (array_reverse($tables) as $t) {
            $pdo->exec("DELETE FROM {$t}");
        }
        foreach ($tables as $t) {
            $rows = $data['tables'][$t] ?? [];
            if (!$rows) {
                continue;
            }
            $cols = array_keys($rows[0]);
            $sql  = "INSERT INTO {$t} (" . implode(',', $cols) . ') VALUES ('
                  . implode(',', array_fill(0, count($cols), '?')) . ')';
            $stmt = $pdo->prepare($sql);
            foreach ($rows as $row) {
                $stmt->execute(array_values($row));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        error_log('[beekeeping] restore failed: ' . $e->getMessage());
        fail('restore_failed', 500, $e->getMessage());
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    log_activity('backup_restore', 'backup');
}

/** Portable SQL dump written directly to the browser. */
function backup_sql_stream(): void
{
    $pdo  = db();
    $name = 'apiary-journal-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');

    echo "-- Apiary-Journal SQL dump, " . date('c') . "\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach (BACKUP_TABLES as $t) {
        echo "-- table {$t}\nDELETE FROM `{$t}`;\n";
        $stmt = $pdo->query("SELECT * FROM `{$t}`");
        while ($row = $stmt->fetch()) {
            $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
            $vals = array_map(function ($v) use ($pdo) {
                if ($v === null) {
                    return 'NULL';
                }
                return $pdo->quote((string)$v);
            }, array_values($row));
            echo 'INSERT INTO `' . $t . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

// --- Route handlers --------------------------------------------------------

function handle_backup_list(): void
{
    require_role('admin');
    ok(['files' => backup_list_files(), 'dir' => backup_dir()]);
}

function handle_backup_create(): void
{
    require_role('admin');
    require_csrf();
    ok(['file' => backup_create('manual')]);
}

function handle_backup_delete(): void
{
    require_role('admin');
    require_csrf();
    $file = backup_safe_name((string)param('file', ''));
    @unlink(backup_dir() . '/' . $file);
    log_activity('backup_delete', 'backup', null, $file);
    ok(['deleted' => true]);
}

/** GET download: api/index.php?r=backup/download&file=...&csrf=... */
function handle_backup_download(): void
{
    require_role('admin');
    require_csrf();
    $file = backup_safe_name((string)($_GET['file'] ?? ''));
    $path = backup_dir() . '/' . $file;
    if (!is_file($path)) {
        fail('backup_not_found', 404);
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $file . '"');
    readfile($path);
    exit;
}

/** GET download: api/index.php?r=backup/sql&csrf=... */
function handle_backup_sql(): void
{
    require_role('admin');
    require_csrf();
    backup_sql_stream();
}

function handle_backup_restore(): void
{
    require_role('admin');
    require_csrf();
    $keepUsers = (bool)param('keep_users', true);

    $file = (string)param('file', '');
    if ($file !== '') {
        $data = backup_read($file);
    } else {
        $payload = param('payload');
        $data    = is_array($payload) ? $payload : json_decode((string)$payload, true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'apiary-journal') {
            fail('backup_invalid');
        }
    }
    backup_restore($data, $keepUsers);
    ok(['restored' => true]);
}

/** Upload a snapshot file into the backup directory. */
function handle_backup_upload(): void
{
    require_role('admin');
    require_csrf();
    if (empty($_FILES['file']['tmp_name'])) {
        fail('no_file');
    }
    $name = backup_safe_name($_FILES['file']['name']);
    if (!preg_match('/\.ajb\.json(\.gz)?$/', $name)) {
        fail('backup_invalid');
    }
    move_uploaded_file($_FILES['file']['tmp_name'], backup_dir() . '/' . $name);
    log_activity('backup_upload', 'backup', null, $name);
    ok(['file' => $name]);
}
