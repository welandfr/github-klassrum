<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();
if (!db_config()) {
    redirect('admin/settings.php');
}

$id = (int) ($_GET['id'] ?? 0);
$a = $id ? q('SELECT * FROM assignments WHERE id = ?', [$id])->fetch() : null;
if ($id && !$a) {
    http_response_code(404);
    exit('No such assignment.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $f = [
            'title' => trim((string) $_POST['title']),
            'slug' => strtolower(trim((string) $_POST['slug'])),
            'template' => trim((string) $_POST['template']),
            'max_team_size' => max(1, min(50, (int) $_POST['max_team_size'])),
            'is_open' => isset($_POST['is_open']) ? 1 : 0,
        ];
        try {
            if ($f['title'] === '') {
                throw new UserError('Title is required.');
            }
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $f['slug'])) {
                throw new UserError('Slug must be lowercase letters, digits and dashes (max 40).');
            }
            // Accept "owner/repo" or a github.com URL.
            if (!preg_match('~^(?:https://github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?/?$~', $f['template'], $m)) {
                throw new UserError('Template must be "owner/repo" or a GitHub URL.');
            }
            [$s, $tpl] = gh('GET', '/repos/' . rawurlencode($m[1]) . '/' . rawurlencode($m[2]));
            if ($s !== 200) {
                throw new UserError("Template repo {$m[1]}/{$m[2]} is not accessible to the GitHub App (HTTP $s).");
            }
            if (empty($tpl['is_template'])) {
                throw new UserError("{$m[1]}/{$m[2]} is not marked as a template repository (repo Settings → Template repository).");
            }
            $params = [$f['title'], $f['slug'], $tpl['owner']['login'], $tpl['name'], $f['max_team_size'], $f['is_open']];
            try {
                if ($a) {
                    q('UPDATE assignments SET title = ?, slug = ?, template_owner = ?, template_repo = ?, max_team_size = ?, is_open = ?
                       WHERE id = ?', [...$params, $a['id']]);
                } else {
                    q('INSERT INTO assignments (title, slug, template_owner, template_repo, max_team_size, is_open, invite_code)
                       VALUES (?, ?, ?, ?, ?, ?, ?)', [...$params, bin2hex(random_bytes(8))]);
                    $id = (int) db()->lastInsertId();
                }
            } catch (PDOException $e) {
                throw is_duplicate_key($e) ? new UserError('Another assignment already uses that slug.') : $e;
            }
            flash('ok', 'Saved.');
            redirect('admin/assignment.php?id=' . $id);
        } catch (UserError $e) {
            flash('err', $e->getMessage());
            $_SESSION['form'] = $f;  // repopulate the form after the redirect
            redirect('admin/assignment.php' . ($id ? "?id=$id" : ''));
        }
    } elseif ($action === 'retry' && $a) {
        [$ok, $failed] = retry_failed_invites((int) $a['id']);
        flash($failed ? 'err' : 'ok', "Retried invitations: $ok succeeded, $failed failed.");
        redirect('admin/assignment.php?id=' . $a['id']);
    }
}

$f = $_SESSION['form'] ?? ($a ? [
    'title' => $a['title'],
    'slug' => $a['slug'],
    'template' => "{$a['template_owner']}/{$a['template_repo']}",
    'max_team_size' => $a['max_team_size'],
    'is_open' => $a['is_open'],
] : ['title' => '', 'slug' => '', 'template' => '', 'max_team_size' => 1, 'is_open' => 1]);
unset($_SESSION['form']);

page_start($a ? $a['title'] : 'New assignment');
echo '<p><a href="' . h(url('admin/')) . '">← Assignments</a></p>';
if ($a) {
    echo '<p>Join link for students: <code>' . h(url('join.php?code=' . $a['invite_code'])) . '</code></p>';
}

echo '<fieldset><legend>' . ($a ? 'Edit' : 'Create') . '</legend><form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="save">'
    . '<label>Title</label><input type="text" name="title" value="' . h($f['title']) . '" required>'
    . '<label>Slug (repo name prefix)</label><input type="text" name="slug" value="' . h($f['slug']) . '" required pattern="[a-z0-9][a-z0-9-]{0,39}">'
    . '<p class="muted">Repos are named <code>&lt;slug&gt;-&lt;github-login&gt;</code> of the student who creates the team. '
    . 'Changing the slug only affects new repos.</p>'
    . '<label>Template repository</label><input type="text" name="template" value="' . h($f['template']) . '" placeholder="my-org/assignment1-template" required>'
    . '<label>Max team size</label><input type="number" name="max_team_size" min="1" max="50" value="' . (int) $f['max_team_size'] . '">'
    . '<p class="muted">1 = individual assignment.</p>'
    . '<label><input type="checkbox" name="is_open"' . ($f['is_open'] ? ' checked' : '') . '> Open for new teams and joins</label>'
    . '<p><button>Save</button></p></form></fieldset>';

if ($a) {
    $teams = q('SELECT * FROM teams WHERE assignment_id = ? ORDER BY name', [$a['id']])->fetchAll();
    echo '<h2>Teams (' . count($teams) . ')</h2>';
    if ($teams) {
        echo '<table><tr><th>Team</th><th>Repository</th><th>Members</th></tr>';
        foreach ($teams as $t) {
            $members = array_map(fn($m) => h($m['sso_user']) . ' (' . h($m['github_login'] ?? '?') . ')'
                . ($m['invite_status'] === 'invited' ? '' : ' <span class="err" title="' . h($m['invite_error']) . '">invite failed</span>'),
                team_members((int) $t['id']));
            echo '<tr><td>' . h($t['name']) . '</td><td><a href="' . h($t['repo_url']) . '">' . h($t['repo_name']) . '</a></td>'
                . '<td>' . implode('<br>', $members) . '</td></tr>';
        }
        echo '</table><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="retry">'
            . '<button>Retry failed invitations</button></form>';
    }
}
page_end();
