<?php
/**
 * Who may see and change what.
 *
 * Everything in the journal hangs off an apiary or a colony, and those two
 * carry the ownership: an owner, and optionally the group they are shared
 * with. Records below a colony - inspections, feedings, treatments, harvests -
 * take their visibility from that colony, so there is exactly one rule rather
 * than one per table.
 *
 * The point of this file is that no query anywhere composes that rule for
 * itself. Every read goes through visible_sql() and every write through
 * assert_can_*(), so a new entity cannot quietly ship without a check.
 *
 * Group roles:
 *   owner   - manages the group, invites and removes members
 *   member  - may create and change records in the group
 *   viewer  - may read only
 * A user always owns and may always change their own records, whatever their
 * role in any group. Being an administrator says nothing about seeing data;
 * it is about managing accounts and the installation.
 */

declare(strict_types=1);

const GROUP_ROLES_WRITE = ['owner', 'member'];

function me_id(): int
{
    return (int)(current_user()['id'] ?? 0);
}

/** [group_id => role] for the signed in user, read once per request. */
function my_groups(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (me_id() > 0) {
            $stmt = db()->prepare('SELECT group_id, role FROM group_members WHERE user_id = ?');
            $stmt->execute([me_id()]);
            foreach ($stmt->fetchAll() as $row) {
                $cache[(int)$row['group_id']] = (string)$row['role'];
            }
        }
    }
    return $cache;
}

/** Group ids the user belongs to, optionally limited to certain roles. */
function my_group_ids(?array $roles = null): array
{
    $groups = my_groups();
    if ($roles !== null) {
        $groups = array_filter($groups, fn($r) => in_array($r, $roles, true));
    }
    return array_map('intval', array_keys($groups));
}

function my_role_in(int $groupId): ?string
{
    return my_groups()[$groupId] ?? null;
}

/**
 * SQL for "this row is visible to me", for a table carrying owner_id and
 * group_id under the given alias.
 *
 * The values are the session user id and group ids straight from the
 * database, cast to int, so they are put into the statement directly - a
 * fragment with placeholders would have to be threaded through every caller
 * along with its parameters, which is exactly the kind of bookkeeping that
 * gets forgotten.
 */
function owned_sql(string $alias): string
{
    return owned_sql_for($alias, me_id(), my_group_ids());
}

/** The same rule with everything passed in, so it can be tested directly. */
function owned_sql_for(string $alias, int $me, array $groupIds): string
{
    $sql = "{$alias}.owner_id = {$me}";
    $ids = array_map('intval', $groupIds);
    if ($ids) {
        $sql .= " OR {$alias}.group_id IN (" . implode(',', $ids) . ')';
    }
    return '(' . $sql . ')';
}

/**
 * The visibility condition for one entity.
 *
 * $self is the alias of the entity's own table, which differs between the
 * listing queries and the report queries; the colony and apiary aliases are
 * 'c' and 'a' everywhere.
 */
function visible_sql(string $entity, string $self = 'x'): string
{
    switch ($entity) {
        case 'apiaries':
            return owned_sql('a');

        case 'colonies':
            return owned_sql('c');

        // Hang off a colony and nothing else.
        case 'queens':
        case 'inspections':
        case 'feedings':
        case 'treatments':
        case 'harvests':
            return owned_sql('c');

        // May belong to a colony, to an apiary, or to neither - in which case
        // whoever wrote it can see it.
        case 'events':
            return '(' . owned_sql('c') . ' OR ' . owned_sql('a')
                 . " OR {$self}.user_id = " . me_id() . ')';

        case 'tasks':
            return '(' . owned_sql('c') . ' OR ' . owned_sql('a')
                 . " OR {$self}.created_by = " . me_id()
                 . " OR {$self}.user_id = " . me_id() . ')';

        default:
            // Refusing beats guessing: an entity nobody taught this function
            // about must not become world readable by accident.
            fail('forbidden', 403);
    }
}

/* ------------------------------------------------------- write access ---- */

/** May I put things into this group - or keep them private (null)? */
function can_use_group(?int $groupId): bool
{
    return can_use_group_with(my_groups(), $groupId);
}

/** May I change a row that is owned by $ownerId and shared with $groupId? */
function can_write_row(?int $ownerId, ?int $groupId): bool
{
    return can_write_with(me_id(), my_groups(), $ownerId, $groupId);
}

