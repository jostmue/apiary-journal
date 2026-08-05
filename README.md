# 🐝 Imkerei-Tagebuch

Eine vollständige Web-App zur Verwaltung von Bienenständen und -völkern:
Standorte, Völker (Rasse, Beute, Königin, Status), Durchsichten/Stockkarte
(mit **automatischem Wetter-Eintrag**), Fütterungen, Behandlungen (z.B.
Varroa), Ernte sowie Aufgaben/Erinnerungen. Mit Benutzerverwaltung
(Admin/Imker-Rollen).

Technik: **PHP 8 + MariaDB**, läuft nativ über die Synology-Pakete
**Web Station** und **MariaDB 10**. Kein Node.js, kein Build-Prozess –
einfach hochladen und loslegen.

---

## 1. Voraussetzungen auf dem Synology NAS (DSM)

Installiere im **Paketzentrum** folgende Pakete (falls noch nicht vorhanden):

1. **Web Station** – stellt Apache/nginx + PHP bereit
2. **MariaDB 10** (oder neuer) – die Datenbank
3. **phpMyAdmin** – komfortabel, um die Datenbank per Weboberfläche anzulegen
   (alternativ SSH-Zugriff nutzen)

Nach der Installation von Web Station: Öffne Web Station → **PHP-Einstellungen**
und stelle sicher, dass mindestens ein **PHP 8.x-Profil** aktiviert ist, mit
den Erweiterungen `pdo_mysql`, `mbstring`, `openssl` (in DSM standardmäßig
aktiv).

---

## 2. Datenbank anlegen

1. Öffne **phpMyAdmin** im DSM.
2. Neue Datenbank erstellen: Name z.B. `imkerei`, Zeichensatz `utf8mb4_general_ci`.
3. Neuen Datenbank-Benutzer anlegen (nicht `root` verwenden!), z.B.
   `imkerei_app` mit einem starken Passwort, und ihm **alle Rechte** auf die
   Datenbank `imkerei` geben.
4. Tabelle „Importieren" wählen und die Datei `sql/schema.sql` aus diesem
   Projekt hochladen. Das legt alle Tabellen an und erstellt einen
   Standard-Admin-Benutzer:
   - **Benutzername:** `admin`
   - **Passwort:** `admin123`

   ⚠️ **Bitte dieses Passwort direkt nach dem ersten Login über die
   Benutzerverwaltung in der App ändern!**

---

## 3. App-Dateien auf das NAS hochladen

1. Erstelle im **Web Station**-Bereich einen neuen virtuellen Host bzw.
   nutze den Standard-Webordner `web` (Datei-Station → `web`-Freigabe).
   Empfehlung: eigenen Unterordner anlegen, z.B. `web/imkerei`.
2. Lade den **kompletten Inhalt** dieses Projekt-Ordners (alle Dateien und
   Unterordner: `api.php`, `index.php`, `login.php`, `assets/`, `config/`,
   `includes/`, `sql/`, `.htaccess`) per Datei-Station oder FTP/SFTP dorthin
   hoch.
3. Kopiere `config/config.sample.php` zu `config/config.php` (z.B. über
   die Datei-Station: Datei duplizieren, dann umbenennen) und trage dort ein:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_PORT', '3306');
   define('DB_NAME', 'imkerei');
   define('DB_USER', 'imkerei_app');
   define('DB_PASS', 'DEIN_DB_PASSWORT');
   define('APP_SECRET', 'ein_zufaelliger_langer_string');
   ```

   Einen zufälligen `APP_SECRET`-String kannst du z.B. so erzeugen: über
   SSH auf dem NAS `openssl rand -hex 32` ausführen, oder einfach eine
   lange zufällige Zeichenkette selbst eintippen.

4. **Wichtig (Berechtigungen):** Stelle sicher, dass der Webserver-Nutzer
   (i.d.R. `http`) Lesezugriff auf alle Dateien hat. Das ist bei
   Standard-Uploads über die Datei-Station normalerweise automatisch der Fall.

---

## 4. Virtuellen Host / Portfreigabe einrichten

In **Web Station → Web-Dienstportal**:

- Lege einen neuen "Web Portal"-Eintrag an (Typ: Name-basiert oder
  Port-basiert), der auf den Ordner `web/imkerei` zeigt.
- Wähle als Backend-Server **PHP 8.x**.
- Aktiviere für den Host `.htaccess`-Unterstützung (Apache) bzw. stelle bei
  nginx sicher, dass Zugriff auf `config/`, `includes/`, `sql/` gesperrt ist
  (die mitgelieferten `.htaccess`-Dateien greifen automatisch bei Apache).

Danach ist die App erreichbar unter z.B.:
`http://<NAS-IP>:<Port>/login.php`

### HTTPS empfohlen
Richte über **Systemsteuerung → Sicherheit → Zertifikat** ein Let's-Encrypt-
Zertifikat ein und leite den Zugriff über einen **Reverse Proxy** (DSM →
Anmeldeportal → Erweitert → Reverse-Proxy) auf HTTPS um. Setze danach in
`config/config.php`:

```php
define('SESSION_SECURE_COOKIE', true);
```

---

## 5. Erste Anmeldung

