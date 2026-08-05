# Beekeeping Journal

A self-hosted beekeeping record book for a Synology NAS. It keeps track of
apiaries, colonies and queens, and documents everything you do at the hive:
inspections, feedings, treatments, harvests, events and open tasks. Weather at
the apiary is filled in automatically for every entry, the interface speaks
German and English, and reports can be filtered down to a single colony and a
single season.

Runs on **Web Station (PHP)** and **MariaDB 10**, both from the DSM Package
Center. No build step, no external service, no API key.

---

## Features

**Colonies and apiaries**
- Apiaries with address, coordinates, elevation and forage notes. Coordinates
  can be looked up by place name.
- Colonies with number, race, origin, hive type, frame size, box count,
  establishment date, status and parent colony (so splits stay traceable).
- Queen records per colony: birth year, marking colour, mating type, breeder,
  introduction and removal dates, clipped wings. The colony card is colour
  coded with the international queen marking colour of the queen's year.

**Journal**
- **Inspections** – bees on frames, brood frames, temperament, queen seen,
  eggs/larvae/capped brood, queen cells and their type, drone brood, swarm
  mood, stores, supers, space given, varroa drop with counting method and
  period, health status, hive weight, notes, and the weather at that moment.
- **Feedings** – feed type, amount, unit.
- **Treatments** – target, product, active substance, dose, application
  method, temperature, batch number, withdrawal period.
- **Harvests** – honey type, frames, gross/net weight, water content, jars,
  batch number.
- **Events** – swarms, splits, merges, requeening, losses, migration,
  maintenance, wintering.
- **Tasks** – with due date, priority, assignee, per colony or apiary.

**Everything else**
- User management with three roles: administrator, beekeeper (write access),
  viewer (read only). Sessions, bcrypt password hashes, CSRF protection, audit
  log.
- Automatic weather per entry from Open-Meteo, using the coordinates of the
  apiary the colony stands on. Past entries use the weather archive, so a
  record you type in three weeks late still gets the right weather. Responses
  are cached per apiary and day.
- Two interface languages (German, English), switchable at any time and stored
  per user. Dates and times use native date pickers.
- Backup and restore from inside the app, plus a portable SQL export.
- Reports: pick record types, apiary, colony, user, date range and a text
  search; get a merged timeline with key figures, print it or export CSV.

## Requirements

| Component | Version | Source |
|-----------|---------|--------|
| DSM       | 7.x     | Synology |
| Web Station | current | Package Center |
| PHP       | 7.4 or newer (8.x recommended) | Package Center |
| MariaDB   | 10      | Package Center |

PHP extensions: `pdo_mysql`, `json`, and `curl` or `allow_url_fopen` for the
weather lookup. Everything else is core PHP.

## Installation

The full walkthrough with DSM screenshots-worth of detail is in
[docs/INSTALL_SYNOLOGY.md](docs/INSTALL_SYNOLOGY.md). Short version:

1. Install MariaDB 10, phpMyAdmin, Web Station and a PHP profile in the
   Package Center.
2. Create a database `beekeeping` and a database user with all privileges on it.
3. Copy this folder to `/volume1/web/beekeeping` (or any folder served by a
   Web Station web portal).
4. Make `api/` and `backups/` writable for the web server user (`http`).
5. Open `http://<nas>:<port>/install.php`, fill in the database credentials and
   create the first administrator.
6. **Delete `install.php`.** Then open `index.html` and sign in.

## Layout

```
index.html              application shell
install.php             one-time setup wizard (delete after use)
assets/css/app.css      stylesheet
assets/js/i18n.js       German and English strings
assets/js/schema.js     field, option and column definitions
assets/js/api.js        API client
assets/js/app.js        views, routing, forms
api/index.php           single API entry point (api/index.php?r=group/action)
api/config.php          created by the installer, never in version control
api/lib/core.php        config, PDO, JSON helpers
api/lib/auth.php        sessions, login, CSRF, roles
api/lib/entities.php    entity definitions and generic list/save/delete
api/lib/weather.php     Open-Meteo lookup and cache, place search
api/lib/reports.php     report engine, CSV export, dashboard figures
api/lib/backup.php      snapshots, restore, SQL export
api/lib/users.php       user management and profile
db/schema.sql           database schema
backups/                snapshot files (not served over HTTP)
```

## How the API works

Every call is `POST api/index.php?r=<group>/<action>` with a JSON body and
returns `{"ok":true,"data":…}` or `{"ok":false,"error":"<key>"}`. The error key
is translated in the browser, so the server never sends localised text. Three
routes stream files instead of JSON: `reports/csv`, `backup/download` and
`backup/sql`.

Writing requests need the CSRF token from `auth/me` in the `X-CSRF-Token`
header. Column names are whitelisted per entity in `api/lib/entities.php`;
anything not listed there cannot be written, whatever the client sends.

## Adding a field

1. Add the column to `db/schema.sql` (and to your live database).
2. Add it to the entity's `fields` list in `api/lib/entities.php`.
3. Add it to the form in `assets/js/schema.js`, and to `COLUMNS` if it should
   appear in the table.
4. Add `field.<column>` to both language blocks in `assets/js/i18n.js`.

## Backup

Two independent layers, use both:

- **In the app** (Backup page, administrators only): creates a compressed JSON
  snapshot of all tables in `backups/`. Restore replaces the data, after
  automatically taking a snapshot of the current state first. You can keep the
  existing user accounts while replacing journal data. The SQL export produces
  a dump for phpMyAdmin or the `mysql` client.
- **On the NAS**: Hyper Backup for the MariaDB database and for the web folder.
  The app-level snapshots do not replace a real off-device backup.

`backup_keep` in `api/config.php` limits how many snapshots are kept.

## Security notes

- Passwords are hashed with bcrypt (`password_hash`), sessions are HttpOnly and
  SameSite=Lax, and every writing request is CSRF-checked.
- Put the site behind HTTPS. On DSM, either use a reverse proxy with a Let's
  Encrypt certificate, or a certificate on the Web Station portal itself.
- Do not expose the NAS to the internet without a reverse proxy, and consider
  restricting the portal to your LAN or a VPN.
- Delete `install.php` after setup; it refuses to run once users exist, but it
  does not belong on a live system.
- Ideally move `backup_dir` to a path outside the web root, for example
  `/volume1/beekeeping-backups`.

## Weather data

Weather comes from [Open-Meteo](https://open-meteo.com/) (free, no key,
CC-BY 4.0 attribution). Entries up to six days old use the forecast/observation
endpoint, older ones the archive endpoint. Values land in the inspection record
and stay editable. If the NAS has no outbound internet access, set
`weather.enabled` to `false` in `api/config.php`; everything else keeps
working.

## Licence

MIT for this code. Open-Meteo data is licensed CC-BY 4.0.
