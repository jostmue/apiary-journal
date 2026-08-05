<?php
/**
 * Konfigurationsdatei für das Imkerei-Tagebuch.
 *
 * WICHTIG: Diese Datei kopieren/umbenennen nach "config.php"
 * (im selben Ordner) und die Werte an deine Synology-Umgebung anpassen.
 * config.php ist in .gitignore und wird NICHT überschrieben, wenn du
 * die App später aktualisierst.
 */

// ---- Datenbankzugang (MariaDB, angelegt über Synology Paketzentrum) ----
define('DB_HOST', 'localhost');       // meist "localhost", ggf. Port ergänzen
define('DB_PORT', '3306');
define('DB_NAME', 'imkerei');
define('DB_USER', 'imkerei_app');     // eigenen DB-User verwenden, NICHT root!
define('DB_PASS', 'BITTE_AENDERN');

// ---- Sicherheit ----
// Zufälligen, langen String erzeugen, z.B. mit: php -r "echo bin2hex(random_bytes(32));"
define('APP_SECRET', 'BITTE_DURCH_ZUFAELLIGEN_STRING_ERSETZEN');

// Session-Cookie nur über HTTPS senden (empfohlen, wenn NAS per HTTPS/Reverse Proxy erreichbar ist)
define('SESSION_SECURE_COOKIE', false); // auf true setzen, sobald HTTPS aktiv ist

// ---- Zeitzone ----
define('APP_TIMEZONE', 'Europe/Berlin');

// ---- Wetterdaten (Open-Meteo, kostenlos, kein API-Key nötig) ----
// Muss i.d.R. nicht verändert werden.
define('WEATHER_GEOCODE_URL', 'https://geocoding-api.open-meteo.com/v1/search');
define('WEATHER_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast');
define('WEATHER_ARCHIVE_URL', 'https://archive-api.open-meteo.com/v1/archive');
