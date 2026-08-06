<?php
/**
 * Report engine.
 *
 * A report is a filtered timeline across any combination of record types.
 * Filters: record types, apiary, colony, user, date range, free text.
 * Output: JSON for the screen, CSV for spreadsheets, and a summary block
 * with totals (harvest, feed, varroa counts, ...).
 */

declare(strict_types=1);

function report_sources(): array
{
    return [
        'inspections' => [
            'table' => 'inspections', 'date' => 'inspected_at',
            'summary' => "CONCAT_WS(' | ',
                CONCAT('bees:', COALESCE(x.strength_frames,'-')),
                CONCAT('brood:', COALESCE(x.brood_frames,'-')),
                CASE WHEN x.queen_seen = 1 THEN 'queen seen' ELSE NULL END,
                CASE WHEN x.eggs_seen = 1 THEN 'eggs' ELSE NULL END,
                CASE WHEN x.queen_cell_type IS NOT NULL AND x.queen_cell_type <> 'none'
                     THEN CONCAT('cells:', x.queen_cell_type) ELSE NULL END,
                CASE WHEN x.varroa_count IS NOT NULL THEN CONCAT('varroa:', x.varroa_count) ELSE NULL END,
                CASE WHEN x.health_status IS NOT NULL THEN CONCAT('health:', x.health_status) ELSE NULL END)",
        ],
        'feedings' => [
            'table' => 'feedings', 'date' => 'fed_at',
            'summary' => "CONCAT_WS(' ', x.feed_type, x.amount, x.unit)",
        ],
        'treatments' => [
            'table' => 'treatments', 'date' => 'started_at',
            'summary' => "CONCAT_WS(' ', x.target, x.product, x.dose, x.unit, x.method)",
        ],
        'harvests' => [
            'table' => 'harvests', 'date' => 'harvested_at',
            'summary' => "CONCAT_WS(' ', x.honey_type, CONCAT(COALESCE(x.net_kg,0),' kg'), CONCAT(COALESCE(x.water_content,0),'% H2O'))",
        ],
        'events' => [
            'table' => 'events', 'date' => 'event_at',
            'summary' => "CONCAT_WS(' - ', x.event_type, x.title)",
        ],
        'tasks' => [
            'table' => 'tasks', 'date' => 'due_date',
            'summary' => "CONCAT_WS(' - ', x.status, x.title)",
            // The tasks table calls its free text column "description".
            'notes' => 'description',
        ],
    ];
}

/** The WHERE clause shared by the condensed and the detailed query. */
function report_filters(string $type, array $s, array $filter): array
{
    $where = [];
    $args  = [];

    if (!empty($filter['colony_id'])) {
        $where[] = 'x.colony_id = ?';
        $args[]  = (int)$filter['colony_id'];
    }
    if (!empty($filter['apiary_id'])) {
        $where[] = ($type === 'tasks' || $type === 'events')
            ? '(c.apiary_id = ? OR x.apiary_id = ?)'
            : 'c.apiary_id = ?';
        $args[]  = (int)$filter['apiary_id'];
        if ($type === 'tasks' || $type === 'events') {
            $args[] = (int)$filter['apiary_id'];
        }
    }
    if (!empty($filter['user_id'])) {
        $where[] = 'x.user_id = ?';
        $args[]  = (int)$filter['user_id'];
    }
    if (!empty($filter['date_from'])) {
        $where[] = "DATE(x.{$s['date']}) >= ?";
        $args[]  = substr((string)$filter['date_from'], 0, 10);
    }
    if (!empty($filter['date_to'])) {
        $where[] = "DATE(x.{$s['date']}) <= ?";
        $args[]  = substr((string)$filter['date_to'], 0, 10);
    }
    if (!empty($filter['search'])) {
        $notesCol = $s['notes'] ?? 'notes';
        $where[]  = "(x.{$notesCol} LIKE ? OR c.name LIKE ?)";
        $like     = '%' . $filter['search'] . '%';
        $args[]   = $like;
        $args[]   = $like;
    }

    return [$where, $args];
}

/**
 * The FROM/JOIN block shared by both queries. Events and tasks may hang off an
 * apiary directly instead of a colony, so for those the apiary is resolved
 * from the colony first and from the record itself otherwise.
 */
function report_from(string $type, array $s): string
{
    $apiaryOn = ($type === 'tasks' || $type === 'events')
        ? 'a.id = COALESCE(c.apiary_id, x.apiary_id)'
        : 'a.id = c.apiary_id';

    return "FROM {$s['table']} x
            LEFT JOIN colonies c ON c.id = x.colony_id
            LEFT JOIN apiaries a ON {$apiaryOn}
            LEFT JOIN users u ON u.id = x.user_id";
}

