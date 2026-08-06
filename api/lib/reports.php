<?php
/**
 * Report engine.
 *
 * A report is a filtered timeline across any combination of record types.
 * Filters: record types, apiary, colony, user, date range, free text.
 * Output: JSON for the screen plus a summary block with totals (harvest,
 * feed, varroa counts, ...). The CSV export is assembled in the browser,
 * where option values have a translated name.
 */

declare(strict_types=1);

/**
 * Feed is summed per physical unit; grams and millilitres are folded into the
 * kilogram and litre totals so a feeding entered in grams is not silently
 * dropped. 'pcs' has no meaningful conversion and stays out of both.
 */
const FEED_SUM_KG = "SUM(CASE WHEN x.unit = 'kg' THEN x.amount WHEN x.unit = 'g'  THEN x.amount / 1000 END)";
const FEED_SUM_L  = "SUM(CASE WHEN x.unit = 'l'  THEN x.amount WHEN x.unit = 'ml' THEN x.amount / 1000 END)";

function report_sources(): array
{
    return [
        'inspections' => [
            'table' => 'inspections', 'date' => 'inspected_at',
        ],
        'feedings' => [
            'table' => 'feedings', 'date' => 'fed_at',
        ],
        'treatments' => [
            'table' => 'treatments', 'date' => 'started_at',
        ],
        'harvests' => [
            'table' => 'harvests', 'date' => 'harvested_at',
        ],
        'events' => [
            'table' => 'events', 'date' => 'event_at',
        ],
        'tasks' => [
            'table' => 'tasks', 'date' => 'due_date',
            // The tasks table calls its free text column "description".
            'notes' => 'description',
        ],
    ];
}

/** The WHERE clause shared by the condensed and the detailed query. */
function report_filters(string $type, array $s, array $filter): array
{
    // Same rule as everywhere else; the report tables are all aliased x.
    $where = [visible_sql($type, 'x')];
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

/**
 * Every column of every matching record. Feeds both report views; the table
 * composes its summary column in the browser.
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
                       COALESCE(NULLIF(u.full_name, ''), u.username) AS user_name
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
    // The key figures have to sit under the same filter as the rows they
    // summarise, otherwise picking a user or a search term changes the list
    // while the totals silently keep counting everything.
    if (!empty($filter['user_id'])) { $where[] = 'x.user_id = ?'; $args[] = (int)$filter['user_id']; }
    if (!empty($filter['search'])) {
        $where[] = '(x.notes LIKE ? OR c.name LIKE ?)';
        $args[]  = '%' . $filter['search'] . '%';
        $args[]  = '%' . $filter['search'] . '%';
    }
    $clause = $where ? ' AND ' . implode(' AND ', $where) : '';

    $range = [];
    $rargs = [];
    if (!empty($filter['date_from'])) { $range[] = 'DATE(x.%s) >= ?'; $rargs[] = substr((string)$filter['date_from'], 0, 10); }
    if (!empty($filter['date_to']))   { $range[] = 'DATE(x.%s) <= ?'; $rargs[] = substr((string)$filter['date_to'], 0, 10); }

    $run = function (string $type, string $table, string $dateCol, string $select) use ($clause, $args, $range, $rargs) {
        // The apiary is joined even where the figures do not use it, because
        // the visibility rule for events refers to it.
        $sql = "SELECT {$select} FROM {$table} x
                LEFT JOIN colonies c ON c.id = x.colony_id
                LEFT JOIN apiaries a ON a.id = COALESCE(c.apiary_id, "
             . (in_array($type, ['events', 'tasks'], true) ? 'x.apiary_id' : 'NULL') . ")
                WHERE " . visible_sql($type, 'x') . $clause;
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
        return in_array($type, $types, true) ? $run($type, $table, $dateCol, $select) : null;
    };

    $insp    = $only('inspections', 'inspections', 'inspected_at', 'COUNT(*) AS n, AVG(x.varroa_count) AS varroa_avg, MAX(x.varroa_count) AS varroa_max');
    $feed    = $only('feedings', 'feedings', 'fed_at', "COUNT(*) AS n,
        " . FEED_SUM_KG . " AS total_kg,
        " . FEED_SUM_L . " AS total_l");
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
        'feed_kg'       => $num($feed, 'total_kg', 2, 0),
        'feed_l'        => $num($feed, 'total_l', 2, 0),
        'treatments'    => $cnt($treat),
        'harvests'      => $cnt($harvest),
        'harvest_kg'    => $num($harvest, 'total_kg', 2, 0),
        'water_avg'     => $num($harvest, 'water_avg', 1),
        'events'        => $cnt($events),
    ];
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

    // Every figure counts only what the user may see, so the dashboard cannot
    // become a way to learn how many colonies somebody else keeps.
    $colonies  = 'FROM colonies c WHERE ' . visible_sql('colonies');
    $viaColony = function (string $table, string $type) {
        return "FROM {$table} x JOIN colonies c ON c.id = x.colony_id WHERE "
             . visible_sql($type, 'x');
    };
    $tasks = 'FROM tasks t
              LEFT JOIN colonies c ON c.id = t.colony_id
              LEFT JOIN apiaries a ON a.id = COALESCE(c.apiary_id, t.apiary_id)
              WHERE ' . visible_sql('tasks', 't');

    $year = date('Y');
    ok([
        'apiaries'          => (int)$one('SELECT COUNT(*) FROM apiaries a WHERE a.is_active = 1 AND ' . visible_sql('apiaries')),
        'colonies_active'   => (int)$one("SELECT COUNT(*) {$colonies} AND c.status = 'active'"),
        'colonies_total'    => (int)$one("SELECT COUNT(*) {$colonies}"),
        'inspections_year'  => (int)$one('SELECT COUNT(*) ' . $viaColony('inspections', 'inspections')
                               . " AND YEAR(x.inspected_at) = {$year}"),
        'harvest_year_kg'   => round((float)$one('SELECT COALESCE(SUM(x.net_kg),0) ' . $viaColony('harvests', 'harvests')
                               . " AND YEAR(x.harvested_at) = {$year}"), 2),
        // Solid and liquid feed are counted apart: adding kilograms to litres
        // would produce a number that means nothing.
        'feed_year_kg'      => round((float)$one('SELECT COALESCE(' . FEED_SUM_KG . ',0) ' . $viaColony('feedings', 'feedings')
                               . " AND YEAR(x.fed_at) = {$year}"), 2),
        'feed_year_l'       => round((float)$one('SELECT COALESCE(' . FEED_SUM_L . ',0) ' . $viaColony('feedings', 'feedings')
                               . " AND YEAR(x.fed_at) = {$year}"), 2),
        'tasks_open'        => (int)$one("SELECT COUNT(*) {$tasks} AND t.status = 'open'"),
        'tasks_overdue'     => (int)$one("SELECT COUNT(*) {$tasks} AND t.status = 'open' AND t.due_date < CURDATE()"),
        'year'              => (int)$year,
    ]);
}

/**
 * Recent activity for the dashboard timeline. Full records, because the
 * one-line summary is composed in the browser - option values like
 * "syrup_3_2" only have a readable name there.
 */
function handle_recent(): void
{
    require_login();
    $rows = report_query_detail([
        'date_from' => date('Y-m-d', strtotime('-60 days')),
        // Without an upper bound a task due next season sits permanently at
        // the top of a list that claims to show recent activity.
        'date_to'   => date('Y-m-d'),
    ]);
    ok(array_slice($rows, 0, 40));
}
