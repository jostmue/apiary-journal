<?php
/**
 * Entity definitions and the generic list/save/delete handlers.
 *
 * Every entity declares the columns a client is allowed to write, together
 * with the type used to normalise incoming values. Anything not listed here
 * can never be written through the API.
 */

declare(strict_types=1);

function entities(): array
{
    return [
        'apiaries' => [
            'table'  => 'apiaries',
            'order'  => '{a}.name ASC',
            'fields' => [
                'name' => 'string', 'code' => 'string', 'address' => 'string',
                'latitude' => 'float', 'longitude' => 'float', 'altitude' => 'int',
                'forage_notes' => 'string', 'description' => 'string',
                'is_active' => 'bool',
                // Whom it is shared with. owner_id is deliberately absent:
                // it is set from the session and never taken from a request.
                'group_id' => 'int',
            ],
            'owner'  => 'created_by',
            'owned'  => true,
        ],
        'colonies' => [
            'table'  => 'colonies',
            'order'  => '{a}.name ASC',
            'fields' => [
                'apiary_id' => 'int', 'name' => 'string', 'tag_number' => 'string',
                'race' => 'string', 'origin' => 'string', 'hive_type' => 'string',
                'frame_size' => 'string', 'box_count' => 'int',
                'established_on' => 'date', 'status' => 'string',
                'parent_colony_id' => 'int', 'notes' => 'string',
                'group_id' => 'int',
            ],
            'owner'  => 'created_by',
            'owned'  => true,
            'filters' => ['apiary_id' => 'int', 'status' => 'string'],
        ],
        'queens' => [
            'table'  => 'queens',
            'order'  => '{a}.is_current DESC, {a}.introduced_on DESC, {a}.id DESC',
            'fields' => [
                'colony_id' => 'int', 'name' => 'string', 'race' => 'string',
                'birth_year' => 'int',
                'marking_color' => 'enum:white,yellow,red,green,blue,unmarked',
                'mating_type' => 'string', 'breeder' => 'string', 'origin' => 'string',
                'introduced_on' => 'date', 'removed_on' => 'date',
                'is_clipped' => 'bool', 'is_current' => 'bool', 'notes' => 'string',
            ],
            'filters' => ['colony_id' => 'int'],
        ],
        'inspections' => [
            'table'  => 'inspections',
            'order'  => '{a}.inspected_at DESC, {a}.id DESC',
            'date'   => 'inspected_at',
            'fields' => [
                'colony_id' => 'int', 'inspected_at' => 'datetime',
                'duration_min' => 'int', 'temperament' => 'int',
                'strength_frames' => 'float', 'brood_frames' => 'float',
                'eggs_seen' => 'bool', 'larvae_seen' => 'bool',
                'capped_brood_seen' => 'bool', 'queen_seen' => 'bool',
                'queen_cell_type' => 'string', 'queen_cell_count' => 'int',
                'drone_brood' => 'bool', 'stores_kg' => 'float',
                'supers_count' => 'int', 'space_action' => 'string',
                'varroa_count' => 'int', 'varroa_method' => 'string',
                'varroa_days' => 'int', 'health_status' => 'string',
                'swarm_risk' => 'bool', 'hive_weight_kg' => 'float',
                'weather_temp' => 'float', 'weather_humidity' => 'int',
                'weather_wind' => 'float', 'weather_wind_dir' => 'int',
                'weather_cloud' => 'int', 'weather_precip' => 'float',
                'weather_pressure' => 'float', 'weather_code' => 'int',
                'weather_source' => 'string', 'notes' => 'string',
            ],
            'owner'   => 'user_id',
            'filters' => ['colony_id' => 'int'],
        ],
        'feedings' => [
            'table'  => 'feedings',
            'order'  => '{a}.fed_at DESC, {a}.id DESC',
            'date'   => 'fed_at',
            'fields' => [
                'colony_id' => 'int', 'fed_at' => 'datetime', 'feed_type' => 'string',
                'amount' => 'float', 'unit' => 'string', 'notes' => 'string',
            ],
            'owner'   => 'user_id',
            'filters' => ['colony_id' => 'int'],
        ],
        'treatments' => [
            'table'  => 'treatments',
            'order'  => '{a}.started_at DESC, {a}.id DESC',
            'date'   => 'started_at',
            'fields' => [
                'colony_id' => 'int', 'started_at' => 'date', 'ended_at' => 'date',
                'target' => 'string', 'product' => 'string',
                'active_substance' => 'string', 'dose' => 'float', 'unit' => 'string',
                'method' => 'string', 'temperature_c' => 'float',
                'batch_no' => 'string', 'withdrawal_until' => 'date',
                'notes' => 'string',
            ],
            'owner'   => 'user_id',
            'filters' => ['colony_id' => 'int'],
        ],
        'harvests' => [
            'table'  => 'harvests',
            'order'  => '{a}.harvested_at DESC, {a}.id DESC',
            'date'   => 'harvested_at',
            'fields' => [
                'colony_id' => 'int', 'harvested_at' => 'date',
                'honey_type' => 'string', 'frames_count' => 'int',
                'gross_kg' => 'float', 'net_kg' => 'float',
                'water_content' => 'float', 'jars_count' => 'int',
                'batch_no' => 'string', 'notes' => 'string',
            ],
            'owner'   => 'user_id',
            'filters' => ['colony_id' => 'int'],
        ],
        'events' => [
            'table'  => 'events',
            'order'  => '{a}.event_at DESC, {a}.id DESC',
            'date'   => 'event_at',
            'fields' => [
                'colony_id' => 'int', 'apiary_id' => 'int', 'event_at' => 'datetime',
                'event_type' => 'string', 'title' => 'string', 'notes' => 'string',
            ],
            'owner'   => 'user_id',
            'filters' => ['colony_id' => 'int', 'apiary_id' => 'int'],
        ],
        'tasks' => [
            'table'  => 'tasks',
            'order'  => "{a}.status ASC, COALESCE({a}.due_date, '2099-12-31') ASC, {a}.id DESC",
            'date'   => 'due_date',
            'fields' => [
                'apiary_id' => 'int', 'colony_id' => 'int', 'user_id' => 'int',
                'title' => 'string', 'description' => 'string', 'due_date' => 'date',
                'priority' => 'string', 'status' => 'string', 'done_at' => 'datetime',
            ],
            'owner'   => 'created_by',
            'filters' => ['colony_id' => 'int', 'apiary_id' => 'int', 'status' => 'string'],
        ],
    ];
}