/* The two rules below decide every write in the application. They take the
   membership map as an argument rather than reading it, so the truth table
   can be pinned down in tests without a database. */

function can_use_group_with(array $myGroups, ?int $groupId): bool
{
    if ($groupId === null) {
        return true;   // keeping something to yourself is always allowed
    }
    return in_array($myGroups[$groupId] ?? null, GROUP_ROLES_WRITE, true);
}

function can_write_with(int $me, array $myGroups, ?int $ownerId, ?int $groupId): bool
{
    if ($ownerId !== null && (int)$ownerId === $me && $me > 0) {
        return true;   // your own records are always yours to change
    }
    if ($groupId === null) {
        return false;  // private and not yours
    }
    return in_array($myGroups[(int)$groupId] ?? null, GROUP_ROLES_WRITE, true);
}

/** Whether a row is readable: ownership, or any membership at all. */
function can_read_with(int $me, array $myGroups, ?int $ownerId, ?int $groupId): bool
{
    if ($ownerId !== null && (int)$ownerId === $me && $me > 0) {
        return true;
    }
    return $groupId !== null && isset($myGroups[(int)$groupId]);
}

function load_row(string $table, int $id): ?array
{
    // $table only ever comes from the entity definitions in entities.php.
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The apiary or colony a row hangs off, with its owner and group. */
function owner_of(string $entity, array $row): array
{
    switch ($entity) {
        case 'apiaries':
        case 'colonies':
            return ['owner' => $row['owner_id'] ?? null, 'group' => $row['group_id'] ?? null];

        case 'queens':
        case 'inspections':
        case 'feedings':
        case 'treatments':
        case 'harvests':
            $colony = empty($row['colony_id']) ? null : load_row('colonies', (int)$row['colony_id']);
            return $colony
                ? ['owner' => $colony['owner_id'], 'group' => $colony['group_id']]
                : ['owner' => null, 'group' => null];

        case 'events':
        case 'tasks':
            if (!empty($row['colony_id']) && ($colony = load_row('colonies', (int)$row['colony_id']))) {
                return ['owner' => $colony['owner_id'], 'group' => $colony['group_id']];
            }
            if (!empty($row['apiary_id']) && ($apiary = load_row('apiaries', (int)$row['apiary_id']))) {
                return ['owner' => $apiary['owner_id'], 'group' => $apiary['group_id']];
            }
            // Free-standing note or task: it belongs to whoever made it.
            return ['owner' => $row['user_id'] ?? ($row['created_by'] ?? null), 'group' => null];

        default:
            fail('forbidden', 403);
    }
}

/** Refuse unless the existing row may be changed by the signed in user. */
function assert_can_edit(string $entity, array $row): void
{
    $o = owner_of($entity, $row);
    if (!can_write_row($o['owner'] === null ? null : (int)$o['owner'],
                       $o['group'] === null ? null : (int)$o['group'])) {
        fail('forbidden', 403);
    }
}

/**
 * Refuse unless a new row may be created where the client wants it: the
 * parent has to be writable, and a group may only be one of mine.
 */
function assert_can_create(string $entity, array $data): void
{
    if (array_key_exists('group_id', $data)
        && !can_use_group($data['group_id'] === null || $data['group_id'] === ''
            ? null : (int)$data['group_id'])) {
        fail('forbidden', 403);
    }

    // Apiaries stand on their own; everything else needs a writable parent.
    if ($entity === 'apiaries') {
        return;
    }
    if ($entity === 'colonies') {
        $apiary = empty($data['apiary_id']) ? null : load_row('apiaries', (int)$data['apiary_id']);
        if (!$apiary || !can_write_row((int)$apiary['owner_id'], $apiary['group_id'] === null ? null : (int)$apiary['group_id'])) {
            fail('forbidden', 403);
        }
        return;
    }
    assert_can_edit($entity, $data);
}

/** Refuse unless the apiary or colony behind an id may be read. */
function assert_can_read(string $table, int $id): void
{
    $row = load_row($table, $id);
    if (!$row) {
        fail('forbidden', 403);
    }
    $owner = $row['owner_id'] === null ? null : (int)$row['owner_id'];
    $group = $row['group_id'] === null ? null : (int)$row['group_id'];
    if (!can_read_with(me_id(), my_groups(), $owner, $group)) {
        fail('forbidden', 403);
    }
}