/**
 * The record types a filter selects.
 *
 * A missing 'types' key means "no preference given" and yields all types -
 * the dashboard timeline relies on that. An empty list, on the other hand, is
 * a deliberate choice by the user and must yield nothing.
 */
function report_types(array $filter): array
{
    $sources = report_sources();
    if (!array_key_exists('types', $filter) || !is_array($filter['types'])) {
        return array_keys($sources);
    }
    return array_values(array_filter($filter['types'], fn($t) => isset($sources[$t])));
}

function report_query(array $filter): array
{
    $sources = report_sources();

    $rows = [];
    foreach (report_types($filter) as $type) {
        $s = $sources[$type];
        [$where, $args] = report_filters($type, $s, $filter);
        $notesCol = $s['notes'] ?? 'notes';

        $sql = "SELECT '{$type}' AS record_type, x.id, x.{$s['date']} AS record_date,
                       c.id AS colony_id, c.name AS colony_name,
                       a.id AS apiary_id, a.name AS apiary_name,
                       u.username AS user_name,
                       {$s['summary']} AS summary, x.{$notesCol} AS notes
                " . report_from($type, $s);
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY x.{$s['date']} DESC LIMIT 5000";

        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = $r;
        }
    }

    // Newest first across all record types.
    usort($rows, function ($a, $b) {
        return strcmp((string)$b['record_date'], (string)$a['record_date']);
    });

    return $rows;
}

/**
 * Same filtering as report_query, but every column of the record is returned
 * instead of a condensed summary line. Feeds the full protocol view, which
 * renders each record with all of its fields.
 */
function report_query_detail(array $filter): array
{
    $sources = report_sources();

    $rows = [];
    foreach (report_types($filter) as $type) {
        $s = $sources[$type];
        [$where, $args] = report_filters($type, $s, $filter);

        $sql = "SELECT x.*, '{$type}' AS record_type, x.{$s['date']} AS record_date,
                       c.name AS colony_name, a.name AS apiary_name,
                       u.username AS user_name
                " . report_from($type, $s);
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        // A full protocol is meant to be read and printed, so the cap is
        // lower than for the condensed table.
        $sql .= " ORDER BY x.{$s['date']} DESC LIMIT 1000";

        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = $r;
        }
    }

    usort($rows, function ($a, $b) {
        return strcmp((string)$b['record_date'], (string)$a['record_date']);
    });

    return $rows;
}

function report_summary(array $filter): array
{
    $where = [];
    $args  = [];
    if (!empty($filter['colony_id'])) { $where[] = 'x.colony_id = ?'; $args[] = (int)$filter['colony_id']; }
    if (!empty($filter['apiary_id'])) { $where[] = 'c.apiary_id = ?'; $args[] = (int)$filter['apiary_id']; }
    $clause = $where ? ' AND ' . implode(' AND ', $where) : '';

    $range = [];
    $rargs = [];
    if (!empty($filter['date_from'])) { $range[] = 'DATE(x.%s) >= ?'; $rargs[] = substr((string)$filter['date_from'], 0, 10); }
    if (!empty($filter['date_to']))   { $range[] = 'DATE(x.%s) <= ?'; $rargs[] = substr((string)$filter['date_to'], 0, 10); }

    $run = function (string $table, string $dateCol, string $select) use ($clause, $args, $range, $rargs) {
        $sql = "SELECT {$select} FROM {$table} x LEFT JOIN colonies c ON c.id = x.colony_id WHERE 1=1{$clause}";
        foreach ($range as $r) {
            $sql .= ' AND ' . sprintf($r, $dateCol);
        }
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($args, $rargs));
        return $stmt->fetch() ?: [];
    };

    // Key figures cover the selected record types only; a figure for a type
    // the user deselected stays null so the interface can drop the tile.
    $types = report_types($filter);
    $only  = function (string $type, string $table, string $dateCol, string $select) use ($types, $run) {
        return in_array($type, $types, true) ? $run($table, $dateCol, $select) : null;
    };

    $insp    = $only('inspections', 'inspections', 'inspected_at', 'COUNT(*) AS n, AVG(x.varroa_count) AS varroa_avg, MAX(x.varroa_count) AS varroa_max');
    $feed    = $only('feedings', 'feedings', 'fed_at', "COUNT(*) AS n, SUM(CASE WHEN x.unit IN ('kg','l') THEN x.amount ELSE 0 END) AS total");
    $treat   = $only('treatments', 'treatments', 'started_at', 'COUNT(*) AS n');
    $harvest = $only('harvests', 'harvests', 'harvested_at', 'COUNT(*) AS n, SUM(x.net_kg) AS total_kg, AVG(x.water_content) AS water_avg');
    $events  = $only('events', 'events', 'event_at', 'COUNT(*) AS n');

    // $empty is what a selected type shows when it matched no rows: a sum of
    // nothing is 0, an average of nothing has no meaningful value.
    $num = fn($row, string $key, int $dec, $empty = null) => $row === null ? null
        : ($row[$key] !== null ? round((float)$row[$key], $dec) : $empty);
    $cnt = fn($row) => $row !== null ? (int)($row['n'] ?? 0) : null;

    return [
        'inspections'   => $cnt($insp),
        'varroa_avg'    => $num($insp, 'varroa_avg', 1),
        'varroa_max'    => $insp !== null && $insp['varroa_max'] !== null ? (int)$insp['varroa_max'] : null,
        'feedings'      => $cnt($feed),
        'feed_total'    => $num($feed, 'total', 2, 0),
        'treatments'    => $cnt($treat),
        'harvests'      => $cnt($harvest),
        'harvest_kg'    => $num($harvest, 'total_kg', 2, 0),
        'water_avg'     => $num($harvest, 'water_avg', 1),
        'events'        => $cnt($events),
    ];
}