function entity_def(string $name): array
{
    $all = entities();
    if (!isset($all[$name])) {
        fail('unknown_entity', 404);
    }
    return $all[$name];
}

/** SELECT with the joins that make a row readable on its own. */
function entity_select(string $name, array $def): string
{
    $t = $def['table'];
    switch ($name) {
        case 'colonies':
            return "SELECT c.*, a.name AS apiary_name,
                           q.id AS queen_id, q.birth_year AS queen_year,
                           q.marking_color AS queen_color, q.race AS queen_race,
                           (SELECT MAX(i.inspected_at) FROM inspections i WHERE i.colony_id = c.id) AS last_inspection
                    FROM colonies c
                    LEFT JOIN apiaries a ON a.id = c.apiary_id
                    LEFT JOIN queens q ON q.colony_id = c.id AND q.is_current = 1";
        case 'apiaries':
            return "SELECT a.*,
                           (SELECT COUNT(*) FROM colonies c WHERE c.apiary_id = a.id AND c.status = 'active') AS colony_count
                    FROM apiaries a";
        case 'events':
            // An event may name an apiary instead of a colony, so resolve the
            // apiary from the colony first and from the event itself after.
            return "SELECT x.*, c.name AS colony_name, a.name AS apiary_name,
                           COALESCE(NULLIF(u.full_name, ''), u.username) AS user_name
                    FROM events x
                    LEFT JOIN colonies c ON c.id = x.colony_id
                    LEFT JOIN apiaries a ON a.id = COALESCE(c.apiary_id, x.apiary_id)
                    LEFT JOIN users u ON u.id = x.user_id";
        case 'tasks':
            return "SELECT t.*, c.name AS colony_name, a.name AS apiary_name, COALESCE(NULLIF(u.full_name, ''), u.username) AS assignee_name
                    FROM tasks t
                    LEFT JOIN colonies c ON c.id = t.colony_id
                    LEFT JOIN apiaries a ON a.id = t.apiary_id
                    LEFT JOIN users u ON u.id = t.user_id";
        case 'queens':
            return "SELECT q.*, c.name AS colony_name FROM queens q
                    LEFT JOIN colonies c ON c.id = q.colony_id";
        default:
            return "SELECT x.*, c.name AS colony_name, a.name AS apiary_name, COALESCE(NULLIF(u.full_name, ''), u.username) AS user_name
                    FROM {$t} x
                    LEFT JOIN colonies c ON c.id = x.colony_id
                    LEFT JOIN apiaries a ON a.id = c.apiary_id
                    LEFT JOIN users u ON u.id = x.user_id";
    }
}

function entity_alias(string $name): string
{
    switch ($name) {
        case 'colonies': return 'c';
        case 'apiaries': return 'a';
        case 'tasks':    return 't';
        case 'queens':   return 'q';
        default:         return 'x';
    }
}

