-- ============================================================
-- Imkerei-Tagebuch – Datenbankschema (MariaDB / MySQL)
-- ============================================================
-- Import z.B. über phpMyAdmin (Synology Paket "phpMyAdmin")
-- oder per Kommandozeile:
--   mysql -u root -p imkerei < schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Benutzerverwaltung
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    role            ENUM('admin','imker') NOT NULL DEFAULT 'imker',
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login      DATETIME     DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Standard-Login: Benutzer "admin" / Passwort "admin123"
-- (BITTE SOFORT NACH DER ERSTEN ANMELDUNG DAS PASSWORT ÄNDERN! -> Benutzerverwaltung in der App)
INSERT INTO users (username, password_hash, name, email, role)
VALUES ('admin', '$2b$10$STW0xBXCll4N/hMMDs7nnOv8KvqZZiWb4vRgJ79mVI2uTzxZ844qS', 'Administrator', 'admin@example.com', 'admin')
ON DUPLICATE KEY UPDATE username=username;

-- ------------------------------------------------------------
-- Standorte
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS standorte (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    adresse         VARCHAR(255) DEFAULT NULL,
    plz             VARCHAR(10)  DEFAULT NULL,
    ort             VARCHAR(100) DEFAULT NULL,
    lat             DECIMAL(10,7) DEFAULT NULL,
    lon             DECIMAL(10,7) DEFAULT NULL,
    flaeche_info    VARCHAR(255) DEFAULT NULL,   -- z.B. Trachtangebot, Umgebung
    pachtvertrag    VARCHAR(255) DEFAULT NULL,   -- Ansprechpartner / Pacht Infos
    notizen         TEXT DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Völker
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voelker (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    standort_id         INT UNSIGNED NOT NULL,
    bezeichnung         VARCHAR(120) NOT NULL,          -- z.B. "Volk 3" / Stockkartennummer
    rasse               VARCHAR(60)  DEFAULT NULL,       -- Carnica, Buckfast, Ligustica, Mellifera, ...
    beutentyp           VARCHAR(60)  DEFAULT NULL,       -- Dadant, Zander, Deutsch Normal, Langstroth, ...
    anzahl_zargen       TINYINT UNSIGNED DEFAULT NULL,
    herkunft            VARCHAR(60)  DEFAULT NULL,       -- Schwarm, Kauf, Ableger, Teilung
    gruendungsdatum     DATE DEFAULT NULL,
    koenigin_jahr       YEAR DEFAULT NULL,
    koenigin_herkunft   VARCHAR(120) DEFAULT NULL,
    koenigin_gezeichnet TINYINT(1) DEFAULT 0,
    koenigin_farbe      VARCHAR(30) DEFAULT NULL,        -- Zeichenfarbe nach Jahresfarbe
    status              ENUM('aktiv','ueberwintert','aufgeloest','verkauft','verloren') NOT NULL DEFAULT 'aktiv',
    notizen             TEXT DEFAULT NULL,
    created_by          INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (standort_id) REFERENCES standorte(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Durchsichten (Stockkarte / Inspektionen)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS durchsichten (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volk_id                 INT UNSIGNED NOT NULL,
    datum                   DATE NOT NULL,
    uhrzeit                 TIME DEFAULT NULL,

    -- Wetter (automatisch befüllt, überschreibbar)
    wetter_temp_c           DECIMAL(4,1) DEFAULT NULL,
    wetter_wind_kmh         DECIMAL(5,1) DEFAULT NULL,
    wetter_beschreibung     VARCHAR(120) DEFAULT NULL,
    wetter_code             SMALLINT DEFAULT NULL,

    -- Brut & Volk
    stifte_vorhanden        TINYINT(1) DEFAULT NULL,
    offene_brut             TINYINT(1) DEFAULT NULL,
    verdeckelte_brut        TINYINT(1) DEFAULT NULL,
    brutwaben_anzahl        DECIMAL(3,1) DEFAULT NULL,
    futterwaben_anzahl      DECIMAL(3,1) DEFAULT NULL,
    volksstaerke_waben      DECIMAL(3,1) DEFAULT NULL,     -- besetzte Waben
    koenigin_gesehen        TINYINT(1) DEFAULT NULL,
    weiselrichtig           ENUM('ja','nein','unsicher') DEFAULT 'ja',
    schwarmzellen            TINYINT(1) DEFAULT 0,
    spielnaepfchen           TINYINT(1) DEFAULT 0,
    honigraum_vorhanden      TINYINT(1) DEFAULT 0,

    -- Gesundheit / Verhalten
    varroa_befall            ENUM('keiner','gering','mittel','hoch','unbekannt') DEFAULT 'unbekannt',
    varroa_anzahl_gemuell    INT DEFAULT NULL,             -- Gemülldiagnose (Anzahl Milben)
    krankheitsanzeichen      VARCHAR(255) DEFAULT NULL,    -- Freitext / Tags
    sanftmut                 TINYINT UNSIGNED DEFAULT NULL, -- 1-5
    wabensitz                TINYINT UNSIGNED DEFAULT NULL, -- 1-5
    stechlust                 TINYINT UNSIGNED DEFAULT NULL, -- 1-5

    massnahmen                TEXT DEFAULT NULL,           -- durchgeführte Maßnahmen
    notizen                   TEXT DEFAULT NULL,

    created_by                INT UNSIGNED DEFAULT NULL,
    created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (volk_id) REFERENCES voelker(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_durchsicht_volk_datum (volk_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Fütterungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fuetterungen (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volk_id         INT UNSIGNED NOT NULL,
    datum           DATE NOT NULL,
    futterart       VARCHAR(80) NOT NULL,   -- Zuckerwasser 3:2, Futterteig, Invertzuckersirup, ...
    menge           DECIMAL(6,2) DEFAULT NULL,
    einheit         ENUM('l','kg','ml','g') DEFAULT 'l',
    notizen         TEXT DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (volk_id) REFERENCES voelker(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_fuetterung_volk_datum (volk_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Behandlungen (z.B. Varroabehandlung)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS behandlungen (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volk_id             INT UNSIGNED NOT NULL,
    datum               DATE NOT NULL,
    mittel              VARCHAR(100) NOT NULL,   -- Ameisensäure, Oxalsäure, Milchsäure, Thymol...
    menge               DECIMAL(6,2) DEFAULT NULL,
    einheit             VARCHAR(20) DEFAULT NULL,
    methode             VARCHAR(80) DEFAULT NULL, -- Verdunster, Träufeln, Sprühen, Streifen
    wartezeit_bis       DATE DEFAULT NULL,
    erfolgskontrolle_datum DATE DEFAULT NULL,
    erfolgskontrolle_ergebnis VARCHAR(255) DEFAULT NULL,
    notizen             TEXT DEFAULT NULL,
    created_by          INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (volk_id) REFERENCES voelker(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_behandlung_volk_datum (volk_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Ernte
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ernte (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volk_id         INT UNSIGNED NOT NULL,
    datum           DATE NOT NULL,
    honigsorte      VARCHAR(100) DEFAULT NULL,   -- Frühtracht, Sommertracht, Waldhonig, ...
    menge_kg        DECIMAL(6,2) DEFAULT NULL,
    wassergehalt    DECIMAL(4,1) DEFAULT NULL,
    notizen         TEXT DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (volk_id) REFERENCES voelker(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ernte_volk_datum (volk_id, datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Aufgaben / Erinnerungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aufgaben (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    volk_id         INT UNSIGNED DEFAULT NULL,       -- optional, kann auch allgemein sein
    standort_id     INT UNSIGNED DEFAULT NULL,
    titel           VARCHAR(150) NOT NULL,
    faelligkeit     DATE DEFAULT NULL,
    erledigt        TINYINT(1) NOT NULL DEFAULT 0,
    erledigt_am     DATETIME DEFAULT NULL,
    notizen         TEXT DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (volk_id) REFERENCES voelker(id) ON DELETE CASCADE,
    FOREIGN KEY (standort_id) REFERENCES standorte(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
