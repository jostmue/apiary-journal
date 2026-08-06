<?php
/**
 * Apiary-Journal - single API entry point.
 *
 * All calls go to  api/index.php?r=<group>/<action>  which works on Apache
 * and nginx alike, so no rewrite rules are needed on DSM's Web Station.
 * Requests and responses are JSON; two routes stream a file instead
 * (backup/download, backup/sql), both by POST like everything else.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/entities.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/reports.php';
require __DIR__ . '/lib/backup.php';
require __DIR__ . '/lib/users.php';

set_exception_handler(function (Throwable $e) {
    error_log('[beekeeping] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['ok' => false, 'error' => 'server_error'], 500);
});

session_start_app();

$route = (string)($_GET['r'] ?? '');
if ($route === '') {
    fail('missing_route', 400);
}

// --- public routes ---------------------------------------------------------
switch ($route) {
    case 'auth/login':
        do_login((string)param('username', ''), (string)param('password', ''));
        break;

    case 'auth/me':
        $u   = current_user();
        $map = config()['map'] ?? [];
        ok([
            'user'    => $u ?: null,
            'csrf'    => $u ? csrf_token() : null,
            'locale'  => $u['locale'] ?? (config()['app']['default_locale'] ?? 'de'),
            'weather' => (bool)(config()['weather']['enabled'] ?? false),
            'map'     => empty($map['enabled']) ? null : [
                'tile_url'    => $map['tile_url'] ?? '',
                'attribution' => $map['attribution'] ?? '',
                'max_zoom'    => (int)($map['max_zoom'] ?? 19),
            ],
        ]);
        break;

    case 'auth/logout':
        do_logout();
        break;
}

// --- everything below requires a session -----------------------------------
require_login();

switch ($route) {
    // journal records --------------------------------------------------------
    case 'apiaries/list':      handle_list('apiaries'); break;
    case 'apiaries/save':      handle_save('apiaries'); break;
    case 'apiaries/delete':    handle_delete('apiaries'); break;

    case 'colonies/list':      handle_list('colonies'); break;
    case 'colonies/save':      handle_save('colonies'); break;
    case 'colonies/delete':    handle_delete('colonies'); break;

    case 'queens/list':        handle_list('queens'); break;
    case 'queens/save':        handle_save('queens'); break;
    case 'queens/delete':      handle_delete('queens'); break;

    case 'inspections/list':   handle_list('inspections'); break;
    case 'inspections/save':   handle_save('inspections'); break;
    case 'inspections/delete': handle_delete('inspections'); break;

    case 'feedings/list':      handle_list('feedings'); break;
    case 'feedings/save':      handle_save('feedings'); break;
    case 'feedings/delete':    handle_delete('feedings'); break;

    case 'treatments/list':    handle_list('treatments'); break;
    case 'treatments/save':    handle_save('treatments'); break;
    case 'treatments/delete':  handle_delete('treatments'); break;

    case 'harvests/list':      handle_list('harvests'); break;
    case 'harvests/save':      handle_save('harvests'); break;
    case 'harvests/delete':    handle_delete('harvests'); break;

    case 'events/list':        handle_list('events'); break;
    case 'events/save':        handle_save('events'); break;
    case 'events/delete':      handle_delete('events'); break;

    case 'tasks/list':         handle_list('tasks'); break;
    case 'tasks/save':         handle_save('tasks'); break;
    case 'tasks/delete':       handle_delete('tasks'); break;

    // weather and geocoding --------------------------------------------------
    case 'weather/get':        handle_weather(); break;
    case 'geo/search':         handle_geo_search(); break;

    // dashboard and reports --------------------------------------------------
    case 'stats/summary':      handle_stats(); break;
    case 'stats/recent':       handle_recent(); break;
    case 'reports/detail':     handle_report_detail(); break;

    // users ------------------------------------------------------------------
    case 'users/list':         handle_users_list(); break;
    case 'users/save':         handle_users_save(); break;
    case 'users/delete':       handle_users_delete(); break;
    case 'profile/save':       handle_profile_save(); break;
    case 'log/list':           handle_activity_log(); break;

    // backup -----------------------------------------------------------------
    case 'backup/list':        handle_backup_list(); break;
    case 'backup/create':      handle_backup_create(); break;
    case 'backup/delete':      handle_backup_delete(); break;
    case 'backup/download':    handle_backup_download(); break;
    case 'backup/sql':         handle_backup_sql(); break;
    case 'backup/restore':     handle_backup_restore(); break;
    case 'backup/upload':      handle_backup_upload(); break;

    default:
        fail('unknown_route', 404, $route);
}
