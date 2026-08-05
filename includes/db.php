<?php
/**
 * Stellt eine einzelne PDO-Verbindung zur MariaDB-Datenbank bereit.
 */

require_once __DIR__ . '/config_loader.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'error' => 'Datenbankverbindung fehlgeschlagen. Bitte config.php prüfen.',
                'detail' => $e->getMessage(),
            ]);
            exit;
        }
    }
    return $pdo;
}
