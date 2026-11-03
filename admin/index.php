<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();
if (!schema_installed()) {
    redirect('admin/settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'unlink') {
        q('DELETE FROM users WHERE sso_user = ?', [(string) $_POST['sso_user']]);
        flash('ok', 'Unlinked the GitHub account of ' . $_POST['sso_user'] . '.');
    }
    redirect('admin/');
}

$rows = q('SELECT a.*, COUNT(DISTINCT t.id) AS teams, COUNT(m.sso_user) AS students,
                  SUM(m.invite_status = \'failed\') AS failed
           FROM assignments a LEFT JOIN teams t ON t.assignment_id = a.id LEFT JOIN team_members m ON m.team_id = t.id
           GROUP BY a.id ORDER BY a.org, a.created_at DESC')->fetchAll();

page_start('Assignments');
echo pending_migrations_notice();
echo '<p><a href="' . h(url('admin/assignment.php')) . '">+ New assignment</a> · <a href="' . h(url('admin/settings.php'))
    . '">Settings</a> · <a href="' . h(url()) . '">Student view</a></p>';
if (!$rows) {
    echo '<p class="muted">No assignments yet.</p>';
} else {
    // One group of rows per org (rows are sorted by org).
    echo '<table><tr><th>Assignment</th><th>Team size</th><th>Teams</th><th>Students</th><th></th></tr>';
    $org = null;
    foreach ($rows as $a) {
        if ($a['org'] !== $org) {
            $org = $a['org'];
            echo '<tr class="group"><th colspan="5">' . h($org) . '</th></tr>';
        }
        echo '<tr><td><a href="' . h(url('admin/assignment.php?id=' . $a['id'])) . '">' . h($a['title']) . '</a>'
            . ($a['is_open'] ? '' : ' <span class="muted">(closed)</span>')
            . '<br><small class="muted">' . h("{$a['template_owner']}/{$a['template_repo']}") . '</small></td>'
            . '<td>' . (int) $a['max_team_size'] . '</td>'
            . '<td>' . (int) $a['teams'] . '</td>'
            . '<td>' . (int) $a['students']
            . ((int) $a['failed'] ? '<br><small class="err">' . (int) $a['failed'] . ' failed invites</small>' : '') . '</td>'
            . '<td class="actions-cell"><button type="button" class="small secondary" data-copy="' . h(url('join.php?code=' . $a['invite_code'])) . '">'
            . 'Copy join link</button> <a href="' . h(url('admin/assignment.php?copy=' . $a['id'])) . '">Duplicate</a></td></tr>';
    }
    echo '</table>';
}

// Team memberships refer to the SSO user, so unlinking doesn't remove anyone from a team.
$users = q('SELECT u.*, (SELECT COUNT(*) FROM team_members m WHERE m.sso_user = u.sso_user) AS teams
            FROM users u ORDER BY u.sso_user')->fetchAll();
echo '<h2>Users (' . count($users) . ')</h2>';
if (!$users) {
    echo '<p class="muted">No student has linked a GitHub account yet.</p>';
} else {
    echo '<table><tr><th>SSO user</th><th>GitHub</th><th>Linked</th><th>Teams</th><th></th></tr>';
    foreach ($users as $u) {
        echo '<tr><td>' . h($u['sso_user']) . '</td>'
            . '<td><a href="https://github.com/' . h($u['github_login']) . '">' . h($u['github_login']) . '</a></td>'
            . '<td>' . h($u['linked_at']) . ' UTC</td><td>' . (int) $u['teams'] . '</td><td>'
            . confirm_button('unlink', ['sso_user' => $u['sso_user']], 'unlink',
                "Unlink GitHub account {$u['github_login']} from {$u['sso_user']}? They must connect a GitHub account again "
                . 'before creating or joining a team.' . ($u['teams'] ? ' Their team memberships stay, and invitations already sent '
                . 'stay with this GitHub account; remove them from the team on the assignment page if needed.' : ''))
            . '</td></tr>';
    }
    echo '</table>';
}
page_end();