function handle_report(): void
{
    require_login();
    $filter = (array)param('filter', []);
    ok([
        'rows'    => report_query($filter),
        'summary' => report_summary($filter),
        'filter'  => $filter,
    ]);
}

/** Full protocol: every field of every matching record. */
function handle_report_detail(): void
{
    require_login();
    $filter = (array)param('filter', []);
    ok([
        'rows'    => report_query_detail($filter),
        'summary' => report_summary($filter),
        'filter'  => $filter,
    ]);
}

/**
 * Excel and LibreOffice execute cell values starting with =, +, - or @ as
 * formulas. A leading apostrophe forces plain text.
 */
function csv_text($v)
{
    if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
        return "'" . $v;
    }
    return $v;
}

/** GET download: api/index.php?r=reports/csv&filter=<urlencoded json>&csrf=... */
function handle_report_csv(): void
{
    require_login();
    require_csrf();
    $filter = json_decode((string)($_GET['filter'] ?? '[]'), true) ?: [];
    $rows   = report_query($filter);

    $name = 'beekeeping-report-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel detects UTF-8
    fputcsv($out, ['date', 'type', 'apiary', 'colony', 'user', 'summary', 'notes'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['record_date'], $r['record_type'], csv_text($r['apiary_name']),
            csv_text($r['colony_name']), csv_text($r['user_name']),
            csv_text($r['summary']), csv_text($r['notes']),
        ], ';');
    }
    fclose($out);
    exit;
}

/** Dashboard numbers. */
function handle_stats(): void
{
    require_login();
    $pdo = db();
    $one = function (string $sql) use ($pdo) {
        $stmt = $pdo->query($sql);
        $row  = $stmt->fetch();
        return $row ? array_values($row)[0] : 0;
    };

    $year = date('Y');
    ok([
        'apiaries'          => (int)$one('SELECT COUNT(*) FROM apiaries WHERE is_active = 1'),
        'colonies_active'   => (int)$one("SELECT COUNT(*) FROM colonies WHERE status = 'active'"),
        'colonies_total'    => (int)$one('SELECT COUNT(*) FROM colonies'),
        'inspections_year'  => (int)$one("SELECT COUNT(*) FROM inspections WHERE YEAR(inspected_at) = {$year}"),
        'harvest_year_kg'   => round((float)$one("SELECT COALESCE(SUM(net_kg),0) FROM harvests WHERE YEAR(harvested_at) = {$year}"), 2),
        'feed_year'         => round((float)$one("SELECT COALESCE(SUM(amount),0) FROM feedings WHERE YEAR(fed_at) = {$year} AND unit IN ('kg','l')"), 2),
        'tasks_open'        => (int)$one("SELECT COUNT(*) FROM tasks WHERE status = 'open'"),
        'tasks_overdue'     => (int)$one("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_date < CURDATE()"),
        'year'              => (int)$year,
    ]);
}

/** Recent activity for the dashboard timeline. */
function handle_recent(): void
{
    require_login();
    $rows = report_query(['date_from' => date('Y-m-d', strtotime('-60 days'))]);
    ok(array_slice($rows, 0, 40));
}
