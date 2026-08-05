<?php
/**
 * Setup wizard.
 *
 * Open http://<nas>:<port>/install.php once after copying the files.
 * It checks the environment, writes api/config.php, imports db/schema.sql and
 * creates the first administrator. Delete this file afterwards.
 */

declare(strict_types=1);
ini_set('display_errors', '0');
session_start();

$configFile = __DIR__ . '/api/config.php';
$schemaFile = __DIR__ . '/db/schema.sql';
$errors  = [];
$notices = [];
$done    = false;

// Refuse to run again once the app is installed and has users.
if (is_file($configFile)) {
    $cfg = require $configFile;
    try {
        $dsn = "mysql:host={$cfg['db']['host']};port={$cfg['db']['port']};dbname={$cfg['db']['name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($n > 0) {
            http_response_code(403);
            echo '<!doctype html><meta charset="utf-8"><title>Already installed</title>'
               . '<body style="font:16px system-ui;padding:2rem;max-width:40rem">'
               . '<h1>Already installed</h1><p>The journal is set up and has ' . $n . ' user account(s). '
               . 'Delete <code>install.php</code> from the web folder and open <a href="index.html">the app</a>.</p>';
            exit;
        }
    } catch (Throwable $e) {
        $notices[] = 'A config.php exists but the database is not reachable yet: ' . htmlspecialchars($e->getMessage());
    }
}

$checks = [
    'PHP 7.4 or newer'      => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO MySQL extension'   => extension_loaded('pdo_mysql'),
    'JSON extension'        => extension_loaded('json'),
    'api/ is writable'      => is_writable(__DIR__ . '/api'),
    'backup dir is writable (backup_dir in api/config.example.php)'
        => ($backupDirCheck = (require __DIR__ . '/api/config.example.php')['app']['backup_dir'])
           && is_dir($backupDirCheck) && is_writable($backupDirCheck),
    'schema file present'   => is_file($schemaFile),
    'outbound HTTPS (weather)' => function_exists('curl_init') || ini_get('allow_url_fopen'),
];

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$v = fn(string $k, string $d = '') => htmlspecialchars((string)($_POST[$k] ?? $d));

if ($post) {
    $dbHost = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort = (int)($_POST['db_port'] ?? 3307);
    $dbName = trim((string)($_POST['db_name'] ?? 'beekeeping'));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $admin  = trim((string)($_POST['admin_user'] ?? ''));
    $pass1  = (string)($_POST['admin_pass'] ?? '');
    $pass2  = (string)($_POST['admin_pass2'] ?? '');
    $locale = ($_POST['locale'] ?? 'de') === 'en' ? 'en' : 'de';
    $weather = !empty($_POST['weather']);

    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $admin)) {
        $errors[] = 'The administrator name may contain letters, digits, dot, dash and underscore (3-60 characters).';
    }
    if (strlen($pass1) < 8) {
        $errors[] = 'The administrator password needs at least 8 characters.';
    }
    if ($pass1 !== $pass2) {
        $errors[] = 'The two passwords do not match.';
    }

    $pdo = null;
    if (!$errors) {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $errors[] = 'Database connection failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    if (!$errors && $pdo) {
        try {
            // Import the schema statement by statement.
            $sql = file_get_contents($schemaFile);
            $sql = preg_replace('/^--.*$/m', '', (string)$sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                $pdo->exec($stmt);
            }
        } catch (PDOException $e) {
            $errors[] = 'Importing the schema failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    if (!$errors && $pdo) {
        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (username, full_name, password_hash, role, locale, is_active)
                 VALUES (?, ?, ?, "admin", ?, 1)'
            );
            $ins->execute([$admin, 'Administrator', password_hash($pass1, PASSWORD_DEFAULT), $locale]);
        } catch (PDOException $e) {
            $errors[] = 'Creating the administrator failed: ' . htmlspecialchars($e->getMessage());
        }
    }

    if (!$errors) {
        $tpl = file_get_contents(__DIR__ . '/api/config.example.php');
        $tpl = str_replace(
            [
                "'host'     => '127.0.0.1'",
                "'port'     => 3307",
                "'name'     => 'beekeeping'",
                "'user'     => 'beekeeping'",
                "'password' => 'CHANGE_ME'",
                "'default_locale'  => 'de'",
                "'enabled'      => true",
            ],
            [
                "'host'     => " . var_export($dbHost, true),
                "'port'     => " . $dbPort,
                "'name'     => " . var_export($dbName, true),
                "'user'     => " . var_export($dbUser, true),
                "'password' => " . var_export($dbPass, true),
                "'default_locale'  => " . var_export($locale, true),
                "'enabled'      => " . ($weather ? 'true' : 'false'),
            ],
            (string)$tpl
        );
        if (file_put_contents($configFile, $tpl) === false) {
            $errors[] = 'Could not write api/config.php. Make the api/ folder writable and try again.';
        } else {
            @chmod($configFile, 0640);
            $done = true;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beekeeping Journal - setup</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="setup-page">
<main class="setup">
  <header class="setup__head">
    <span class="brand__mark" aria-hidden="true"></span>
    <h1>Beekeeping Journal</h1>
    <p class="muted">One-time setup. Delete <code>install.php</code> when you are finished.</p>
  </header>

  <?php if ($done): ?>
    <div class="alert alert--ok">
      <h2>Setup complete</h2>
      <p>The database is ready and your administrator account has been created.</p>
      <p><strong>Delete <code>install.php</code> now</strong>, then open the journal.</p>
      <p><a class="btn btn--primary" href="index.html">Open the journal</a></p>
    </div>
  <?php else: ?>

    <section class="card">
      <h2>Environment</h2>
      <ul class="checklist">
        <?php foreach ($checks as $label => $okFlag): ?>
          <li class="<?= $okFlag ? 'is-ok' : 'is-bad' ?>"><?= htmlspecialchars($label) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php foreach ($notices as $n): ?><p class="muted"><?= $n ?></p><?php endforeach; ?>
    </section>

    <?php if ($errors): ?>
      <div class="alert alert--bad">
        <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" class="card form-grid" autocomplete="off">
      <h2>Database</h2>
      <label>Host<input name="db_host" value="<?= $v('db_host', '127.0.0.1') ?>" required></label>
      <label>Port<input name="db_port" type="number" value="<?= $v('db_port', '3307') ?>" required></label>
      <label>Database name<input name="db_name" value="<?= $v('db_name', 'beekeeping') ?>" required></label>
      <label>Database user<input name="db_user" value="<?= $v('db_user', 'beekeeping') ?>" required></label>
      <label>Database password<input name="db_pass" type="password" value=""></label>

      <h2>Administrator</h2>
      <label>User name<input name="admin_user" value="<?= $v('admin_user', 'admin') ?>" required></label>
      <label>Password (min. 8 characters)<input name="admin_pass" type="password" required></label>
      <label>Repeat password<input name="admin_pass2" type="password" required></label>
      <label>Default language
        <select name="locale">
          <option value="de" selected>Deutsch</option>
          <option value="en">English</option>
        </select>
      </label>
      <label class="check"><input type="checkbox" name="weather" value="1" checked> Fetch weather automatically (needs outbound internet access)</label>

      <div class="form-actions">
        <button class="btn btn--primary" type="submit">Install</button>
      </div>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
