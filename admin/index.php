<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_teacher();
if (!schema_installed()) {
    redirect(is_admin() ? 'admin/settings.php' : '');
}
$me = sso_user();

// Linked GitHub accounts with their team count in this teacher's assignments ("mine") and in other teachers' ("others").
// Team memberships refer to the SSO user, so unlinking doesn't remove anyone from a team.
$userSql = 'SELECT u.*,
        (SELECT COUNT(*) FROM team_members m JOIN assignments a ON a.id = m.assignment_id WHERE m.sso_user = u.sso_user AND a.owner = :me) AS mine,
        (SELECT COUNT(*) FROM team_members m JOIN assignments a ON a.id = m.assignment_id WHERE m.sso_user = u.sso_user AND a.owner <> :me) AS others
    FROM users u';
// A teacher may unlink their own students, unless another teacher's assignment uses the link too.
// Main admins may also unlink accounts that are in no assignment.
$mayUnlink = fn(array $u): bool => !$u['others'] && ($u['mine'] || is_admin());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'unlink') {
        $u = q("$userSql WHERE u.sso_user = :sso", ['me' => $me, 'sso' => (string) $_POST['sso_user']])->fetch();
        if ($u && $mayUnlink($u)) {
            q('DELETE FROM users WHERE sso_user = ?', [$u['sso_user']]);
            flash('ok', 'Unlinked the GitHub account of ' . $u['sso_user'] . '.');
        } else {
            flash('err', 'You can\'t unlink that account.');
        }
    }
    redirect('admin/');
}

$rows = q('SELECT a.*, COUNT(DISTINCT t.id) AS teams, COUNT(m.sso_user) AS students,
                  SUM(m.invite_status = \'failed\') AS failed
           FROM assignments a LEFT JOIN teams t ON t.assignment_id = a.id LEFT JOIN team_members m ON m.team_id = t.id
           WHERE a.owner = ? GROUP BY a.id ORDER BY a.org, a.created_at DESC', [$me])->fetchAll();

page_start('My assignments');
echo pending_migrations_notice();
echo '<p><a href="' . h(url('admin/assignment.php')) . '">+ New assignment</a>'
    . (is_admin() ? ' · <a href="' . h(url('admin/settings.php')) . '">Settings</a>' : '')
    . ' · <a href="' . h(url()) . '">Student view</a> · <a href="' . h(url('admin/guide.php')) . '">Teacher guide</a></p>';
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
            . ((int) $a['failed'] ? '<br><small class="err">' . (int) $a['failed'] . ' failed invite' . ($a['failed'] > 1 ? 's' : '') . '</small>' : '') . '</td>'
            . '<td class="actions-cell"><button type="button" class="small secondary" data-copy="' . h(url('join.php?code=' . $a['invite_code'])) . '">'
            . 'Copy join link</button> <a href="' . h(url('admin/assignment.php?copy=' . $a['id'])) . '">Duplicate</a></td></tr>';
    }
    echo '</table>';
}

$users = q("$userSql ORDER BY u.sso_user", ['me' => $me])->fetchAll();
$userTable = function (array $users) use ($mayUnlink): string {
    $html = '<table><tr><th>SSO user</th><th>GitHub</th><th>Linked</th><th>Teams</th><th></th></tr>';
    foreach ($users as $u) {
        $html .= '<tr><td>' . h($u['sso_user']) . '</td>'
            . '<td><a href="https://github.com/' . h($u['github_login']) . '">' . h($u['github_login']) . '</a></td>'
            . '<td>' . h($u['linked_at']) . ' UTC</td><td>' . (int) $u['mine'] . '</td><td>'
            . ($mayUnlink($u)
                ? confirm_button('unlink', ['sso_user' => $u['sso_user']], 'unlink',
                    "Unlink GitHub account {$u['github_login']} from {$u['sso_user']}? They must connect a GitHub account again "
                    . 'before creating or joining a team.' . ($u['mine'] ? ' Their team memberships stay, and invitations already sent '
                    . 'stay with this GitHub account; remove them from the team on the assignment page if needed.' : ''))
                : '<button class="small danger" disabled>unlink</button><br><small class="muted">Also in another teacher\'s assignment</small>')
            . '</td></tr>';
    }
    return $html . '</table>';
};

$students = array_filter($users, fn($u) => $u['mine'] > 0);
echo '<h2>Students (' . count($students) . ')</h2>';
echo $students ? $userTable($students) : '<p class="muted">No students in your assignments yet.</p>';

if (is_admin()) {
    $unassigned = array_filter($users, fn($u) => !$u['mine'] && !$u['others']);
    echo '<h2>Not in any assignment (' . count($unassigned) . ')</h2>'
        . '<p class="muted">Linked GitHub accounts of students who haven\'t joined a team yet, and of teachers. Only main admins see this list.</p>'
        . ($unassigned ? $userTable($unassigned) : '<p class="muted">None.</p>');
}
page_end();
