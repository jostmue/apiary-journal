# Installing on a Synology NAS (DSM 7)

Everything below happens in DSM in the browser; no SSH is required, although
one optional step is easier with it.

## 1. Packages

Open **Package Center** and install:

- **MariaDB 10** – the database. During installation you set the password for
  the `root` database user and the TCP port (default **3307**). Write both down.
- **phpMyAdmin** – convenient for creating the database and for restoring SQL
  dumps.
- **Web Station** – the web server.
- **PHP 8.x** – Web Station offers it as a separate package.

In **Web Station → PHP**, create or edit a PHP profile and enable the
extensions `pdo_mysql`, `curl` and `mbstring`. Set `date.timezone` to
`Europe/Berlin`.

## 2. Database and database user

Open phpMyAdmin and sign in as `root` on port 3307.

```sql
CREATE DATABASE beekeeping CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'beekeeping'@'localhost' IDENTIFIED BY 'a-long-password';
GRANT ALL PRIVILEGES ON beekeeping.* TO 'beekeeping'@'localhost';
FLUSH PRIVILEGES;
```

Use a password you have not used elsewhere; it ends up in `api/config.php`.

## 3. Copy the files

Create a shared folder `web` if DSM has not already (Web Station usually
creates `/volume1/web`). Then, in **File Station**, create
`web/beekeeping` and upload the contents of this project into it, so that
`index.html` sits at `/volume1/web/beekeeping/index.html`.

## 4. Web portal

**Web Station → Web Service → Create**:

- Service type: *Static website* / *PHP*
- Document root: `web/beekeeping`
- PHP profile: the one from step 1

Then **Web Portal → Create**, bind the service to a port (for example 8080) or
to a hostname. Nginx and Apache both work; the app needs no rewrite rules.

## 5. Permissions

The web server runs as user `http`. In **File Station**, right-click the
`beekeeping` folder → **Properties → Permissions**, and give `http`
read/write on:

- `api/` (the installer writes `config.php` there)
- the backup directory (default `/volume1/Backup/apiary-journal` - create this
  shared folder or directory first; it deliberately lives **outside** the web
  root because snapshots contain all data including password hashes)

Read access is enough for everything else. With SSH:

```bash
sudo mkdir -p /volume1/Backup/apiary-journal
sudo chown -R http:http /volume1/web/beekeeping/api /volume1/Backup/apiary-journal
sudo chmod 750 /volume1/web/beekeeping/api /volume1/Backup/apiary-journal
```

## 6. Run the setup wizard

Open `http://<nas-ip>:8080/install.php`.

The page first shows an environment check. Then enter:

- **Host** `127.0.0.1`, **Port** `3307` (the MariaDB 10 port from step 1)
- **Database** `beekeeping`, **user** and **password** from step 2
- The administrator account for the journal, and the default language
- Whether the weather lookup should be active

Press **Install**. The wizard imports `db/schema.sql`, creates the
administrator and writes `api/config.php`.

**Delete `install.php` afterwards** (File Station → right-click → Delete).

## 7. First steps in the app

Open `http://<nas-ip>:8080/` and sign in.

1. **Apiaries** → *New apiary*. Enter a name, then use the place search to fill
   in the coordinates - they drive the automatic weather.
2. **Colonies** → *New colony*. Pick the apiary, give the colony a number,
   race and hive type.
3. Open the colony and add the **queen** on the Queens tab. The birth year
   controls the marking colour shown on the colony card.
4. **Inspections** → *New inspection*. Pick colony and time; the weather is
   fetched automatically.
5. **Users** (administrators only) → create accounts for anyone else who works
   the hives. Give read-only access to people who should only look.

## 8. HTTPS and access from outside

- Simplest: keep the portal on the LAN and reach it through the Synology VPN
  or Tailscale.
- Alternatively, DSM's **Login Portal → Reverse Proxy** with a Let's Encrypt
  certificate, and forward only 443 on the router. Enable **Control Panel →
  Security → Account Protection** (auto-block) as well.

## 9. Backups

- In the app: **Backup → Create backup now**. Snapshots land in the
  `backup_dir` from `api/config.php` (default `/volume1/Backup/apiary-journal`),
  can be downloaded, uploaded and restored, and old ones are pruned according
  to `backup_keep`.
- On the NAS: back up the MariaDB database with **Hyper Backup** (it has a
  MariaDB task) and include the web folder. This is the layer that survives a
  broken volume; the in-app snapshots do not.

## Troubleshooting

| Symptom | Cause and fix |
|---------|---------------|
| *"The application is not set up yet"* | `api/config.php` is missing – run `install.php`. |
| *"The database is not reachable"* | Wrong port (3307, not 3306), wrong password, or MariaDB is not running. |
| Installer cannot write `config.php` | `http` has no write permission on `api/` – see step 5. |
| *"Weather data is not available"* | No outbound internet access, or `curl` is disabled in the PHP profile. The apiary may also have no coordinates. |
| Blank page, no error | Look at the PHP error log: Web Station → PHP profile → error log path, usually under `/volume1/web/…` or `/var/log/nginx/`. |
| Sign-in loops back to the login screen | Cookies blocked, or the session directory is not writable. Check `session.save_path` in the PHP profile. |
| Upload of a backup file fails | `upload_max_filesize` and `post_max_size` in the PHP profile are smaller than the snapshot. |
