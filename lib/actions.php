<?php
declare(strict_types=1);

/** An error whose message is safe and meant to be shown to the user. */
class UserError extends RuntimeException
{
}

/** A team whose repo still isn't created after this long was abandoned mid-creation. */
const STALE_TEAM_MINUTES = 10;

function linked_user(string $sso): ?array
{
    return q('SELECT * FROM users WHERE sso_user = ?', [$sso])->fetch() ?: null;
}

function my_team(int $assignmentId, string $sso): ?array
{
    return q('SELECT t.* FROM teams t JOIN team_members m ON m.team_id = t.id
              WHERE m.assignment_id = ? AND m.sso_user = ?', [$assignmentId, $sso])->fetch() ?: null;
}

function team_members(int $teamId): array
{
    return q('SELECT m.*, u.github_login FROM team_members m LEFT JOIN users u USING (sso_user)
              WHERE m.team_id = ? ORDER BY m.joined_at', [$teamId])->fetchAll();
}

function repo_name_for(string $slug, string $login): string
{
    return strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', "$slug-$login"));
}

/**
 * Removes teams whose repo creation never finished (e.g. the request was killed), so their
 * members can try again. A repo GitHub did create for one is left there; see README.
 */
function delete_stale_teams(): void
{
    q("DELETE FROM teams WHERE repo_url IS NULL AND created_at < datetime('now', ?)",
        ['-' . STALE_TEAM_MINUTES . ' minutes']);
}

/**
 * Creates a team with the student as its first member, plus its repo from the template.
 * The team is reserved first in a short transaction, so the unique keys block double
 * submissions; it stays pending (repo_url NULL, hidden from the join list) while GitHub
 * creates the repo, and is deleted again if that fails.
 */
function create_team(array $a, array $user, string $teamName): void
{
    $teamName = trim($teamName) === '' ? $user['github_login'] : trim($teamName);
    if (strlen($teamName) > 100) {
        throw new UserError('Team name is too long.');
    }
    $org = $a['org'];
    $base = repo_name_for($a['slug'], $user['github_login']);
    ignore_user_abort(true);  // finish, or clean up, even if the student closes the page
    gh_token($org);
    delete_stale_teams();

    $teamId = tx(function () use ($a, $user, $teamName, $org, $base): int {
        if (my_team((int) $a['id'], $user['sso_user'])) {
            throw new UserError('You are already in a team for this assignment.');
        }
        if (q('SELECT 1 FROM teams WHERE assignment_id = ? AND name = ?', [$a['id'], $teamName])->fetch()) {
            throw new UserError('A team with that name already exists.');
        }
        // Placeholder repo name until one is reserved below.
        q('INSERT INTO teams (assignment_id, name, org, repo_name, created_by) VALUES (?, ?, ?, ?, ?)',
            [$a['id'], $teamName, $org, $base . '-pending-' . bin2hex(random_bytes(4)), $user['sso_user']]);
        $teamId = (int) db()->lastInsertId();
        q('INSERT INTO team_members (team_id, assignment_id, sso_user) VALUES (?, ?, ?)',
            [$teamId, $a['id'], $user['sso_user']]);
        return $teamId;
    });

    // No transaction from here: GitHub calls take seconds, and SQLite allows one writer at a time.
    try {
        // Try base, base-2, base-3, ...: reserve each name here first (the unique key settles
        // races between students), then skip names that GitHub reports as taken.
        $repo = null;
        for ($i = 1; $repo === null; $i++) {
            if ($i > 20) {
                throw new RuntimeException("No free repository name found for $base");
            }
            $name = $i === 1 ? $base : "$base-$i";
            try {
                q('UPDATE teams SET repo_name = ? WHERE id = ?', [$name, $teamId]);
            } catch (PDOException $e) {
                if (is_duplicate_key($e)) {
                    continue;
                }
                throw $e;
            }
            [$s, $body] = gh($org, 'POST', '/repos/' . rawurlencode($a['template_owner']) . '/' . rawurlencode($a['template_repo']) . '/generate', [
                'owner' => $org,
                'name' => $name,
                'private' => true,
            ]);
            if ($s === 201) {
                $repo = $body;
            } elseif (!($s === 422 && str_contains(json_encode($body), 'already exists'))) {
                throw gh_error('Could not create the repository', $s, $body);
            }
        }
        q('UPDATE teams SET repo_name = ?, repo_url = ? WHERE id = ?', [$repo['name'], $repo['html_url'], $teamId]);
    } catch (Throwable $e) {
        q('DELETE FROM teams WHERE id = ?', [$teamId]);  // the membership goes with it (ON DELETE CASCADE)
        throw $e;
    }
    invite_member($teamId, $user['sso_user']);
}

function join_team(array $a, array $user, int $teamId): void
{
    // The write lock taken by tx() keeps concurrent joins from exceeding the size limit.
    tx(function () use ($a, $user, $teamId): void {
        $team = q('SELECT * FROM teams WHERE id = ? AND assignment_id = ?', [$teamId, $a['id']])->fetch();
        if (!$team) {
            throw new UserError('That team does not exist.');
        }
        if ($team['repo_url'] === null) {
            throw new UserError('That team\'s repository is still being created. Try again in a moment.');
        }
        $count = (int) q('SELECT COUNT(*) FROM team_members WHERE team_id = ?', [$teamId])->fetchColumn();
        if ($count >= (int) $a['max_team_size']) {
            throw new UserError('That team is already full.');
        }
        try {
            q('INSERT INTO team_members (team_id, assignment_id, sso_user) VALUES (?, ?, ?)',
                [$teamId, $a['id'], $user['sso_user']]);
        } catch (PDOException $e) {
            if (is_duplicate_key($e)) {
                throw new UserError('You are already in a team for this assignment.');
            }
            throw $e;
        }
    });
    invite_member($teamId, $user['sso_user']);
}

/**
 * Adds the member's GitHub account as a collaborator (push access), which sends them an
 * invitation. Records the outcome on the membership row; returns true on success.
 */
function invite_member(int $teamId, string $sso): bool
{
    $row = q('SELECT t.org, t.repo_name, u.github_login FROM teams t, users u
              WHERE t.id = ? AND t.repo_url IS NOT NULL AND u.sso_user = ?', [$teamId, $sso])->fetch();
    $error = 'Student has no linked GitHub account';
    if ($row) {
        $path = '/repos/' . rawurlencode($row['org']) . '/' . rawurlencode($row['repo_name'])
            . '/collaborators/' . rawurlencode($row['github_login']);
        // A freshly generated repo may not be ready yet (404), so retry a few times.
        for ($i = 0; $i < 4; $i++) {
            try {
                [$s, $body] = gh($row['org'], 'PUT', $path, ['permission' => 'push']);
            } catch (RuntimeException $e) {
                // Token or network failure: record it, so the retry button picks it up.
                $error = $e->getMessage();
                break;
            }
            if ($s === 201 || $s === 204) {
                $error = null;
                break;
            }
            $error = "HTTP $s: " . ($body['message'] ?? 'no message');
            if ($s !== 404) {
                break;
            }
            sleep(2);
        }
    }
    q('UPDATE team_members SET invite_status = ?, invite_error = ? WHERE team_id = ? AND sso_user = ?',
        [$error === null ? 'invited' : 'failed', $error === null ? null : substr($error, 0, 255), $teamId, $sso]);
    return $error === null;
}

/**
 * Removes a student from a team: on GitHub (collaborator access and any pending invitation)
 * and in the app. The app side always happens, so the student is free to join another team;
 * returns the GitHub error, if any, for the admin to fix by hand.
 */
function remove_member(int $teamId, string $sso): ?string
{
    $row = q('SELECT t.org, t.repo_name, t.repo_url, u.github_id, u.github_login, m.invite_status
              FROM team_members m JOIN teams t ON t.id = m.team_id LEFT JOIN users u ON u.sso_user = m.sso_user
              WHERE m.team_id = ? AND m.sso_user = ?', [$teamId, $sso])->fetch();
    if (!$row) {
        return null;
    }
    $error = null;
    if ($row['repo_url'] !== null && $row['github_login'] !== null && $row['invite_status'] === 'invited') {
        $repo = '/repos/' . rawurlencode($row['org']) . '/' . rawurlencode($row['repo_name']);
        try {
            [$s, $body] = gh($row['org'], 'DELETE', "$repo/collaborators/" . rawurlencode($row['github_login']));
            if ($s !== 204 && $s !== 404) {
                throw gh_error('Could not remove the collaborator', $s, $body);
            }
            [$s, $invites] = gh($row['org'], 'GET', "$repo/invitations?per_page=100");
            if ($s !== 200) {
                throw gh_error('Could not list the invitations', $s, $invites);
            }
            foreach ($invites as $inv) {
                if (($inv['invitee']['id'] ?? null) === $row['github_id']) {
                    [$s, $body] = gh($row['org'], 'DELETE', "$repo/invitations/{$inv['id']}");
                    if ($s !== 204) {
                        throw gh_error('Could not cancel the invitation', $s, $body);
                    }
                }
            }
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
    q('DELETE FROM team_members WHERE team_id = ? AND sso_user = ?', [$teamId, $sso]);
    return $error;
}

/** Deletes the team's repo on GitHub; returns the error, if any. An already missing repo counts as deleted. */
function delete_repo(array $team): ?string
{
    try {
        [$s, $body] = gh($team['org'], 'DELETE', '/repos/' . rawurlencode($team['org']) . '/' . rawurlencode($team['repo_name']));
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return $s === 204 || $s === 404 ? null : "HTTP $s: " . ($body['message'] ?? 'no message');
}

/** Removes a team and its memberships from the app only; its repo stays on GitHub. */
function delete_team(int $teamId): void
{
    q('DELETE FROM teams WHERE id = ?', [$teamId]);
}

/**
 * Compares the assignment's teams with GitHub, without changing anything. Finds deleted,
 * moved and renamed repos, members no longer on their repo (neither collaborator nor
 * invited), and expired invitations. Throws if GitHub can't be read, so a failed request
 * never looks like a removal.
 * @return list<array{type:string,team_id:int,sso_user?:string,repo_name?:string,repo_url?:string,text:string}>
 */
function github_differences(array $a): array
{
    $teams = q('SELECT * FROM teams WHERE assignment_id = ? AND repo_url IS NOT NULL ORDER BY name', [$a['id']])->fetchAll();
    $paths = [];
    foreach ($teams as $t) {
        $repo = '/repos/' . rawurlencode($t['org']) . '/' . rawurlencode($t['repo_name']);
        $paths["{$t['id']}:repo"] = $repo;
        $paths["{$t['id']}:collab"] = "$repo/collaborators?affiliation=all&per_page=100";
        $paths["{$t['id']}:inv"] = "$repo/invitations?per_page=100";
    }
    $res = $paths ? gh_get_many($a['org'], $paths) : [];

    $diffs = [];
    foreach ($teams as $t) {
        $id = (int) $t['id'];
        [$s, $repo] = $res["$id:repo"];
        if ($s === 404) {
            $diffs[] = ['type' => 'team_gone', 'team_id' => $id, 'text' => "Repo {$t['repo_name']} no longer exists: remove team {$t['name']}."];
            continue;
        }
        if ($s !== 200) {
            throw gh_error("Could not read repo {$t['repo_name']}", $s, $repo);
        }
        if (strcasecmp($repo['owner']['login'], $t['org']) !== 0) {
            $diffs[] = ['type' => 'team_gone', 'team_id' => $id, 'text' => "Repo {$t['repo_name']} was moved to {$repo['full_name']}: remove team {$t['name']}."];
            continue;
        }
        if ($repo['name'] !== $t['repo_name']) {
            $diffs[] = ['type' => 'renamed', 'team_id' => $id, 'repo_name' => $repo['name'], 'repo_url' => $repo['html_url'],
                'text' => "Repo {$t['repo_name']} was renamed to {$repo['name']}."];
        }
        foreach (['collab', 'inv'] as $k) {
            if ($res["$id:$k"][0] !== 200) {
                throw gh_error("Could not read the collaborators of {$t['repo_name']}", ...$res["$id:$k"]);
            }
        }
        $collaborators = array_column($res["$id:collab"][1], 'id');
        $expired = [];  // invitee id => invitation expired
        foreach ($res["$id:inv"][1] as $inv) {
            if (isset($inv['invitee']['id'])) {
                $expired[$inv['invitee']['id']] = (bool) ($inv['expired'] ?? false);
            }
        }
        foreach (team_members($id) as $m) {
            $ghId = q('SELECT github_id FROM users WHERE sso_user = ?', [$m['sso_user']])->fetchColumn();
            if ($m['invite_status'] !== 'invited' || $ghId === false || in_array($ghId, $collaborators, true)) {
                continue;
            }
            $who = "{$m['sso_user']} ({$m['github_login']})";
            if (!isset($expired[$ghId])) {
                $diffs[] = ['type' => 'member_gone', 'team_id' => $id, 'sso_user' => $m['sso_user'],
                    'text' => "$who no longer has access to {$repo['name']}: remove them from team {$t['name']}."];
            } elseif ($expired[$ghId]) {
                $diffs[] = ['type' => 'invite_expired', 'team_id' => $id, 'sso_user' => $m['sso_user'],
                    'text' => "The invitation of $who to {$repo['name']} expired: mark it failed, so Retry sends a new one."];
            }
        }
    }
    return $diffs;
}

/** Applies github_differences() to the app's database. */
function apply_github_differences(array $diffs): void
{
    foreach ($diffs as $d) {
        match ($d['type']) {
            'team_gone' => delete_team($d['team_id']),
            'renamed' => q('UPDATE teams SET repo_name = ?, repo_url = ? WHERE id = ?', [$d['repo_name'], $d['repo_url'], $d['team_id']]),
            'member_gone' => q('DELETE FROM team_members WHERE team_id = ? AND sso_user = ?', [$d['team_id'], $d['sso_user']]),
            'invite_expired' => q("UPDATE team_members SET invite_status = 'failed', invite_error = 'Invitation expired'
                                   WHERE team_id = ? AND sso_user = ?", [$d['team_id'], $d['sso_user']]),
        };
    }
}

/** @return array{0:int,1:int} [succeeded, failed] */
function retry_failed_invites(int $assignmentId): array
{
    $ok = $failed = 0;
    $rows = q("SELECT m.team_id, m.sso_user FROM team_members m JOIN teams t ON t.id = m.team_id
               WHERE m.assignment_id = ? AND m.invite_status <> 'invited' AND t.repo_url IS NOT NULL",
        [$assignmentId])->fetchAll();
    foreach ($rows as $r) {
        invite_member((int) $r['team_id'], $r['sso_user']) ? $ok++ : $failed++;
    }
    return [$ok, $failed];
}
