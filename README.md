# Apiary-Journal

A self-hosted beekeeping record book. It keeps track of
apiaries, colonies and queens, and documents everything you do at the hive:
inspections, feedings, treatments, harvests, events and open tasks. Weather at
the apiary is filled in automatically for every inspection, the interface
speaks German and English, and reports can be filtered down to a single colony
and a single season.

Runs on any web server with **PHP** and **MariaDB/MySQL** - including a
Synology NAS, which is what it was built for and where it is still tested.
No build step, no dependencies, no API key.

---

## Features

**Colonies and apiaries**
- Apiaries with address, coordinates, elevation and forage notes. Coordinates
  come from an address search (street, house number, postcode) or from
  clicking a map.
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
- Groups: share apiaries and colonies with other beekeepers, or keep them
  private. Roles apply per group (owner, member, viewer); members join by
  e-mail invitation. Administrators manage accounts, not other people's data.
- Sessions, bcrypt password hashes, CSRF protection, login rate limiting, an
  audit log, and a forgotten-password link when mail is configured.
- Automatic weather per inspection from Open-Meteo, using the coordinates of
  the apiary the colony stands on. Past entries use the weather archive, so a
  record you type in three weeks late still gets the right weather. Responses
  are cached per apiary and day.
- Two interface languages (German, English), switchable at any time and stored
  per user. Dates and times use native date pickers.
- Backup and restore from inside the app, plus a portable SQL export.
- Reports: pick record types, apiary, colony, user, date range and a text
  search; get a merged timeline with key figures, print it or export CSV.

## Requirements

| Component | Version |
|-----------|---------|
| PHP       | 7.4 or newer, 8.1+ recommended |
| MariaDB 10 or MySQL 8 | |
| A web server that runs PHP | Apache, nginx, or Synology Web Station |