1. Rufe `https://deine-domain-oder-ip/login.php` auf.
2. Melde dich mit `admin` / `admin123` an.
3. Gehe sofort zu **Benutzer** → Administrator bearbeiten → neues Passwort
   vergeben.
4. Lege deine **Standorte** an (Adresse eingeben und auf „Koordinaten
   automatisch ermitteln" klicken – das befüllt Lat/Lon automatisch über
   die kostenlose Open-Meteo-Geokodierung, ohne API-Key).
5. Lege deine **Völker** an den Standorten an.
6. Bei jeder neuen **Durchsicht** wird das Wetter für den gewählten Tag und
   Standort automatisch abgerufen und eingetragen (nutzbar/überschreibbar).

---

## 6. Funktionsüberblick

| Bereich | Enthält |
|---|---|
| **Standorte** | Name, Adresse, Koordinaten (für Wetter), Trachtangebot, Notizen |
| **Völker** | Bezeichnung, Standort, Rasse, Beutentyp, Zargenzahl, Herkunft, Status, Königin (Jahr, Herkunft, Zeichenfarbe/gezeichnet) |
| **Durchsichten** | Datum/Uhrzeit, **automatisches Wetter**, Brutstadien, Volksstärke, Weiselrichtigkeit, Schwarmzellen, Varroa-Einschätzung, Krankheitsanzeichen, Sanftmut/Wabensitz/Stechlust (Bewertungsskalen), Maßnahmen, Notizen |
| **Fütterungen** | Datum, Futterart, Menge/Einheit, Notizen |
| **Behandlungen** | Datum, Mittel, Menge/Methode, Wartezeit, Erfolgskontrolle |
| **Ernte** | Datum, Sorte, Menge (kg), Wassergehalt |
| **Aufgaben** | Freie Erinnerungen mit Fälligkeitsdatum, optional an Volk/Standort geknüpft |
| **Benutzer** | Rollen Admin/Imker, Konten aktivieren/deaktivieren, Passwörter zurücksetzen |
| **Dashboard** | Kennzahlen, letzte Durchsichten, offene Aufgaben, Völker ohne Kontrolle seit &gt;30 Tagen |

Alle Datum-Felder sind native `<input type="date">`-Kalenderfelder, alle
Kategorien (Rasse, Beutentyp, Futterart, Behandlungsmittel, ...) sind
Auswahllisten – kann bei Bedarf leicht in `assets/js/app.js` (oben, Konstanten
`RASSEN`, `BEUTENTYPEN`, `FUTTERARTEN`, `BEHANDLUNGSMITTEL`, ...) erweitert
werden.

---

## 7. Wetterdaten

Die App nutzt die kostenlose **Open-Meteo API** (kein API-Key nötig,
https://open-meteo.com). Für aktuelle/kommende Tage sowie kurz
zurückliegende Tage wird die Vorhersage-API genutzt, für länger
zurückliegende Termine automatisch die Archiv-API. Voraussetzung ist, dass
der Standort über Koordinaten (Lat/Lon) verfügt – diese werden beim Anlegen
eines Standorts per Adress-Eingabe automatisch ermittelt.

Das NAS benötigt dafür eine ausgehende Internetverbindung auf Port 443.

---

## 8. Datensicherung

Da alle Daten in MariaDB liegen, reicht ein regelmäßiger Export über
**phpMyAdmin** (Export → SQL) oder – komfortabler – die Einrichtung eines
**Hyper Backup**-Jobs, der den MariaDB-Datenordner des Pakets sichert.
Zusätzlich empfiehlt sich, den App-Ordner (insbesondere `config/config.php`)
in die Sicherung einzubeziehen.

---

## 9. Struktur des Projekts

```
imker-tagebuch/
├── api.php                  # Zentrale REST-API (alle Ressourcen)
├── index.php                # App-Oberfläche (nach Login)
├── login.php                # Login-Seite
├── .htaccess
├── config/
│   ├── config.sample.php    # Vorlage – nach config.php kopieren & anpassen
│   └── .htaccess            # sperrt direkten Web-Zugriff
├── includes/
│   ├── config_loader.php
│   ├── db.php                # PDO-Verbindung
│   ├── auth.php               # Login/Session/CSRF
│   └── weather.php            # Open-Meteo Anbindung
├── sql/
│   └── schema.sql            # Datenbankschema + Standard-Admin
└── assets/
    ├── css/style.css
    └── js/app.js              # komplette Frontend-Logik (SPA)
```

---

## 10. Erweiterungsideen

Die Datenbank ist so ausgelegt, dass sich leicht weitere Module ergänzen
lassen, z.B.:
- Ablegerbildung / Königinnenzucht als eigene Tabelle
- Foto-Uploads zu Durchsichten
- Export als PDF/CSV (Jahresbericht je Volk)
- Push-Erinnerungen für fällige Aufgaben per E-Mail (z.B. über PHP `mail()`
  und einen Cron-Job in der Synology-Aufgabenplanung)

Bei Bedarf einfach die entsprechende Tabelle in `sql/schema.sql`, den
passenden `case`-Block in `api.php` sowie die View/Formulare in
`assets/js/app.js` ergänzen – das bestehende Muster (Standorte/Völker/...)
lässt sich 1:1 übertragen.
