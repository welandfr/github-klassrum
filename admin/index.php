<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();
if (!schema_installed()) {
    redirect('admin/settings.php');
}

$rows = q('SELECT a.*, COUNT(DISTINCT t.id) AS teams, COUNT(m.sso_user) AS students,
                  SUM(m.invite_status <> \'invited\') AS failed
           FROM assignments a LEFT JOIN teams t ON t.assignment_id = a.id LEFT JOIN team_members m ON m.team_id = t.id
           GROUP BY a.id ORDER BY a.org, a.created_at DESC')->fetchAll();

page_start('Assignments');
echo pending_migrations_notice();
echo '<p><a href="' . h(url('admin/assignment.php')) . '">+ New assignment</a> · <a href="' . h(url('admin/settings.php'))
    . '">Settings</a> · <a href="' . h(url()) . '">Student view</a></p>';
if (!$rows) {
    echo '<p class="muted">No assignments yet.</p>';
} else {
    echo '<table><tr><th>Org</th><th>Assignment</th><th>Template</th><th>Team size</th><th>Teams / students</th><th>Join link</th></tr>';
    foreach ($rows as $a) {
        echo '<tr><td>' . h($a['org']) . '</td><td><a href="' . h(url('admin/assignment.php?id=' . $a['id'])) . '">' . h($a['title']) . '</a>'
            . '<br><code>' . h($a['slug']) . '</code>' . ($a['is_open'] ? '' : ' <span class="muted">(closed)</span>') . '</td>'
            . '<td>' . h("{$a['template_owner']}/{$a['template_repo']}") . '</td>'
            . '<td>' . (int) $a['max_team_size'] . '</td>'
            . '<td>' . (int) $a['teams'] . ' / ' . (int) $a['students']
            . ((int) $a['failed'] ? ' <span class="err">(' . (int) $a['failed'] . ' failed invites)</span>' : '') . '</td>'
            . '<td><code>' . h(url('join.php?code=' . $a['invite_code'])) . '</code></td></tr>';
    }
    echo '</table>';
}
page_end();
