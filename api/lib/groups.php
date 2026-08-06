<?php
/**
 * Groups: several beekeepers working the same colonies.
 *
 * A group is created by whoever needs it, and that person is its owner.
 * Members are added by e-mail invitation rather than by picking from a list of
 * accounts - once accounts can be private there must be no directory of
 * everyone registered here, and an invitation also reaches somebody who has no
 * account yet.
 *
 * Leaving a group takes nothing away: the apiaries and colonies a member had
 * shared with it fall back to being private to their owner, and the records
 * others wrote at those colonies stay where they are, because they belong to
 * the colony rather than to whoever typed them.
 */

declare(strict_types=1);

const INVITE_TTL_DAYS = 14;

function group_row(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM user_groups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Refuse unless the signed in user owns this group. */
function require_group_owner(int $groupId): array
{
    $group = group_row($groupId);
    if (!$group || my_role_in($groupId) !== 'owner') {
        fail('forbidden', 403);
    }
    return $group;
}

/** GET-ish: the groups I belong to, with my role and the member count. */
function handle_groups_list(): void
{
    require_login();
    $ids = my_group_ids();
    if (!$ids) {
        ok([]);
    }
    $sql = 'SELECT g.id, g.name, g.description, g.created_at,
                   (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS member_count
            FROM user_groups g
            WHERE g.id IN (' . implode(',', $ids) . ')
            ORDER BY g.name';
    $rows = db()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['my_role'] = my_role_in((int)$row['id']);
    }
    ok($rows);
}

function handle_groups_save(): void
{
    $me = require_login();
    require_csrf();
    $rec  = (array)param('record', []);
    $id   = isset($rec['id']) && $rec['id'] !== '' ? (int)$rec['id'] : 0;
    $name = trim((string)($rec['name'] ?? ''));
    $desc = trim((string)($rec['description'] ?? ''));

    if ($name === '') {
        fail('group_name_required');
    }

    if ($id > 0) {
        require_group_owner($id);
        db()->prepare('UPDATE user_groups SET name = ?, description = ? WHERE id = ?')
            ->execute([$name, $desc !== '' ? $desc : null, $id]);
        log_activity('group_update', 'groups', $id, $name);
        ok(['id' => $id]);
    }

    db()->prepare('INSERT INTO user_groups (name, description, created_by) VALUES (?, ?, ?)')
        ->execute([$name, $desc !== '' ? $desc : null, $me['id']]);
    $id = (int)db()->lastInsertId();
    // Whoever creates a group owns it.
    db()->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
        ->execute([$id, $me['id'], 'owner']);
    log_activity('group_create', 'groups', $id, $name);
    ok(['id' => $id]);
}

/**
 * Deleting a group does not delete anything that was shared with it: the rows
 * simply become private again through the ON DELETE SET NULL on group_id.
 */
function handle_groups_delete(): void
{
    require_login();
    require_csrf();
    $id = param_int('id', 0);
    if (!$id) {
        fail('missing_id');
    }
    require_group_owner($id);
    db()->prepare('DELETE FROM user_groups WHERE id = ?')->execute([$id]);
    log_activity('group_delete', 'groups', $id);
    ok(['deleted' => true]);
}

function handle_group_members(): void
{
    require_login();
    $id = param_int('group_id', 0);
    if (!$id || my_role_in($id) === null) {
        fail('forbidden', 403);
    }
    $stmt = db()->prepare(
        "SELECT m.user_id, m.role, m.joined_at,
                COALESCE(NULLIF(u.full_name, ''), u.username) AS name
         FROM group_members m
         JOIN users u ON u.id = m.user_id
         WHERE m.group_id = ?
         ORDER BY m.role = 'owner' DESC, name"
    );
    $stmt->execute([$id]);
    $members = $stmt->fetchAll();

    $invites = [];
    if (my_role_in($id) === 'owner') {
        $stmt = db()->prepare(
            'SELECT id, email, role, expires_at FROM group_invites
             WHERE group_id = ? AND accepted_at IS NULL AND expires_at > NOW()
             ORDER BY created_at DESC'
        );
        $stmt->execute([$id]);
        $invites = $stmt->fetchAll();
    }
    ok(['members' => $members, 'invites' => $invites, 'my_role' => my_role_in($id)]);
}

function handle_group_member_save(): void
{
    require_login();
    require_csrf();
    $groupId = param_int('group_id', 0);
    $userId  = param_int('user_id', 0);
    $role    = (string)param('role', 'member');
    if (!$groupId || !$userId || !in_array($role, ['owner', 'member', 'viewer'], true)) {
        fail('missing_id');
    }
    require_group_owner($groupId);
    if ($userId === me_id() && $role !== 'owner' && count_group_owners($groupId) <= 1) {
        fail('last_group_owner');
    }
    db()->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?')
        ->execute([$role, $groupId, $userId]);
    log_activity('group_member_role', 'groups', $groupId, $role);
    ok(['saved' => true]);
}

function count_group_owners(int $groupId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND role = 'owner'");
    $stmt->execute([$groupId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Remove a member, or leave the group yourself.
 *
 * Whatever they had shared with the group becomes private to them again -
 * nobody loses data by leaving, and the group stops seeing it immediately.
 */
function handle_group_member_remove(): void
{
    require_login();
    require_csrf();
    $groupId = param_int('group_id', 0);
    $userId  = param_int('user_id', 0) ?: me_id();
    if (!$groupId) {
        fail('missing_id');
    }
    if ($userId !== me_id()) {
        require_group_owner($groupId);
    } elseif (my_role_in($groupId) === null) {
        fail('forbidden', 403);
    }
    if (my_role_in($groupId) === 'owner' && $userId === me_id() && count_group_owners($groupId) <= 1) {
        fail('last_group_owner');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')
            ->execute([$groupId, $userId]);
        foreach (['apiaries', 'colonies'] as $table) {
            $pdo->prepare("UPDATE {$table} SET group_id = NULL WHERE group_id = ? AND owner_id = ?")
                ->execute([$groupId, $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    log_activity('group_member_remove', 'groups', $groupId, (string)$userId);
    ok(['removed' => true]);
}

/* ----------------------------------------------------------- invites ---- */

function handle_group_invite(): void
{
    $me = require_login();
    require_csrf();
    $groupId = param_int('group_id', 0);
    $email   = trim((string)param('email', ''));
    $role    = (string)param('role', 'member');
    if (!$groupId || !in_array($role, ['owner', 'member', 'viewer'], true)) {
        fail('missing_id');
    }
    $group = require_group_owner($groupId);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('invalid_email');
    }
    if (!mail_enabled()) {
        fail('mail_not_configured');
    }

    // Someone already in the group does not need an invitation.
    $stmt = db()->prepare(
        'SELECT 1 FROM group_members m JOIN users u ON u.id = m.user_id
         WHERE m.group_id = ? AND u.email = ?'
    );
    $stmt->execute([$groupId, $email]);
    if ($stmt->fetchColumn()) {
        fail('already_member');
    }

    // Only the newest invitation per address stays valid.
    db()->prepare(
        'UPDATE group_invites SET expires_at = NOW()
         WHERE group_id = ? AND email = ? AND accepted_at IS NULL'
    )->execute([$groupId, $email]);

    $token = bin2hex(random_bytes(32));
    db()->prepare(
        'INSERT INTO group_invites (group_id, email, role, token_hash, invited_by, expires_at)
         VALUES (?, ?, ?, ?, ?, (NOW() + INTERVAL ' . INVITE_TTL_DAYS . ' DAY))'
    )->execute([$groupId, $email, $role, hash('sha256', $token), $me['id']]);

    $link    = reset_base_url() . '/index.html#/invite/' . $token;
    $inviter = trim((string)($me['full_name'] ?? '')) ?: (string)$me['username'];

    if (($me['locale'] ?? 'de') === 'en') {
        $subject = 'You have been invited to an Apiary-Journal group';
        $body = "Hello,\n\n{$inviter} invites you to the group \"{$group['name']}\" in Apiary-Journal.\n"
              . "Open this link to join:\n\n{$link}\n\n"
              . 'The invitation is valid for ' . INVITE_TTL_DAYS . " days.\n"
              . "If you have no account yet, the link will let you create one.\n\n"
              . "If you do not know what this is about, simply ignore this mail.\n";
    } else {
        $subject = 'Einladung zu einer Gruppe im Apiary-Journal';
        $body = "Hallo,\n\n{$inviter} lädt dich zur Gruppe \"{$group['name']}\" im Apiary-Journal ein.\n"
              . "Über diesen Link trittst du bei:\n\n{$link}\n\n"
              . 'Die Einladung gilt ' . INVITE_TTL_DAYS . " Tage.\n"
              . "Falls du noch kein Konto hast, kannst du dir über den Link eines anlegen.\n\n"
              . "Wenn du damit nichts anfangen kannst, ignoriere diese Nachricht einfach.\n";
    }

    if (!send_mail($email, $subject, $body)) {
        fail('mail_failed', 500);
    }
    log_activity('group_invite', 'groups', $groupId, $email);
    ok(['invited' => true]);
}

function handle_group_invite_revoke(): void
{
    require_login();
    require_csrf();
    $id = param_int('id', 0);
    if (!$id) {
        fail('missing_id');
    }
    $stmt = db()->prepare('SELECT group_id FROM group_invites WHERE id = ?');
    $stmt->execute([$id]);
    $groupId = $stmt->fetchColumn();
    if ($groupId === false) {
        fail('missing_id', 404);
    }
    require_group_owner((int)$groupId);
    db()->prepare('DELETE FROM group_invites WHERE id = ?')->execute([$id]);
    ok(['revoked' => true]);
}

/** What an invitation link points at, before the recipient commits to it. */
function handle_invite_preview(): void
{
    $invite = invite_by_token((string)param('token', ''));
    $group  = group_row((int)$invite['group_id']);
    ok([
        'group'      => $group['name'] ?? '',
        'role'       => $invite['role'],
        'email'      => $invite['email'],
        'signed_in'  => me_id() > 0,
    ]);
}

function invite_by_token(string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        fail('invite_invalid', 400);
    }
    $stmt = db()->prepare(
        'SELECT * FROM group_invites
         WHERE token_hash = ? AND accepted_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        fail('invite_invalid', 400);
    }
    return $row;
}

/** Accept an invitation. The recipient has to be signed in at this point. */
function handle_invite_accept(): void
{
    $me = require_login();
    require_csrf();
    $invite  = invite_by_token((string)param('token', ''));
    $groupId = (int)$invite['group_id'];

    $pdo = db();
    $pdo->prepare(
        'INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role)'
    )->execute([$groupId, $me['id'], $invite['role']]);
    $pdo->prepare('UPDATE group_invites SET accepted_at = NOW() WHERE id = ?')
        ->execute([$invite['id']]);

    log_activity('group_join', 'groups', $groupId);
    ok(['group_id' => $groupId, 'group' => group_row($groupId)['name'] ?? '']);
}
