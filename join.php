<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$sso = sso_user();
require_configured();

$code = (string) ($_GET['code'] ?? '');
$a = q('SELECT * FROM assignments WHERE invite_code = ?', [$code])->fetch();
if (!$a) {
    http_response_code(404);
    page_start('Not found');
    echo '<p>This join link is not valid. Check the link from your teacher.</p>';
    page_end();
    exit;
}
$self = 'join.php?code=' . $a['invite_code'];
$me = linked_user($sso);

if ($me && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if (!$a['is_open'] && $action !== 'create_repo') {
            throw new UserError('This assignment is closed.');
        }
        if ($action === 'create') {
            create_team($a, $me, (string) ($_POST['team_name'] ?? ''));
            flash('ok', (int) $a['max_team_size'] > 1 ? 'Team created.' : 'Repository created.');
        } elseif ($action === 'join') {
            join_team($a, $me, (int) ($_POST['team_id'] ?? 0));
            flash('ok', 'You joined the team.');
        } elseif ($action === 'create_repo') {
            $team = my_team((int) $a['id'], $sso);
            if (!$team) {
                throw new UserError('You are not in a team for this assignment.');
            }
            create_repo((int) $team['id']);
            flash('ok', 'Repository created.');
        }
    } catch (UserError $e) {
        flash('err', $e->getMessage());
    }
    redirect($self);
}

page_start($a['title']);
if ($me) {
    echo '<p class="muted">GitHub: ' . h($me['github_login']) . '</p>';
}

if (!$me) {
    echo '<p>First, connect your GitHub account so you can be given access to your repository.</p>'
        . '<p><a href="oauth.php?return=' . h(urlencode($self)) . '"><button>Connect GitHub account</button></a></p>';
    page_end();
    exit;
}

$size = (int) $a['max_team_size'];
$team = my_team((int) $a['id'], $sso);
if ($team && $team['repo_url'] === null && $team['creating_since'] !== null) {
    // Creation is still running (e.g. in another tab), or was abandoned: then reset it and offer a retry.
    reset_stale_creations();
    $team = my_team((int) $a['id'], $sso);
    if ($team['creating_since'] !== null) {
        echo '<p>The repository for team <b>' . h($team['name']) . '</b> is being created. Reload this page in a moment.</p>';
        page_end();
        exit;
    }
}
if ($team && $team['repo_url'] === null) {
    $members = team_members((int) $team['id']);
    echo '<p>You are in team <b>' . h($team['name']) . '</b> (' . count($members) . "/$size). It has no repository yet.</p>"
        . '<table><tr><th>Member</th><th>GitHub</th></tr>';
    foreach ($members as $m) {
        echo '<tr><td>' . h($m['sso_user']) . '</td><td>' . h($m['github_login']) . '</td></tr>';
    }
    echo '</table><p>The repository is created automatically when the team is full. If your team is complete with fewer members, '
        . 'any member can create it now. It will be named <code>'
        . h(repo_name_for($a['slug'], array_column($members, 'sso_user'))) . '</code>; members who join later are '
        . 'still given access, but the name stays.</p>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="create_repo">'
        . '<button>Our team is ready – create the repository</button></form>';
    page_end();
    exit;
}
if ($team) {
    echo '<p>You are in team <b>' . h($team['name']) . '</b>.</p>'
        . '<p>Repository: <a href="' . h($team['repo_url']) . '">' . h($team['repo_url']) . '</a></p>';
    $mine = null;
    echo '<table><tr><th>Member</th><th>GitHub</th><th>Access</th></tr>';
    foreach (team_members((int) $team['id']) as $m) {
        if ($m['sso_user'] === $sso) {
            $mine = $m;
        }
        echo '<tr><td>' . h($m['sso_user']) . '</td><td>' . h($m['github_login']) . '</td><td>'
            . match ($m['invite_status']) { 'invited' => 'invited', 'failed' => '<span class="err">failed</span>', default => 'pending' } . '</td></tr>';
    }
    echo '</table>';
    if ($mine && $mine['invite_status'] === 'invited') {
        echo '<p><b>Accept your invitation:</b> <a href="' . h($team['repo_url']) . '/invitations">'
            . h($team['repo_url']) . '/invitations</a> (the repo shows 404 until you accept).</p>';
    } else {
        echo '<p class="err">Your GitHub invitation could not be sent. Contact the teacher.</p>';
    }
    page_end();
    exit;
}

if (!$a['is_open']) {
    echo '<p>This assignment is closed for new teams.</p>';
    page_end();
    exit;
}

echo '<fieldset><legend>Create a new team</legend><form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="create">';
if ($size > 1) {
    echo '<label for="team_name">Team name</label>'
        . '<input type="text" id="team_name" name="team_name" maxlength="100" placeholder="' . h($sso) . '">';
}
echo '<p class="muted">' . ($size > 1
        ? 'Others can then join your team. The repository is created when the team is full, or earlier by any member, '
            . 'named after the members, e.g. <code>' . h(repo_name_for($a['slug'], [$sso, 'username2'])) . '</code>.'
        : 'Your repository will be named <code>' . h(repo_name_for($a['slug'], [$sso])) . '</code>.') . '</p>'
    . '<button>' . ($size > 1 ? 'Create team' : 'Create my repository') . '</button></form></fieldset>';

if ($size > 1) {
    // Members in join order; an ordered subquery instead of group_concat(... ORDER BY), which needs SQLite 3.44.
    $teams = q('SELECT t.id, t.name, t.repo_url,
                       (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id) AS n,
                       (SELECT group_concat(member, \', \') FROM (
                           SELECT m.sso_user || COALESCE(\' (\' || u.github_login || \')\', \'\') AS member FROM team_members m
                           LEFT JOIN users u ON u.sso_user = m.sso_user WHERE m.team_id = t.id ORDER BY m.joined_at)) AS members
                FROM teams t WHERE t.assignment_id = ? AND (t.repo_url IS NOT NULL OR t.creating_since IS NULL) ORDER BY t.name', [$a['id']])->fetchAll();
    echo '<h2>Or join an existing team</h2>';
    if (!$teams) {
        echo '<p class="muted">No teams yet.</p>';
    } else {
        echo '<table><tr><th>Team</th><th>Members</th><th></th></tr>';
        foreach ($teams as $t) {
            $full = (int) $t['n'] >= $size;
            echo '<tr><td>' . h($t['name']) . ($t['repo_url'] === null ? '' : ' <span class="muted">(repository created)</span>')
                . '</td><td>' . h($t['members']) . " ({$t['n']}/$size)</td><td>"
                . '<form method="post">' . csrf_field()
                . '<input type="hidden" name="action" value="join"><input type="hidden" name="team_id" value="' . $t['id'] . '">'
                . '<button' . ($full ? ' disabled>Full' : '>Join') . '</button></form></td></tr>';
        }
        echo '</table>';
    }
}
page_end();
