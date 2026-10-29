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
    try {
        if (!$a['is_open']) {
            throw new UserError('This assignment is closed.');
        }
        if (($_POST['action'] ?? '') === 'create') {
            create_team($a, $me, (string) ($_POST['team_name'] ?? ''));
            flash('ok', 'Team and repository created.');
        } elseif (($_POST['action'] ?? '') === 'join') {
            join_team($a, $me, (int) ($_POST['team_id'] ?? 0));
            flash('ok', 'You joined the team.');
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

$team = my_team((int) $a['id'], $sso);
if ($team && $team['repo_url'] === null) {
    // Creation is still running (e.g. in another tab), or was abandoned: then clear it and offer a retry.
    delete_stale_teams();
    if ($team = my_team((int) $a['id'], $sso)) {
        echo '<p>The repository for team <b>' . h($team['name']) . '</b> is being created. Reload this page in a moment.</p>';
        page_end();
        exit;
    }
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
            . ($m['invite_status'] === 'invited' ? 'invited' : '<span class="err">failed</span>') . '</td></tr>';
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

$size = (int) $a['max_team_size'];
echo '<fieldset><legend>Create a new team</legend><form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="create">';
if ($size > 1) {
    echo '<label for="team_name">Team name</label>'
        . '<input type="text" id="team_name" name="team_name" maxlength="100" placeholder="' . h($me['github_login']) . '">';
}
echo '<p class="muted">Your repository will be named <code>' . h(repo_name_for($a['slug'], $me['github_login'])) . '</code>.</p>'
    . '<button>' . ($size > 1 ? 'Create team' : 'Create my repository') . '</button></form></fieldset>';

if ($size > 1) {
    // Members in join order; an ordered subquery instead of group_concat(... ORDER BY), which needs SQLite 3.44.
    $teams = q('SELECT t.id, t.name,
                       (SELECT COUNT(*) FROM team_members m WHERE m.team_id = t.id) AS n,
                       (SELECT group_concat(member, \', \') FROM (
                           SELECT m.sso_user || COALESCE(\' (\' || u.github_login || \')\', \'\') AS member FROM team_members m
                           LEFT JOIN users u ON u.sso_user = m.sso_user WHERE m.team_id = t.id ORDER BY m.joined_at)) AS members
                FROM teams t WHERE t.assignment_id = ? AND t.repo_url IS NOT NULL ORDER BY t.name', [$a['id']])->fetchAll();
    echo '<h2>Or join an existing team</h2>';
    if (!$teams) {
        echo '<p class="muted">No teams yet.</p>';
    } else {
        echo '<table><tr><th>Team</th><th>Members</th><th></th></tr>';
        foreach ($teams as $t) {
            $full = (int) $t['n'] >= $size;
            echo '<tr><td>' . h($t['name']) . '</td><td>' . h($t['members']) . " ({$t['n']}/$size)</td><td>"
                . '<form method="post">' . csrf_field()
                . '<input type="hidden" name="action" value="join"><input type="hidden" name="team_id" value="' . $t['id'] . '">'
                . '<button' . ($full ? ' disabled>Full' : '>Join') . '</button></form></td></tr>';
        }
        echo '</table>';
    }
}
page_end();
