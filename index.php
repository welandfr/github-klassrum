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
echo '<p class="muted">Arcada-user: ' . h($sso) . ($me ? '<br>GitHub: <a href="https://github.com/' . h($me['github_login']) . '">'
    . h($me['github_login']) . '</a> · <a href="oauth.php">re-link</a>' : '') . '</p>'
    . '<p>How it works:</p><ol>'
    . '<li>Connect your GitHub account (only the first time).</li>'
    . '<li>Open the join link from your teacher, then create a team or join one. For individual work, create your repository.</li>'
    . '<li>When your team is full, or a member clicks <b>Our team is ready</b>, the repository is created.</li>'
    . '<li>Accept the invitation on GitHub. Until then, the repository shows “404”.</li>'
    . '</ol><p>Details in the <a href="' . h(url('howto.php')) . '">student guide</a>.</p>';

if (!$me) {
    echo '<p>Connect your GitHub account to get started.</p>'
        . '<p><a href="oauth.php"><button>Connect GitHub account</button></a></p>';
    page_end();
    exit;
}

$teams = q('SELECT a.title, a.invite_code, t.name, t.repo_name, t.repo_url, t.creating_since, m.invite_status
            FROM team_members m JOIN teams t ON t.id = m.team_id JOIN assignments a ON a.id = t.assignment_id
            WHERE m.sso_user = ? ORDER BY t.created_at DESC', [$sso])->fetchAll();
if (!$teams) {
    echo '<p class="muted">You have no assignment repos yet. Use the join link from your teacher.</p>';
} else {
    echo '<table><tr><th>Assignment</th><th>Team</th><th>Repository</th></tr>';
    foreach ($teams as $t) {
        echo '<tr><td>' . h($t['title']) . '</td><td>' . h($t['name']) . '</td><td>'
            . ($t['repo_url'] !== null ? '<a href="' . h($t['repo_url']) . '">' . h($t['repo_name']) . '</a>'
                . match ($t['invite_status']) {
                    'invited' => ' · <a href="' . h($t['repo_url']) . '/invitations">accept invitation</a>',
                    'failed' => ' <span class="err">(invitation failed; contact the teacher)</span>',
                    default => ' <span class="muted">(invitation being sent)</span>',
                }
                : '<span class="muted">' . ($t['creating_since'] !== null ? 'being created' : 'not created yet') . '</span> · '
                    . '<a href="' . h(url('join.php?code=' . $t['invite_code'])) . '">team page</a>')
            . '</td></tr>';
    }
    echo '</table><p class="muted">A repo shows “404” on GitHub until you accept its invitation. '
        . 'Once accepted, the repo link works and the invitation link no longer does.</p>';
}
page_end();