On a Synology NAS all of it comes from the Package Center; see
[docs/INSTALL_SYNOLOGY.md](docs/INSTALL_SYNOLOGY.md).

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
api/lib/weather.php     Open-Meteo lookup and cache, address search
api/lib/reports.php     report engine and dashboard figures
api/lib/backup.php      snapshots, restore, SQL export
api/lib/users.php       user management and profile
api/lib/access.php      the single rule for who may see and change what
api/lib/groups.php      groups, members and invitations
api/lib/recovery.php    forgotten password: request a link, set a new password
api/lib/registration.php self-registration and address confirmation (open mode)
api/lib/account.php     export your own data, delete your own account
legal/                  placeholder terms and privacy pages
api/lib/mail.php        mail delivery, PHP mail() or a direct SMTP session
api/lib/migrate.php     schema version and migration steps
deploy/                 annotated nginx and Apache samples
tests/run.php           tests that need no server and no database
db/schema.sql           database schema
backups/                snapshot files (default location, see Backup)
```

## How the API works

Every call is `POST api/index.php?r=<group>/<action>` with a JSON body and
returns `{"ok":true,"data":…}` or `{"ok":false,"error":"<key>"}`. The error key
is translated in the browser, so the server never sends localised text. Two
routes answer with a file instead of JSON, `backup/download` and `backup/sql`;
they are POSTed like everything else, so the CSRF token never appears in a URL.
The CSV export is assembled in the browser from the rows already on screen,
which is what lets option values appear in the selected language.

Writing requests need the CSRF token from `auth/me` in the `X-CSRF-Token`
header. Column names are whitelisted per entity in `api/lib/entities.php`;
anything not listed there cannot be written, whatever the client sends.

## Adding a field

1. Add the column to `db/schema.sql` (and to your live database).
2. Add it to the entity's `fields` list in `api/lib/entities.php`.
3. Add it to the form in `assets/js/schema.js`, and to `COLUMNS` if it should
   appear in the table.
4. Add `field.<column>` to both language blocks in `assets/js/i18n.js`.
5. Bump the `?v=` marker on the asset URLs in `index.html`. Without it a
   browser can mix a fresh `app.js` with a cached `schema.js`, which fails
   with "… is not defined".

## Who sees what

Every apiary and every colony has an owner and, optionally, a group it is
shared with. Inspections, feedings, treatments, harvests and events take their
visibility from the colony they belong to, so there is one rule rather than one
per record type:

> You see a record if you own the apiary or colony it hangs off, or if that
> apiary or colony is shared with a group you belong to.

Anything not put into a group is private. That is the default for everything
you create.

Sharing is set separately on apiaries and on colonies, which is what a club
apiary needs: the site belongs to the group so everyone can find it, while the
colonies standing there stay with their individual keepers - or the other way
round.

**Roles are per group**, not per account:

| Role | May |
|------|-----|
| Owner | manage the group, invite and remove members, change roles |
| Member | create and change records in the group |
| Viewer | read only |

Your own records are always yours to change, whatever role you hold anywhere.
The only thing an account itself carries is whether it is an administrator -
and that is about managing accounts and the installation, **not** about seeing
data. An administrator has no access to other people's journals.

**Joining** happens by e-mail invitation, sent by a group owner. There is
deliberately no way to search for users: with private accounts a directory of
everyone registered here would defeat the point. An invitation reaches someone
without an account too.

**Leaving** takes nothing away. The apiaries and colonies you had shared become
private to you again, and the group stops seeing them immediately. Records
other members wrote at your colonies stay with those colonies, because they
belong to the colony rather than to whoever typed them. Deleting a group works
the same way: the records survive and turn private.

**Upgrading an existing installation** does not change what anyone can see. The
migration gives every existing row an owner and puts everything into one group
containing all existing accounts, so the journal looks exactly as it did.
Tightening things up is a decision you make afterwards, not one the update
makes for you.

## Running it on an ordinary web server

The app makes no assumptions beyond PHP and MySQL/MariaDB, and `deploy/` has
an annotated sample for nginx and for Apache. Three settings matter once it is
not just sitting on a LAN:

- **`security.trusted_proxies`** - behind a reverse proxy the connection to PHP
  is plain HTTP, so the session cookie would go out without the `Secure` flag
  and the rate limit would see every visitor as one address. List the proxy
  here and `X-Forwarded-Proto` and `X-Forwarded-For` are believed - but only
  from that address, because otherwise anyone could claim to be anyone.
- **`app.base_url`** - the address used to build password reset links. Left
  empty it is taken from the request, which means trusting the `Host` header;
  set it once the site is reachable from the internet.
- **`security.hsts`** - only after HTTPS works, since browsers remember it for
  a year.

`index.html` carries a Content-Security-Policy that limits scripts to this
origin. The page has no inline script, so an injected `<script>` or event
handler does not execute - worth knowing before you add one.

nginx ignores `.htaccess`, so on nginx the two bundled files protect nothing;
use the sample.

## Two operating modes

`app.mode` in `api/config.php` decides how people get an account:

- **`private`** (default) - an administrator creates accounts, there is no
  registration form, and the backup page offers full snapshots. This is the
  arrangement on a NAS at home, and it is what an upgrade leaves you with.
- **`open`** - anyone may register, confirms their address by e-mail and
  accepts the terms. Full snapshots disappear from the interface, because
  handing an administrator every user's data would quietly undo the rule that
  they see none of it; back the database up at server level instead.

Either way each user can export their own data and delete their account under
*My account*.

Running in open mode makes you a data controller under the GDPR. The pages in
`legal/` are placeholders carrying the outline of what this software actually
processes - replace them with your own texts before letting strangers in, or
point `app.terms_url` and `app.privacy_url` somewhere else.

## Forgotten password

Without a `mail` section the button is simply not shown, and an administrator
sets passwords instead - which is the sensible arrangement on a NAS at home.
Configure `mail` and users can request a link themselves. `transport` is either
`mail`, which needs a working MTA on the machine, or `smtp`, which talks to a
mailbox of your own and works anywhere with outbound access.

A link is valid for 60 minutes, works once, and asking again invalidates the
previous one. Only the hash of the token is stored. The reply is deliberately
the same whether or not an account matched, so the form cannot be used to find
out who has an account here.

## Working on the code

There is no build step: edit, reload, done. Two things are worth doing once
after cloning.

Bump the `?v=` marker on the asset URLs in `index.html` whenever you change a
file under `assets/` - without it a browser can pair a fresh `app.js` with a
cached `schema.js`, which fails with "… is not defined".

Enable the bundled hook so a commit with unparsable PHP is refused:

```bash
git config core.hooksPath .githooks
```

It runs `php -l` over the staged PHP files and needs the PHP CLI on PATH (or
at `C:\php\php.exe` on Windows). Without PHP installed it simply skips.

There are tests for the parts that are hard to check by clicking around - who
a request comes from, whether it is encrypted, value normalisation and mail
headers. They need neither a database nor a web server:

```bash
php tests/run.php
```
This matters more than it sounds: every request loads every file under
`api/lib/`, so one parse error takes down the entire API, login included.

## Backup

Two independent layers, use both:

- **In the app** (Backup page, administrators only): creates a compressed JSON
  snapshot of all tables in the configured `backup_dir` (the bundled
  `backups/` folder by default). Restore replaces the data, after
  automatically taking a snapshot of the current state first. You can keep the
  existing user accounts while replacing journal data. The SQL export produces
  a dump for phpMyAdmin or the `mysql` client.
- **On the NAS**: Hyper Backup for the MariaDB database and for the web folder.
  The app-level snapshots do not replace a real off-device backup.

`backup_keep` in `api/config.php` limits how many snapshots are kept.

## Security notes

- Passwords are hashed with bcrypt (`password_hash`), sessions are HttpOnly and
  SameSite=Lax, and every writing request is CSRF-checked.
- After 5 failed sign-in attempts within 15 minutes (per user name or address),
  further attempts are rejected for the rest of the window.
- Put the site behind HTTPS. On DSM, either use a reverse proxy with a Let's
  Encrypt certificate, or a certificate on the Web Station portal itself.
- Do not expose the NAS to the internet without a reverse proxy, and consider
  restricting the portal to your LAN or a VPN.
- Delete `install.php` after setup; it refuses to run once users exist, but it
  does not belong on a live system.
- Snapshots contain all data including password hashes, and `backup_dir`
  defaults to the bundled `backups/` folder inside the site. That default is
  chosen because it works everywhere: Web Station confines PHP with
  `open_basedir`, so a folder outside the document root is invisible to PHP
  even when its owner and mode are right. Inside the site the files are
  protected by unguessable random names, an auto-created empty `index.html`
  and the bundled `.htaccess` - the last of which Apache honours and nginx
  ignores. Moving `backup_dir` out of the web root is the stronger option:
  add the target to `open_basedir` in the Web Station PHP profile, keeping
  every path already listed there.

## Weather data

Weather comes from [Open-Meteo](https://open-meteo.com/) (free, no key,
CC-BY 4.0 attribution). Entries up to six days old use the forecast/observation
endpoint, older ones the archive endpoint. Values land in the inspection record
and stay editable. If the NAS has no outbound internet access, set
`weather.enabled` to `false` in `api/config.php`; everything else keeps
working.

## Coordinates: address search and map

An apiary needs coordinates, because they drive the weather lookup. Two ways
to set them, and they work together:

- **Address search** via [Nominatim](https://nominatim.openstreetmap.org/)
  (OpenStreetMap, free, no key). It understands street, house number, postcode
  and town. Picking a hit also fills in the elevation, looked up from
  Open-Meteo. Configured under `geo` in `api/config.php`; its usage policy
  asks for at most one request per second, which this app stays far below.
- **Click map** in the apiary form: drag to pan, scroll or the buttons to
  zoom, click to set the coordinate. Fine-tune the exact hive spot after the
  address search has taken you to the street.

The map is roughly 120 lines in `assets/js/app.js` - no mapping library, no
build step. Tiles are loaded **by the browser** from the server set in
`map.tile_url`, which therefore learns roughly where your apiaries are. Set
`map.enabled` to `false` to drop the map and keep the address search only;
the form degrades cleanly.

## Licence

MIT for this code, see [LICENSE](LICENSE). Open-Meteo data is licensed
CC-BY 4.0.
