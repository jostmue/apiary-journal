<?php
/**
 * Zentraler API-Endpunkt.
 * Aufruf: /api.php?res=<resource>&action=<action>  (per fetch aus app.js)
 *
 * Ressourcen: auth, users, standorte, voelker, durchsichten,
 *             fuetterungen, behandlungen, ernte, aufgaben, dashboard
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/weather.php';

header('X-Content-Type-Options: nosniff');

$res    = $_GET['res']    ?? '';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Eingehende JSON-Body-Daten (für POST/PUT) einlesen
$input = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $input = json_decode($raw, true) ?: [];
    }
}

// -------------------------------------------------------------
// AUTH (login/logout/me) – keine Anmeldung nötig für 'login'
// -------------------------------------------------------------
if ($res === 'auth') {
    if ($action === 'login' && $method === 'POST') {
        $username = trim($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $password === '') {
            json_error('Benutzername und Passwort erforderlich.');
        }
        $user = attempt_login($username, $password);
        if (!$user) {
            json_error('Benutzername oder Passwort ist falsch.', 401);
        }
        json_ok(['user' => $user, 'csrf_token' => csrf_token()]);
    }

    if ($action === 'logout') {
        do_logout();
        json_ok();
    }

    if ($action === 'me') {
        $user = current_user();
        json_ok(['user' => $user, 'csrf_token' => $user ? csrf_token() : null]);
    }

    json_error('Unbekannte Aktion.', 404);
}

// Ab hier: Login erforderlich
$currentUser = require_login();
verify_csrf();
$pdo = db();

// Hilfsfunktion: numerischen Query-Parameter (id) holen
function param_id(): int
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Ungültige oder fehlende ID.');
    }
    return $id;
}

// Hilfsfunktion: prüft ob Feld gesetzt, sonst null
function val(array $arr, string $key, $default = null)
{
    return array_key_exists($key, $arr) && $arr[$key] !== '' ? $arr[$key] : $default;
}

switch ($res) {

    // ===========================================================
    // USERS (nur Admin)
    // ===========================================================
    case 'users':
        if ($action === 'list') {
            require_admin();
            $rows = $pdo->query('SELECT id, username, name, email, role, active, created_at, last_login FROM users ORDER BY name')->fetchAll();
            json_ok($rows);
        }
        if ($action === 'create' && $method === 'POST') {
            require_admin();
            $username = trim($input['username'] ?? '');
            $name = trim($input['name'] ?? '');
            $password = (string)($input['password'] ?? '');
            $role = in_array($input['role'] ?? '', ['admin', 'imker']) ? $input['role'] : 'imker';
            if ($username === '' || $name === '' || strlen($password) < 6) {
                json_error('Benutzername, Name und ein Passwort (min. 6 Zeichen) sind erforderlich.');
            }
            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, name, email, role) VALUES (:u,:p,:n,:e,:r)');
            try {
                $stmt->execute([
                    'u' => $username,
                    'p' => password_hash($password, PASSWORD_DEFAULT),
                    'n' => $name,
                    'e' => val($input, 'email'),
                    'r' => $role,
                ]);
            } catch (PDOException $e) {
                json_error('Benutzername bereits vergeben.');
            }
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            require_admin();
            $id = param_id();
            $fields = [];
            $params = ['id' => $id];
            foreach (['name', 'email', 'role'] as $f) {
                if (isset($input[$f])) {
                    $fields[] = "$f = :$f";
                    $params[$f] = $input[$f];
                }
            }
            if (isset($input['active'])) {
                $fields[] = 'active = :active';
                $params['active'] = (int)(bool)$input['active'];
            }
            if (!empty($input['password'])) {
                if (strlen($input['password']) < 6) {
                    json_error('Passwort muss mind. 6 Zeichen haben.');
                }
                $fields[] = 'password_hash = :ph';
                $params['ph'] = password_hash($input['password'], PASSWORD_DEFAULT);
            }
            if (!$fields) json_ok();
            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            require_admin();
            $id = param_id();
            if ($id === (int)$currentUser['id']) {
                json_error('Du kannst dich nicht selbst löschen.');
            }
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // STANDORTE
    // ===========================================================
    case 'standorte':
        if ($action === 'list') {
            $rows = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM voelker v WHERE v.standort_id = s.id AND v.status != "aufgeloest") AS anzahl_voelker
                                  FROM standorte s ORDER BY s.name')->fetchAll();
            json_ok($rows);
        }
        if ($action === 'get') {
            $id = param_id();
            $stmt = $pdo->prepare('SELECT * FROM standorte WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) json_error('Standort nicht gefunden.', 404);
            json_ok($row);
        }
        if ($action === 'geocode' && $method === 'POST') {
            $query = trim($input['query'] ?? '');
            $result = geocode_address($query);
            if (!$result) json_error('Ort nicht gefunden. Bitte Koordinaten manuell eingeben.');
            json_ok($result);
        }
        if ($action === 'create' && $method === 'POST') {
            $name = trim($input['name'] ?? '');
            if ($name === '') json_error('Name des Standorts erforderlich.');
            $stmt = $pdo->prepare('INSERT INTO standorte (name, adresse, plz, ort, lat, lon, flaeche_info, pachtvertrag, notizen, created_by)
                                    VALUES (:name,:adresse,:plz,:ort,:lat,:lon,:flaeche,:pacht,:notizen,:uid)');
            $stmt->execute([
                'name' => $name,
                'adresse' => val($input, 'adresse'),
                'plz' => val($input, 'plz'),
                'ort' => val($input, 'ort'),
                'lat' => val($input, 'lat'),
                'lon' => val($input, 'lon'),
                'flaeche' => val($input, 'flaeche_info'),
                'pacht' => val($input, 'pachtvertrag'),
                'notizen' => val($input, 'notizen'),
                'uid' => $currentUser['id'],
            ]);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            $id = param_id();
            $fields = [];
            $params = ['id' => $id];
            foreach (['name', 'adresse', 'plz', 'ort', 'lat', 'lon', 'flaeche_info', 'pachtvertrag', 'notizen'] as $f) {
                if (array_key_exists($f, $input)) {
                    $fields[] = "$f = :$f";
                    $params[$f] = $input[$f] === '' ? null : $input[$f];
                }
            }
            if (!$fields) json_ok();
            $pdo->prepare('UPDATE standorte SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            try {
                $pdo->prepare('DELETE FROM standorte WHERE id = :id')->execute(['id' => $id]);
            } catch (PDOException $e) {
                json_error('Standort kann nicht gelöscht werden (noch Völker zugeordnet?).');
            }
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // VÖLKER
    // ===========================================================
    case 'voelker':
        if ($action === 'list') {
            $standortId = (int)($_GET['standort_id'] ?? 0);
            $sql = 'SELECT v.*, s.name AS standort_name FROM voelker v JOIN standorte s ON s.id = v.standort_id';
            $params = [];
            if ($standortId) {
                $sql .= ' WHERE v.standort_id = :sid';
                $params['sid'] = $standortId;
            }
            $sql .= ' ORDER BY s.name, v.bezeichnung';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_ok($stmt->fetchAll());
        }
        if ($action === 'get') {
            $id = param_id();
            $stmt = $pdo->prepare('SELECT v.*, s.name AS standort_name FROM voelker v JOIN standorte s ON s.id=v.standort_id WHERE v.id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) json_error('Volk nicht gefunden.', 404);
            json_ok($row);
        }
        if ($action === 'create' && $method === 'POST') {
            $bez = trim($input['bezeichnung'] ?? '');
            $standortId = (int)($input['standort_id'] ?? 0);
            if ($bez === '' || !$standortId) json_error('Bezeichnung und Standort sind erforderlich.');
            $stmt = $pdo->prepare('INSERT INTO voelker
                (standort_id, bezeichnung, rasse, beutentyp, anzahl_zargen, herkunft, gruendungsdatum,
                 koenigin_jahr, koenigin_herkunft, koenigin_gezeichnet, koenigin_farbe, status, notizen, created_by)
                VALUES (:standort_id,:bezeichnung,:rasse,:beutentyp,:zargen,:herkunft,:gruendung,
                        :kjahr,:kherkunft,:kgez,:kfarbe,:status,:notizen,:uid)');
            $stmt->execute([
                'standort_id' => $standortId,
                'bezeichnung' => $bez,
                'rasse' => val($input, 'rasse'),
                'beutentyp' => val($input, 'beutentyp'),
                'zargen' => val($input, 'anzahl_zargen'),
                'herkunft' => val($input, 'herkunft'),
                'gruendung' => val($input, 'gruendungsdatum'),
                'kjahr' => val($input, 'koenigin_jahr'),
                'kherkunft' => val($input, 'koenigin_herkunft'),
                'kgez' => (int)(bool)($input['koenigin_gezeichnet'] ?? 0),
                'kfarbe' => val($input, 'koenigin_farbe'),
                'status' => val($input, 'status', 'aktiv'),
                'notizen' => val($input, 'notizen'),
                'uid' => $currentUser['id'],
            ]);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            $id = param_id();
            $fields = [];
            $params = ['id' => $id];
            $allowed = ['standort_id', 'bezeichnung', 'rasse', 'beutentyp', 'anzahl_zargen', 'herkunft',
                        'gruendungsdatum', 'koenigin_jahr', 'koenigin_herkunft', 'koenigin_gezeichnet',
                        'koenigin_farbe', 'status', 'notizen'];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $input)) {
                    $fields[] = "$f = :$f";
                    $v = $input[$f];
                    if ($f === 'koenigin_gezeichnet') $v = (int)(bool)$v;
                    $params[$f] = ($v === '' ? null : $v);
                }
            }
            if (!$fields) json_ok();
            $pdo->prepare('UPDATE voelker SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            $pdo->prepare('DELETE FROM voelker WHERE id = :id')->execute(['id' => $id]);
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // DURCHSICHTEN (inkl. automatischem Wetter)
    // ===========================================================
    case 'durchsichten':
        $fields = [
            'volk_id', 'datum', 'uhrzeit', 'wetter_temp_c', 'wetter_wind_kmh', 'wetter_beschreibung', 'wetter_code',
            'stifte_vorhanden', 'offene_brut', 'verdeckelte_brut', 'brutwaben_anzahl', 'futterwaben_anzahl',
            'volksstaerke_waben', 'koenigin_gesehen', 'weiselrichtig', 'schwarmzellen', 'spielnaepfchen',
            'honigraum_vorhanden', 'varroa_befall', 'varroa_anzahl_gemuell', 'krankheitsanzeichen',
            'sanftmut', 'wabensitz', 'stechlust', 'massnahmen', 'notizen',
        ];
        $boolFields = ['stifte_vorhanden', 'offene_brut', 'verdeckelte_brut', 'koenigin_gesehen', 'schwarmzellen', 'spielnaepfchen', 'honigraum_vorhanden'];

        if ($action === 'list') {
            $volkId = (int)($_GET['volk_id'] ?? 0);
            $sql = 'SELECT d.*, v.bezeichnung AS volk_bezeichnung FROM durchsichten d JOIN voelker v ON v.id=d.volk_id';
            $params = [];
            if ($volkId) { $sql .= ' WHERE d.volk_id = :vid'; $params['vid'] = $volkId; }
            $sql .= ' ORDER BY d.datum DESC, d.id DESC LIMIT 500';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            json_ok($stmt->fetchAll());
        }
        if ($action === 'get') {
            $id = param_id();
            $stmt = $pdo->prepare('SELECT * FROM durchsichten WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) json_error('Durchsicht nicht gefunden.', 404);
            json_ok($row);
        }
        if ($action === 'create' && $method === 'POST') {
            $volkId = (int)($input['volk_id'] ?? 0);
            $datum = trim($input['datum'] ?? '');
            if (!$volkId || $datum === '') json_error('Volk und Datum sind erforderlich.');

            // Automatisches Wetter, sofern nicht manuell übergeben
            if (empty($input['wetter_temp_c']) && empty($input['wetter_no_auto'])) {
                $stmt = $pdo->prepare('SELECT s.lat, s.lon FROM voelker v JOIN standorte s ON s.id=v.standort_id WHERE v.id=:id');
                $stmt->execute(['id' => $volkId]);
                $loc = $stmt->fetch();
                if ($loc && $loc['lat'] !== null && $loc['lon'] !== null) {
                    $w = fetch_weather_for_date((float)$loc['lat'], (float)$loc['lon'], $datum);
                    if ($w) {
                        $input['wetter_temp_c'] = $w['temp_c'];
                        $input['wetter_wind_kmh'] = $w['wind_kmh'];
                        $input['wetter_beschreibung'] = $w['beschreibung'];
                        $input['wetter_code'] = $w['code'];
                    }
                }
            }

            $cols = ['volk_id', 'created_by'];
            $placeholders = [':volk_id', ':created_by'];
            $params = ['volk_id' => $volkId, 'created_by' => $currentUser['id']];
            foreach ($fields as $f) {
                if ($f === 'volk_id') continue;
                $cols[] = $f;
                $placeholders[] = ":$f";
                $v = $input[$f] ?? null;
                if (in_array($f, $boolFields, true)) $v = $v === null ? 0 : (int)(bool)$v;
                $params[$f] = ($v === '' ? null : $v);
            }
            $sql = 'INSERT INTO durchsichten (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
            $pdo->prepare($sql)->execute($params);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            $id = param_id();
            $set = [];
            $params = ['id' => $id];
            foreach (array_merge(['volk_id'], $fields) as $f) {
                if (array_key_exists($f, $input)) {
                    $set[] = "$f = :$f";
                    $v = $input[$f];
                    if (in_array($f, $boolFields, true)) $v = (int)(bool)$v;
                    $params[$f] = ($v === '' ? null : $v);
                }
            }
            if (!$set) json_ok();
            $pdo->prepare('UPDATE durchsichten SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            $pdo->prepare('DELETE FROM durchsichten WHERE id = :id')->execute(['id' => $id]);
            json_ok();
        }
        if ($action === 'wetter_vorschau') {
            // Liefert Wetter für Volk+Datum, ohne zu speichern (für Live-Vorschau im Formular)
            $volkId = (int)($_GET['volk_id'] ?? 0);
            $datum = trim($_GET['datum'] ?? '');
            if (!$volkId || $datum === '') json_error('Volk und Datum erforderlich.');
            $stmt = $pdo->prepare('SELECT s.lat, s.lon, s.name FROM voelker v JOIN standorte s ON s.id=v.standort_id WHERE v.id=:id');
            $stmt->execute(['id' => $volkId]);
            $loc = $stmt->fetch();
            if (!$loc || $loc['lat'] === null) json_error('Für diesen Standort sind keine Koordinaten hinterlegt.');
            $w = fetch_weather_for_date((float)$loc['lat'], (float)$loc['lon'], $datum);
            if (!$w) json_error('Wetterdaten konnten nicht abgerufen werden.');
            json_ok($w);
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // FÜTTERUNGEN
    // ===========================================================
    case 'fuetterungen':
        if ($action === 'list') {
            $volkId = (int)($_GET['volk_id'] ?? 0);
            $sql = 'SELECT f.*, v.bezeichnung AS volk_bezeichnung FROM fuetterungen f JOIN voelker v ON v.id=f.volk_id';
            $params = [];
            if ($volkId) { $sql .= ' WHERE f.volk_id=:vid'; $params['vid'] = $volkId; }
            $sql .= ' ORDER BY f.datum DESC, f.id DESC LIMIT 500';
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            json_ok($stmt->fetchAll());
        }
        if ($action === 'create' && $method === 'POST') {
            $volkId = (int)($input['volk_id'] ?? 0);
            $datum = trim($input['datum'] ?? '');
            $art = trim($input['futterart'] ?? '');
            if (!$volkId || $datum === '' || $art === '') json_error('Volk, Datum und Futterart sind erforderlich.');
            $stmt = $pdo->prepare('INSERT INTO fuetterungen (volk_id, datum, futterart, menge, einheit, notizen, created_by)
                                    VALUES (:vid,:datum,:art,:menge,:einheit,:notizen,:uid)');
            $stmt->execute([
                'vid' => $volkId, 'datum' => $datum, 'art' => $art,
                'menge' => val($input, 'menge'), 'einheit' => val($input, 'einheit', 'l'),
                'notizen' => val($input, 'notizen'), 'uid' => $currentUser['id'],
            ]);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            $id = param_id();
            $set = []; $params = ['id' => $id];
            foreach (['volk_id', 'datum', 'futterart', 'menge', 'einheit', 'notizen'] as $f) {
                if (array_key_exists($f, $input)) { $set[] = "$f=:$f"; $params[$f] = ($input[$f] === '' ? null : $input[$f]); }
            }
            if (!$set) json_ok();
            $pdo->prepare('UPDATE fuetterungen SET ' . implode(',', $set) . ' WHERE id=:id')->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            $pdo->prepare('DELETE FROM fuetterungen WHERE id=:id')->execute(['id' => $id]);
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // BEHANDLUNGEN
    // ===========================================================
    case 'behandlungen':
        if ($action === 'list') {
            $volkId = (int)($_GET['volk_id'] ?? 0);
            $sql = 'SELECT b.*, v.bezeichnung AS volk_bezeichnung FROM behandlungen b JOIN voelker v ON v.id=b.volk_id';
            $params = [];
            if ($volkId) { $sql .= ' WHERE b.volk_id=:vid'; $params['vid'] = $volkId; }
            $sql .= ' ORDER BY b.datum DESC, b.id DESC LIMIT 500';
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            json_ok($stmt->fetchAll());
        }
        if ($action === 'create' && $method === 'POST') {
            $volkId = (int)($input['volk_id'] ?? 0);
            $datum = trim($input['datum'] ?? '');
            $mittel = trim($input['mittel'] ?? '');
            if (!$volkId || $datum === '' || $mittel === '') json_error('Volk, Datum und Mittel sind erforderlich.');
            $stmt = $pdo->prepare('INSERT INTO behandlungen (volk_id, datum, mittel, menge, einheit, methode, wartezeit_bis, erfolgskontrolle_datum, erfolgskontrolle_ergebnis, notizen, created_by)
                                    VALUES (:vid,:datum,:mittel,:menge,:einheit,:methode,:wartezeit,:ekdatum,:ekergebnis,:notizen,:uid)');
            $stmt->execute([
                'vid' => $volkId, 'datum' => $datum, 'mittel' => $mittel,
                'menge' => val($input, 'menge'), 'einheit' => val($input, 'einheit'),
                'methode' => val($input, 'methode'), 'wartezeit' => val($input, 'wartezeit_bis'),
                'ekdatum' => val($input, 'erfolgskontrolle_datum'), 'ekergebnis' => val($input, 'erfolgskontrolle_ergebnis'),
                'notizen' => val($input, 'notizen'), 'uid' => $currentUser['id'],
            ]);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            $id = param_id();
            $set = []; $params = ['id' => $id];
            foreach (['volk_id', 'datum', 'mittel', 'menge', 'einheit', 'methode', 'wartezeit_bis', 'erfolgskontrolle_datum', 'erfolgskontrolle_ergebnis', 'notizen'] as $f) {
                if (array_key_exists($f, $input)) { $set[] = "$f=:$f"; $params[$f] = ($input[$f] === '' ? null : $input[$f]); }
            }
            if (!$set) json_ok();
            $pdo->prepare('UPDATE behandlungen SET ' . implode(',', $set) . ' WHERE id=:id')->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            $pdo->prepare('DELETE FROM behandlungen WHERE id=:id')->execute(['id' => $id]);
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // ERNTE
    // ===========================================================
    case 'ernte':
        if ($action === 'list') {
            $volkId = (int)($_GET['volk_id'] ?? 0);
            $sql = 'SELECT e.*, v.bezeichnung AS volk_bezeichnung FROM ernte e JOIN voelker v ON v.id=e.volk_id';
            $params = [];
            if ($volkId) { $sql .= ' WHERE e.volk_id=:vid'; $params['vid'] = $volkId; }
            $sql .= ' ORDER BY e.datum DESC, e.id DESC LIMIT 500';
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            json_ok($stmt->fetchAll());
        }
        if ($action === 'create' && $method === 'POST') {
            $volkId = (int)($input['volk_id'] ?? 0);
            $datum = trim($input['datum'] ?? '');
            if (!$volkId || $datum === '') json_error('Volk und Datum sind erforderlich.');
            $stmt = $pdo->prepare('INSERT INTO ernte (volk_id, datum, honigsorte, menge_kg, wassergehalt, notizen, created_by)
                                    VALUES (:vid,:datum,:sorte,:menge,:wasser,:notizen,:uid)');
            $stmt->execute([
                'vid' => $volkId, 'datum' => $datum, 'sorte' => val($input, 'honigsorte'),
                'menge' => val($input, 'menge_kg'), 'wasser' => val($input, 'wassergehalt'),
                'notizen' => val($input, 'notizen'), 'uid' => $currentUser['id'],
            ]);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'update' && $method === 'PUT') {
            $id = param_id();
            $set = []; $params = ['id' => $id];
            foreach (['volk_id', 'datum', 'honigsorte', 'menge_kg', 'wassergehalt', 'notizen'] as $f) {
                if (array_key_exists($f, $input)) { $set[] = "$f=:$f"; $params[$f] = ($input[$f] === '' ? null : $input[$f]); }
            }
            if (!$set) json_ok();
            $pdo->prepare('UPDATE ernte SET ' . implode(',', $set) . ' WHERE id=:id')->execute($params);
            json_ok();
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            $pdo->prepare('DELETE FROM ernte WHERE id=:id')->execute(['id' => $id]);
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // AUFGABEN
    // ===========================================================
    case 'aufgaben':
        if ($action === 'list') {
            $showDone = (int)($_GET['zeige_erledigte'] ?? 0);
            $sql = 'SELECT a.*, v.bezeichnung AS volk_bezeichnung, s.name AS standort_name
                    FROM aufgaben a
                    LEFT JOIN voelker v ON v.id=a.volk_id
                    LEFT JOIN standorte s ON s.id=a.standort_id';
            if (!$showDone) $sql .= ' WHERE a.erledigt = 0';
            $sql .= ' ORDER BY a.erledigt ASC, a.faelligkeit IS NULL, a.faelligkeit ASC';
            json_ok($pdo->query($sql)->fetchAll());
        }
        if ($action === 'create' && $method === 'POST') {
            $titel = trim($input['titel'] ?? '');
            if ($titel === '') json_error('Titel ist erforderlich.');
            $stmt = $pdo->prepare('INSERT INTO aufgaben (volk_id, standort_id, titel, faelligkeit, notizen, created_by)
                                    VALUES (:vid,:sid,:titel,:faelligkeit,:notizen,:uid)');
            $stmt->execute([
                'vid' => val($input, 'volk_id') ?: null,
                'sid' => val($input, 'standort_id') ?: null,
                'titel' => $titel,
                'faelligkeit' => val($input, 'faelligkeit'),
                'notizen' => val($input, 'notizen'),
                'uid' => $currentUser['id'],
            ]);
            json_ok(['id' => $pdo->lastInsertId()]);
        }
        if ($action === 'toggle' && $method === 'PUT') {
            $id = param_id();
            $stmt = $pdo->prepare('SELECT erledigt FROM aufgaben WHERE id=:id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) json_error('Aufgabe nicht gefunden.', 404);
            $newVal = $row['erledigt'] ? 0 : 1;
            $pdo->prepare('UPDATE aufgaben SET erledigt=:e, erledigt_am=:eam WHERE id=:id')->execute([
                'e' => $newVal, 'eam' => $newVal ? date('Y-m-d H:i:s') : null, 'id' => $id,
            ]);
            json_ok(['erledigt' => (bool)$newVal]);
        }
        if ($action === 'delete' && $method === 'DELETE') {
            $id = param_id();
            $pdo->prepare('DELETE FROM aufgaben WHERE id=:id')->execute(['id' => $id]);
            json_ok();
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    // ===========================================================
    // DASHBOARD
    // ===========================================================
    case 'dashboard':
        if ($action === 'stats') {
            $stats = [];
            $stats['anzahl_standorte'] = (int)$pdo->query('SELECT COUNT(*) c FROM standorte')->fetch()['c'];
            $stats['anzahl_voelker'] = (int)$pdo->query("SELECT COUNT(*) c FROM voelker WHERE status='aktiv'")->fetch()['c'];
            $stats['anzahl_offene_aufgaben'] = (int)$pdo->query('SELECT COUNT(*) c FROM aufgaben WHERE erledigt=0')->fetch()['c'];
            $stats['ernte_jahr_kg'] = (float)$pdo->query('SELECT COALESCE(SUM(menge_kg),0) s FROM ernte WHERE YEAR(datum)=YEAR(CURDATE())')->fetch()['s'];
            $stats['letzte_durchsichten'] = $pdo->query('SELECT d.id, d.datum, d.weiselrichtig, v.bezeichnung AS volk_bezeichnung, s.name AS standort_name
                FROM durchsichten d JOIN voelker v ON v.id=d.volk_id JOIN standorte s ON s.id=v.standort_id
                ORDER BY d.datum DESC, d.id DESC LIMIT 8')->fetchAll();
            $stats['naechste_aufgaben'] = $pdo->query('SELECT id, titel, faelligkeit FROM aufgaben WHERE erledigt=0 ORDER BY faelligkeit IS NULL, faelligkeit ASC LIMIT 6')->fetchAll();
            $stats['voelker_ohne_durchsicht_30d'] = $pdo->query("SELECT v.id, v.bezeichnung, s.name AS standort_name,
                    (SELECT MAX(datum) FROM durchsichten d WHERE d.volk_id=v.id) AS letzte_durchsicht
                FROM voelker v JOIN standorte s ON s.id=v.standort_id
                WHERE v.status='aktiv'
                HAVING letzte_durchsicht IS NULL OR letzte_durchsicht < DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchAll();
            json_ok($stats);
        }
        json_error('Unbekannte Aktion.', 404);
        break;

    default:
        json_error('Unbekannte Ressource.', 404);
}
