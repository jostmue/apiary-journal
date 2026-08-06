<?php
/**
 * Apiary-Journal - configuration template.
 *
 * Copy this file to config.php and adjust the values.
 * config.php is never delivered by the web server (see .htaccess) and should
 * not be committed to a repository.
 */

return [
    // --- Database (MariaDB 10 from the Synology Package Center) -------------
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3307,            // DSM MariaDB 10: 3307, elsewhere usually 3306
        'name'     => 'beekeeping',
        'user'     => 'beekeeping',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // --- Application -------------------------------------------------------
    'app' => [
        // Directory used for database backups, writable by the web server
        // user (http on DSM).
        //
        // Defaults to the bundled folder inside the site because that works
        // everywhere: Web Station confines PHP with open_basedir, and a path
        // outside the document root is not merely unwritable - PHP cannot see
        // it at all, so is_dir() reports a directory that plainly exists as
        // missing. Protection here rests on the unguessable random file
        // names, the bundled .htaccess (Apache only) and an auto-created
        // empty index.html.
        //
        // Snapshots contain all data including password hashes, so moving
        // them out of the web root is worthwhile if you can: add the target
        // to open_basedir in the Web Station PHP profile - keeping every path
        // already listed there, or PHP loses access to the site itself - and
        // put the absolute path here.
        'backup_dir'      => __DIR__ . '/../backups',

        // Keep at most this many automatic/manual backups; older ones are
        // removed when a new backup is created. 0 = keep everything.
        'backup_keep'     => 30,

        // Default interface language for the login screen: 'de' or 'en'.
        'default_locale'  => 'de',

        // Public address of this installation, e.g. https://apiary.example.org
        // Used to build the link in a password reset mail. Leave empty to take
        // it from the request - fine on a private network, but a caller can
        // choose the Host header, so set it once the site is reachable from
        // the internet.
        'base_url'        => '',

        // Session lifetime in minutes.
        'session_minutes' => 480,

        // Set to true once the installation is finished; install.php refuses
        // to run again while a config.php exists and users are present.
        'installed'       => true,
    ],

    // --- Mail ---------------------------------------------------------------
    // Needed only for the "forgotten password" link. Without it the button is
    // hidden and an administrator sets passwords instead.
    'mail' => [
        'enabled'   => false,
        'from'      => 'apiary-journal@example.org',
        'from_name' => 'Apiary-Journal',

        // 'mail' uses PHP's mail() and needs a working MTA on the machine -
        // usual on hosting, almost never on a NAS. 'smtp' talks to a mailbox
        // of your own and works anywhere with outbound access.
        'transport' => 'smtp',
        'smtp' => [
            'host'     => 'smtp.example.org',
            'port'     => 587,
            'security' => 'starttls',   // 'starttls' (587), 'tls' (465), 'none'
            'username' => '',
            'password' => '',
            'timeout'  => 15,
        ],
    ],

    // --- Security ----------------------------------------------------------
    'security' => [
        // Reverse proxies sitting in front of this app, as addresses or CIDR
        // ranges. Only a request arriving from one of these is allowed to
        // state the real client address and protocol through the headers
        // X-Forwarded-For and X-Forwarded-Proto - otherwise any caller could
        // claim to be someone else and walk around the login rate limit.
        //
        // Leave empty when PHP is reached directly. For the DSM reverse proxy
        // or a proxy on the same machine: ['127.0.0.1', '::1'].
        'trusted_proxies' => [],

        // Send Strict-Transport-Security, which tells browsers to use HTTPS
        // for this host from now on. Only switch this on once the site is
        // reachable over HTTPS - it is remembered for a year and locks out
        // plain HTTP, including for anyone else on the same hostname.
        'hsts' => false,
    ],

    // --- Geocoding (address search for apiary coordinates) -----------------
    // Nominatim (OpenStreetMap) understands street, house number, postcode
    // and town. Free and without an API key; its usage policy asks for at
    // most one request per second, which this app stays far below.
    'geo' => [
        'search_url'    => 'https://nominatim.openstreetmap.org/search',
        'elevation_url' => 'https://api.open-meteo.com/v1/elevation',
        'language'      => 'de',
        'timeout'       => 8,
    ],

    // --- Map ---------------------------------------------------------------
    // The apiary form can show a click map for picking coordinates. The tiles
    // are fetched by the BROWSER from the server below, which therefore learns
    // roughly where your apiaries are. Set enabled = false to keep the address
    // search only - everything else works unchanged.
    'map' => [
        'enabled'     => true,
        'tile_url'    => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '(c) OpenStreetMap',
        'max_zoom'    => 19,
    ],

    // --- Weather -----------------------------------------------------------
    'weather' => [
        // Open-Meteo needs no API key. Set enabled = false if the NAS has no
        // outbound internet access; weather fields then stay editable/empty.
        'enabled'      => true,
        'forecast_url' => 'https://api.open-meteo.com/v1/forecast',
        'archive_url'  => 'https://archive-api.open-meteo.com/v1/archive',
        'geocode_url'  => 'https://geocoding-api.open-meteo.com/v1/search',
        'timezone'     => 'Europe/Berlin',
        'timeout'      => 8,           // seconds
        'cache_hours'  => 3,           // re-fetch same-day data after n hours
    ],
];
