-- ---------------------------------------------------------------------------
-- Apiary-Journal - database schema (MariaDB 10.x / MySQL 8, schema version 2)
-- Charset: utf8mb4 so that notes may contain any unicode character.
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(60)  NOT NULL,
  full_name      VARCHAR(120) NULL,
  email          VARCHAR(160) NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin','beekeeper','viewer') NOT NULL DEFAULT 'beekeeper',
  locale         ENUM('de','en') NOT NULL DEFAULT 'de',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS apiaries (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(120) NOT NULL,
  code          VARCHAR(30)  NULL,
  address       VARCHAR(255) NULL,
  latitude      DECIMAL(9,6) NULL,
  longitude     DECIMAL(9,6) NULL,
  altitude      INT          NULL,
  forage_notes  TEXT         NULL,
  description   TEXT         NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_apiaries_active (is_active),
  CONSTRAINT fk_apiaries_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS colonies (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  apiary_id         INT UNSIGNED NOT NULL,
  name              VARCHAR(120) NOT NULL,
  tag_number        VARCHAR(30)  NULL,
  race              VARCHAR(40)  NULL,
  origin            VARCHAR(40)  NULL,
  hive_type         VARCHAR(40)  NULL,
  frame_size        VARCHAR(40)  NULL,
  box_count         TINYINT UNSIGNED NULL,
  established_on    DATE         NULL,
  status            VARCHAR(20)  NOT NULL DEFAULT 'active',
  parent_colony_id  INT UNSIGNED NULL,
  notes             TEXT         NULL,
  created_by        INT UNSIGNED NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_colonies_apiary (apiary_id),
  KEY ix_colonies_status (status),
  CONSTRAINT fk_colonies_apiary FOREIGN KEY (apiary_id) REFERENCES apiaries(id) ON DELETE CASCADE,
  CONSTRAINT fk_colonies_parent FOREIGN KEY (parent_colony_id) REFERENCES colonies(id) ON DELETE SET NULL,
  CONSTRAINT fk_colonies_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS queens (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  colony_id      INT UNSIGNED NOT NULL,
  name           VARCHAR(120) NULL,
  race           VARCHAR(40)  NULL,
  birth_year     SMALLINT     NULL,
  marking_color  VARCHAR(20)  NULL,
  mating_type    VARCHAR(30)  NULL,
  breeder        VARCHAR(120) NULL,
  origin         VARCHAR(40)  NULL,
  introduced_on  DATE         NULL,
  removed_on     DATE         NULL,
  is_clipped     TINYINT(1)   NOT NULL DEFAULT 0,
  is_current     TINYINT(1)   NOT NULL DEFAULT 1,
  notes          TEXT         NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_queens_colony (colony_id),
  CONSTRAINT fk_queens_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inspections (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  colony_id         INT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED NULL,
  inspected_at      DATETIME     NOT NULL,
  duration_min      SMALLINT     NULL,
  temperament       TINYINT      NULL,   -- 1 = very calm ... 5 = aggressive
  strength_frames   DECIMAL(4,1) NULL,   -- frames covered with bees
  brood_frames      DECIMAL(4,1) NULL,
  eggs_seen         TINYINT(1)   NULL,
  larvae_seen       TINYINT(1)   NULL,
  capped_brood_seen TINYINT(1)   NULL,
  queen_seen        TINYINT(1)   NULL,
  queen_cell_type   VARCHAR(20)  NULL,   -- none/play/swarm/supersedure/emergency
  queen_cell_count  SMALLINT     NULL,
  drone_brood       TINYINT(1)   NULL,
  stores_kg         DECIMAL(5,1) NULL,
  supers_count      TINYINT      NULL,
  space_action      VARCHAR(30)  NULL,
  varroa_count      SMALLINT     NULL,
  varroa_method     VARCHAR(30)  NULL,
  varroa_days       SMALLINT     NULL,
  health_status     VARCHAR(30)  NULL,
  swarm_risk        TINYINT(1)   NULL,
  hive_weight_kg    DECIMAL(6,2) NULL,
  weather_temp      DECIMAL(5,1) NULL,
  weather_humidity  SMALLINT     NULL,
  weather_wind      DECIMAL(5,1) NULL,
  weather_wind_dir  SMALLINT     NULL,
  weather_cloud     SMALLINT     NULL,
  weather_precip    DECIMAL(5,2) NULL,
  weather_pressure  DECIMAL(6,1) NULL,
  weather_code      SMALLINT     NULL,
  weather_source    VARCHAR(20)  NULL,
  notes             TEXT         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_insp_colony_date (colony_id, inspected_at),
  CONSTRAINT fk_insp_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE,
  CONSTRAINT fk_insp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedings (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  colony_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  fed_at      DATETIME     NOT NULL,
  feed_type   VARCHAR(30)  NOT NULL,
  amount      DECIMAL(7,2) NULL,
  unit        VARCHAR(10)  NULL,
  notes       TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_feed_colony_date (colony_id, fed_at),
  CONSTRAINT fk_feed_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE,
  CONSTRAINT fk_feed_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS treatments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  colony_id         INT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED NULL,
  started_at        DATE         NOT NULL,
  ended_at          DATE         NULL,
  target            VARCHAR(30)  NULL,
  product           VARCHAR(120) NULL,
  active_substance  VARCHAR(120) NULL,
  dose              DECIMAL(8,2) NULL,
  unit              VARCHAR(10)  NULL,
  method            VARCHAR(40)  NULL,
  temperature_c     DECIMAL(5,1) NULL,
  batch_no          VARCHAR(60)  NULL,
  withdrawal_until  DATE         NULL,
  notes             TEXT         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_treat_colony_date (colony_id, started_at),
  CONSTRAINT fk_treat_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE,
  CONSTRAINT fk_treat_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS harvests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  colony_id      INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NULL,
  harvested_at   DATE         NOT NULL,
  honey_type     VARCHAR(60)  NULL,
  frames_count   SMALLINT     NULL,
  gross_kg       DECIMAL(7,2) NULL,
  net_kg         DECIMAL(7,2) NULL,
  water_content  DECIMAL(4,1) NULL,
  jars_count     SMALLINT     NULL,
  batch_no       VARCHAR(60)  NULL,
  notes          TEXT         NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_harv_colony_date (colony_id, harvested_at),
  CONSTRAINT fk_harv_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE,
  CONSTRAINT fk_harv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  colony_id   INT UNSIGNED NULL,
  apiary_id   INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  event_at    DATETIME     NOT NULL,
  event_type  VARCHAR(40)  NOT NULL,
  title       VARCHAR(160) NULL,
  notes       TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_event_colony_date (colony_id, event_at),
  CONSTRAINT fk_event_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_apiary FOREIGN KEY (apiary_id) REFERENCES apiaries(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  apiary_id    INT UNSIGNED NULL,
  colony_id    INT UNSIGNED NULL,
  user_id      INT UNSIGNED NULL,   -- assignee
  title        VARCHAR(160) NOT NULL,
  description  TEXT         NULL,
  due_date     DATE         NULL,
  priority     VARCHAR(10)  NOT NULL DEFAULT 'normal',
  status       VARCHAR(10)  NOT NULL DEFAULT 'open',
  done_at      DATETIME     NULL,
  created_by   INT UNSIGNED NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_tasks_status (status, due_date),
  CONSTRAINT fk_tasks_colony FOREIGN KEY (colony_id) REFERENCES colonies(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_apiary FOREIGN KEY (apiary_id) REFERENCES apiaries(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS weather_cache (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lat         DECIMAL(8,3) NOT NULL,
  lon         DECIMAL(8,3) NOT NULL,
  obs_date    DATE         NOT NULL,
  payload     MEDIUMTEXT   NOT NULL,
  fetched_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_weather (lat, lon, obs_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(40)  NOT NULL,
  entity      VARCHAR(40)  NULL,
  entity_id   INT UNSIGNED NULL,
  detail      VARCHAR(255) NULL,
  ip          VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k  VARCHAR(60) NOT NULL,
  v  TEXT        NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password reset tokens. Only the hash is kept, so a leaked row cannot be
-- turned back into a working link.
CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  created_ip  VARCHAR(45)  NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  KEY ix_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which migration steps this schema already contains. api/lib/migrate.php
-- compares it against SCHEMA_VERSION and applies whatever is missing.
INSERT INTO settings (k, v) VALUES ('db_version', '2')
  ON DUPLICATE KEY UPDATE v = VALUES(v);

SET FOREIGN_KEY_CHECKS = 1;

-- No default account is created here on purpose. The first administrator is
-- created interactively by /install.php, which stores a bcrypt password hash.

INSERT INTO settings (k, v) VALUES ('schema_version', '1')
ON DUPLICATE KEY UPDATE v = VALUES(v);
