<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

$sso = sso_user();
if (is_admin() && !app_ready()) {
    redirect('admin/settings.php');
}
require_configured();
$me = linked_user($sso);

page_start('My assignment repos');
echo '<p>Logged in as <b>' . h($sso) . '</b>';
if ($me) {
    echo ' · GitHub: <a href="https://github.com/' . h($me['github_login']) . '">' . h($me['github_login']) . '</a>'
        . ' · <a href="oauth.php">re-link</a>';
}
if (is_admin()) {
    echo ' · <a href="admin/">Admin</a>';
}
echo '</p>';

if (!$me) {
    echo '<p>Connect your GitHub account to get started.</p>'
        . '<p><a href="oauth.php"><button>Connect GitHub account</button></a></p>';
    page_end();
    exit;
}

$teams = q('SELECT a.title, t.name, t.repo_name, t.repo_url, m.invite_status
            FROM team_members m JOIN teams t ON t.id = m.team_id JOIN assignments a ON a.id = t.assignment_id
            WHERE m.sso_user = ? ORDER BY t.created_at DESC', [$sso])->fetchAll();
if (!$teams) {
    echo '<p class="muted">You have no assignment repos yet. Use the join link from your teacher.</p>';
} else {
    echo '<table><tr><th>Assignment</th><th>Team</th><th>Repository</th></tr>';
    foreach ($teams as $t) {
        echo '<tr><td>' . h($t['title']) . '</td><td>' . h($t['name']) . '</td><td>'
            . ($t['repo_url'] === null ? '<span class="muted">being created</span>'
                : '<a href="' . h($t['repo_url']) . '">' . h($t['repo_name']) . '</a>'
                . ($t['invite_status'] === 'invited' ? '' : ' <span class="err">(invitation failed; contact the teacher)</span>'))
            . '</td></tr>';
    }
    echo '</table><p class="muted">Not seeing a repo on GitHub? Accept the invitation at '
        . '<a href="https://github.com/notifications">github.com/notifications</a> or the repo’s /invitations page.</p>';
}
page_end();