function handle_list(string $name): void
{
    require_login();
    $def   = entity_def($name);
    $alias = entity_alias($name);
    $sql   = entity_select($name, $def);
    // Not optional and not per entity: one rule, applied before any filter
    // the client asked for.
    $where = [visible_sql($name, $alias)];
    $args  = [];

    foreach (($def['filters'] ?? []) as $field => $type) {
        $v = param($field, null);
        if ($v !== null && $v !== '' && $v !== 'all') {
            $where[] = "{$alias}.{$field} = ?";
            $args[]  = clean_value($v, $type);
        }
    }

    if (!empty($def['date'])) {
        $from = param('date_from');
        $to   = param('date_to');
        if ($from) { $where[] = "DATE({$alias}.{$def['date']}) >= ?"; $args[] = substr((string)$from, 0, 10); }
        if ($to)   { $where[] = "DATE({$alias}.{$def['date']}) <= ?"; $args[] = substr((string)$to, 0, 10); }
    }

    $id = param_int('id');
    if ($id) {
        $where[] = "{$alias}.id = ?";
        $args[]  = $id;
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . str_replace('{a}', $alias, $def['order']);
    $limit = param_int('limit', 500);
    $sql  .= ' LIMIT ' . max(1, min(5000, (int)$limit));

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    ok($stmt->fetchAll());
}

function handle_save(string $name): void
{
    $user = require_login();
    require_csrf();
    $def  = entity_def($name);
    $data = (array)param('record', []);
    $id   = isset($data['id']) && $data['id'] !== '' ? (int)$data['id'] : 0;

    // What may be changed is decided by ownership, not by a role on the
    // account: an existing row must be one the user may edit, and a new one
    // must go somewhere they may write to.
    if ($id > 0) {
        $existing = load_row($def['table'], $id);
        if (!$existing) {
            fail('missing_id', 404);
        }
        assert_can_edit($name, $existing);
        // Moving a record elsewhere needs write access at the destination too,
        // otherwise it could be pushed into a stranger's colony.
        assert_can_create($name, array_merge($existing, $data));
    } else {
        assert_can_create($name, $data);
    }

    $cols = [];
    $vals = [];
    foreach ($def['fields'] as $field => $type) {
        if (array_key_exists($field, $data)) {
            $cols[] = $field;
            $vals[] = clean_value($data[$field], $type);
        }
    }
    if (!$cols) {
        fail('nothing_to_save');
    }

    if ($id > 0) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $vals[] = $id;
        $stmt = db()->prepare("UPDATE {$def['table']} SET {$set} WHERE id = ?");
        $stmt->execute($vals);
        log_activity('update', $name, $id);
    } else {
        if (!empty($def['owner'])) {
            $cols[] = $def['owner'];
            $vals[] = $user['id'];
        }
        // The owner comes from the session, never from the request.
        if (!empty($def['owned'])) {
            $cols[] = 'owner_id';
            $vals[] = $user['id'];
        }
        $ph   = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = db()->prepare("INSERT INTO {$def['table']} (" . implode(', ', $cols) . ") VALUES ({$ph})");
        $stmt->execute($vals);
        $id = (int)db()->lastInsertId();
        log_activity('create', $name, $id);
    }

    // A task remembers when it was completed - and forgets again when it is
    // reopened, so a second completion does not keep the first date.
    if ($name === 'tasks' && array_key_exists('status', $data)) {
        if ($data['status'] === 'done' && empty($data['done_at'])) {
            db()->prepare('UPDATE tasks SET done_at = NOW() WHERE id = ? AND done_at IS NULL')->execute([$id]);
        } elseif ($data['status'] !== 'done') {
            db()->prepare('UPDATE tasks SET done_at = NULL WHERE id = ?')->execute([$id]);
        }
    }

    // A colony has exactly one current queen.
    if ($name === 'queens' && !empty($data['is_current']) && !empty($data['colony_id'])) {
        $stmt = db()->prepare('UPDATE queens SET is_current = 0 WHERE colony_id = ? AND id <> ?');
        $stmt->execute([(int)$data['colony_id'], $id]);
    }

    ok(['id' => $id]);
}

function handle_delete(string $name): void
{
    require_login();
    require_csrf();
    $def = entity_def($name);
    $id  = param_int('id', 0);
    if (!$id) {
        fail('missing_id');
    }
    $existing = load_row($def['table'], $id);
    if (!$existing) {
        fail('missing_id', 404);
    }
    assert_can_edit($name, $existing);

    $stmt = db()->prepare("DELETE FROM {$def['table']} WHERE id = ?");
    $stmt->execute([$id]);
    log_activity('delete', $name, $id);
    ok(['deleted' => $stmt->rowCount()]);
}
