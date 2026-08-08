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
require __DIR__ . '/lib/migrate.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/entities.php';
require __DIR__ . '/lib/weather.php';
require __DIR__ . '/lib/reports.php';
require __DIR__ . '/lib/backup.php';
require __DIR__ . '/lib/users.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/recovery.php';
require __DIR__ . '/lib/registration.php';
require __DIR__ . '/lib/account.php';
require __DIR__ . '/lib/groups.php';

set_exception_handler(function (Throwable $e) {
    error_log('[apiary-journal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['ok' => false, 'error' => 'server_error'], 500);
});

// An update is installed by copying files over the old ones, so the database
// has to catch up on its own before anything touches it.
migrate_if_needed();

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
            'mail'    => mail_enabled(),
            'mode'    => app_mode(),
            'can_register' => registration_open(),
            'legal'   => legal_urls(),
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

    // Forgotten password. Both are deliberately reachable without a session
    // and answer the same way whether or not the account exists.
    case 'auth/forgot':
        handle_forgot_password();
        break;

    case 'auth/reset':
        handle_reset_password();
        break;

    // Self-registration. Refuses outright in private mode.
    case 'auth/register':
        handle_register();
        break;

    case 'auth/verify':
        handle_verify_email();
        break;

    // Someone following an invitation link has usually not signed in yet, so
    // the preview has to work without a session. It only tells whoever holds
    // the token what they were invited to - which they were sent by mail.
    case 'groups/invite_preview':
        handle_invite_preview();
        break;
}

// --- everything below requires a session -----------------------------------
require_login();

// A full snapshot holds every user's data, which would quietly undo the rule
// that an administrator sees none of it. In open mode the operator backs the
// database up at server level instead.
if (app_mode() === 'open' && strncmp($route, 'backup/', 7) === 0) {
    fail('backup_disabled_open_mode', 403);
}

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

    // groups -----------------------------------------------------------------
    case 'groups/list':           handle_groups_list(); break;
    case 'groups/save':           handle_groups_save(); break;
    case 'groups/delete':         handle_groups_delete(); break;
    case 'groups/members':        handle_group_members(); break;
    case 'groups/member_role':    handle_group_member_save(); break;
    case 'groups/member_remove':  handle_group_member_remove(); break;
    case 'groups/invite':         handle_group_invite(); break;
    case 'groups/invite_revoke':  handle_group_invite_revoke(); break;
    case 'groups/invite_accept':  handle_invite_accept(); break;

    // users ------------------------------------------------------------------
    case 'users/list':         handle_users_list(); break;
    case 'users/save':         handle_users_save(); break;
    case 'users/delete':       handle_users_delete(); break;
    case 'profile/save':       handle_profile_save(); break;
    case 'account/export':     handle_account_export(); break;
    case 'account/delete':     handle_account_delete(); break;
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
