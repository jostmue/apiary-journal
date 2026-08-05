<?php
/**
 * Lädt config/config.php und bricht mit einer verständlichen Meldung ab,
 * falls die Datei noch nicht angelegt wurde (Erstinstallation).
 */

$configFile = __DIR__ . '/../config/config.php';

if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">
    <title>Einrichtung erforderlich</title>
    <style>body{font-family:sans-serif;max-width:640px;margin:60px auto;line-height:1.6;color:#2b2b2b}
    code{background:#f2f2f2;padding:2px 6px;border-radius:4px}</style></head><body>
    <h1>🐝 Einrichtung erforderlich</h1>
    <p>Es wurde keine <code>config/config.php</code> gefunden.</p>
    <p>Bitte kopiere <code>config/config.sample.php</code> nach
    <code>config/config.php</code> und trage dort deine Datenbankzugangsdaten ein.
    Details dazu stehen in der <code>README.md</code>.</p>
    </body></html>';
    exit;
}

require_once $configFile;

if (defined('APP_TIMEZONE')) {
    date_default_timezone_set(APP_TIMEZONE);
}
