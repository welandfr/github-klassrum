<?php
declare(strict_types=1);

/** An error whose message is safe and meant to be shown to the user. */
class UserError extends RuntimeException
{
}

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
 * Creates a team with the student as its first member, plus its repo from the template.
 * DB rows are inserted first (inside the transaction) so the unique keys block double
 * submissions; if the repo cannot be created everything is rolled back.
 */
function create_team(array $a, array $user, string $teamName): void
{
    $teamName = trim($teamName) === '' ? $user['github_login'] : trim($teamName);
    if (strlen($teamName) > 100) {
        throw new UserError('Team name is too long.');
    }
    $org = (string) setting('gh_org');
    $base = repo_name_for($a['slug'], $user['github_login']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (my_team((int) $a['id'], $user['sso_user'])) {
            throw new UserError('You are already in a team for this assignment.');
        }
        if (q('SELECT 1 FROM teams WHERE assignment_id = ? AND name = ?', [$a['id'], $teamName])->fetch()) {
            throw new UserError('A team with that name already exists.');
        }
        try {
            q('INSERT INTO teams (assignment_id, name, repo_name, created_by) VALUES (?, ?, ?, ?)',
                [$a['id'], $teamName, $base . '-' . bin2hex(random_bytes(4)), $user['sso_user']]);
            $teamId = (int) $pdo->lastInsertId();
            q('INSERT INTO team_members (team_id, assignment_id, sso_user) VALUES (?, ?, ?)',
                [$teamId, $a['id'], $user['sso_user']]);
        } catch (PDOException $e) {
            if (is_duplicate_key($e)) {
                throw new UserError('You are already in a team, or that team name was just taken. Reload and try again.');
            }
            throw $e;
        }

        // Pick the first free name: base, base-2, base-3, ...
        $repo = null;
        for ($i = 1; $i <= 20 && $repo === null; $i++) {
            $name = $i === 1 ? $base : "$base-$i";
            if (q('SELECT 1 FROM teams WHERE repo_name = ?', [$name])->fetch()) {
                continue;
            }
            [$s, $body] = gh('POST', '/repos/' . rawurlencode($a['template_owner']) . '/' . rawurlencode($a['template_repo']) . '/generate', [
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
        if ($repo === null) {
            throw new RuntimeException("No free repository name found for $base");
        }
        q('UPDATE teams SET repo_name = ?, repo_url = ? WHERE id = ?', [$repo['name'], $repo['html_url'], $teamId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    invite_member($teamId, $user['sso_user']);
}

function join_team(array $a, array $user, int $teamId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the team row so concurrent joins can't exceed the size limit.
        $team = q('SELECT * FROM teams WHERE id = ? AND assignment_id = ? FOR UPDATE', [$teamId, $a['id']])->fetch();
        if (!$team) {
            throw new UserError('That team does not exist.');
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
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    invite_member($teamId, $user['sso_user']);
}

/**
 * Adds the member's GitHub account as a collaborator (push access), which sends them an
 * invitation. Records the outcome on the membership row; returns true on success.
 */
function invite_member(int $teamId, string $sso): bool
{
    $row = q('SELECT t.repo_name, u.github_login FROM teams t, users u WHERE t.id = ? AND u.sso_user = ?',
        [$teamId, $sso])->fetch();
    $error = 'Student has no linked GitHub account';
    if ($row) {
        $path = '/repos/' . rawurlencode((string) setting('gh_org')) . '/' . rawurlencode($row['repo_name'])
            . '/collaborators/' . rawurlencode($row['github_login']);
        // A freshly generated repo may not be ready yet (404), so retry a few times.
        for ($i = 0; $i < 4; $i++) {
            [$s, $body] = gh('PUT', $path, ['permission' => 'push']);
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

/** @return array{0:int,1:int} [succeeded, failed] */
function retry_failed_invites(int $assignmentId): array
{
    $ok = $failed = 0;
    $rows = q("SELECT team_id, sso_user FROM team_members WHERE assignment_id = ? AND invite_status <> 'invited'",
        [$assignmentId])->fetchAll();
    foreach ($rows as $r) {
        invite_member((int) $r['team_id'], $r['sso_user']) ? $ok++ : $failed++;
    }
    return [$ok, $failed];
}
