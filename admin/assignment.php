<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();
if (!schema_installed()) {
    redirect('admin/settings.php');
}

$id = (int) ($_GET['id'] ?? 0);
$a = $id ? q('SELECT * FROM assignments WHERE id = ?', [$id])->fetch() : null;
if ($id && !$a) {
    http_response_code(404);
    exit('No such assignment.');
}
// The org can't change once repos exist in it.
$orgLocked = $a && q('SELECT 1 FROM teams WHERE assignment_id = ? LIMIT 1', [$a['id']])->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $f = [
            'org' => $orgLocked ? $a['org'] : trim((string) ($_POST['org'] ?? '')),
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
            if (!$orgLocked && !in_array($f['org'], gh_installed_orgs(), true)) {
                throw new UserError('Choose an organization the GitHub App is installed on.');
            }
            // Accept "owner/repo" or a github.com URL.
            if (!preg_match('~^(?:https://github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?/?$~', $f['template'], $m)) {
                throw new UserError('Template must be "owner/repo" or a GitHub URL.');
            }
            [$s, $tpl] = gh($f['org'], 'GET', '/repos/' . rawurlencode($m[1]) . '/' . rawurlencode($m[2]));
            if ($s !== 200) {
                throw new UserError("Template repo {$m[1]}/{$m[2]} is not accessible to the GitHub App (HTTP $s). "
                    . "It must be public, or private in {$f['org']}.");
            }
            if (empty($tpl['is_template'])) {
                throw new UserError("{$m[1]}/{$m[2]} is not marked as a template repository (repo Settings → Template repository).");
            }
            $params = [$f['org'], $f['title'], $f['slug'], $tpl['owner']['login'], $tpl['name'], $f['max_team_size'], $f['is_open']];
            try {
                if ($a) {
                    q('UPDATE assignments SET org = ?, title = ?, slug = ?, template_owner = ?, template_repo = ?, max_team_size = ?, is_open = ?
                       WHERE id = ?', [...$params, $a['id']]);
                } else {
                    q('INSERT INTO assignments (org, title, slug, template_owner, template_repo, max_team_size, is_open, invite_code)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [...$params, bin2hex(random_bytes(8))]);
                    $id = (int) db()->lastInsertId();
                }
            } catch (PDOException $e) {
                throw is_duplicate_key($e) ? new UserError("Another assignment in {$f['org']} already uses that slug.") : $e;
            }
            flash('ok', 'Saved.');
            if (!$a || $a['org'] !== $f['org']) {
                check_org_base_permission($f['org']);
            }
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
    } elseif ($action === 'remove_member' && $a) {
        $teamId = (int) $_POST['team_id'];
        $sso = (string) $_POST['sso_user'];
        $team = q('SELECT * FROM teams WHERE id = ? AND assignment_id = ?', [$teamId, $a['id']])->fetch();
        if (!$team) {
            redirect('admin/assignment.php?id=' . $a['id']);
        }
        $members = array_column(team_members($teamId), 'sso_user');
        if (!empty($_POST['delete_repo']) && $members === [$sso] && $team['repo_url'] !== null) {
            $error = delete_repo($team);
            if ($error === null) {
                delete_team($teamId);
                flash('ok', "Removed $sso, deleted the repo {$team['repo_name']} on GitHub and the team.");
                redirect('admin/assignment.php?id=' . $a['id']);
            }
            flash('err', "Could not delete the repo, so only the member was removed. $error");
        }
        $error = remove_member($teamId, $sso);
        flash('ok', "Removed $sso from the team.");
        if ($error !== null) {
            flash('err', "Removing them on GitHub failed; check the repo's collaborators by hand. $error");
        }
        redirect('admin/assignment.php?id=' . $a['id']);
    } elseif ($action === 'create_repo' && $a) {
        $teamId = (int) $_POST['team_id'];
        try {
            if (!q('SELECT 1 FROM teams WHERE id = ? AND assignment_id = ?', [$teamId, $a['id']])->fetch()) {
                throw new UserError('That team does not exist.');
            }
            create_repo($teamId);
            flash('ok', 'Repository created and members invited.');
        } catch (UserError $e) {
            flash('err', $e->getMessage());
        }
        redirect('admin/assignment.php?id=' . $a['id']);
    } elseif ($action === 'delete_team' && $a) {
        q('DELETE FROM teams WHERE id = ? AND assignment_id = ?', [(int) $_POST['team_id'], $a['id']]);
        flash('ok', 'Team removed from the app. Its repo is still on GitHub.');
        redirect('admin/assignment.php?id=' . $a['id']);
    } elseif ($action === 'sync' && $a) {
        // Recomputed rather than taken from the form, so only what is still true gets applied.
        try {
            $diffs = github_differences($a);
            apply_github_differences($diffs);
            flash('ok', 'Updated from GitHub: ' . count($diffs) . ' change(s).');
        } catch (RuntimeException $e) {
            flash('err', 'Could not read GitHub, nothing changed: ' . $e->getMessage());
        }
        redirect('admin/assignment.php?id=' . $a['id']);
    }
}

$f = $_SESSION['form'] ?? ($a ? [
    'org' => $a['org'],
    'title' => $a['title'],
    'slug' => $a['slug'],
    'template' => "{$a['template_owner']}/{$a['template_repo']}",
    'max_team_size' => $a['max_team_size'],
    'is_open' => $a['is_open'],
] : ['org' => '', 'title' => '', 'slug' => '', 'template' => '', 'max_team_size' => 1, 'is_open' => 1]);
unset($_SESSION['form']);

if ($orgLocked) {
    $orgField = '<p><code>' . h($f['org']) . '</code> <span class="muted">(fixed: teams already exist)</span></p>';
} else {
    try {
        $orgs = gh_installed_orgs();
    } catch (RuntimeException $e) {
        $orgs = [];
        flash('err', $e->getMessage());
    }
    if ($f['org'] !== '' && !in_array($f['org'], $orgs, true)) {
        $orgs[] = $f['org'];  // keep the saved value visible even if the app was uninstalled
    }
    $orgField = '<select name="org" required><option value="">Choose…</option>';
    foreach ($orgs as $o) {
        $orgField .= '<option' . ($o === $f['org'] ? ' selected' : '') . '>' . h($o) . '</option>';
    }
    $appUrl = gh_app_url();
    $install = $appUrl
        ? '<a href="' . h($appUrl . '/installations/new') . '">' . h($appUrl . '/installations/new') . '</a>'
        : 'the app\'s page on GitHub (<i>Settings → Developer settings → GitHub Apps → Edit → Install App</i>)';
    $orgField .= '</select><p class="muted">Orgs the GitHub App is installed on.</p>'
        // Expanded while no org is available yet.
        . '<details' . ($orgs ? '' : ' open') . '><summary>Adding a new group\'s org</summary><ol>'
        . '<li>Open ' . $install . '.</li>'
        . '<li>Choose the group\'s org. You must be an owner of it; otherwise GitHub only sends an install request to the owners. '
        . 'If only the org that owns the app is offered, the app is private: in the app\'s settings, '
        . '<i>Advanced → Make public</i>.</li>'
        . '<li>Select <i>All repositories</i> and click <i>Install</i>.</li>'
        . '<li>In the org\'s <i>Settings → Member privileges</i>, set <i>Base permissions</i> to No permission and disable repository creation for members '
        . '(see the <a href="' . h(url('admin/settings.php')) . '">settings page</a> instructions).</li>'
        . '<li>Reload this page (unsaved input in this form is lost); the org then appears in the list.</li>'
        . '</ol></details>';
}

page_start($a ? $a['title'] : 'New assignment');
echo pending_migrations_notice();
echo '<p><a href="' . h(url('admin/')) . '">← Assignments</a></p>';
if ($a) {
    echo '<p>Join link for students: <code>' . h(url('join.php?code=' . $a['invite_code'])) . '</code></p>';
}

echo '<fieldset><legend>' . ($a ? 'Edit' : 'Create') . '</legend><form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="save">'
    . '<label>GitHub organization</label>' . $orgField
    . '<label>Title</label><input type="text" name="title" value="' . h($f['title']) . '" required>'
    . '<label>Slug (repo name prefix)</label><input type="text" name="slug" value="' . h($f['slug']) . '" required pattern="[a-z0-9][a-z0-9-]{0,39}">'
    . '<p class="muted">Repos are named <code>&lt;slug&gt;-&lt;github-login&gt;</code> of the student who creates the team. '
    . 'Changing the slug only affects new repos.</p>'
    . '<label>Template repository</label><input type="text" name="template" value="' . h($f['template']) . '" placeholder="owner/assignment1-template" required>'
    . '<p class="muted">Any template repository the app can read: public, or private in the chosen org.</p>'
    . '<label>Max team size</label><input type="number" name="max_team_size" min="1" max="50" value="' . (int) $f['max_team_size'] . '">'
    . '<p class="muted">1 = individual assignment.</p>'
    . '<label><input type="checkbox" name="is_open"' . ($f['is_open'] ? ' checked' : '') . '> Open for new teams and joins</label>'
    . '<p><button>Save</button></p></form></fieldset>';

if ($a) {
    $teams = q('SELECT * FROM teams WHERE assignment_id = ? ORDER BY name', [$a['id']])->fetchAll();
    echo '<h2>Teams (' . count($teams) . ')</h2>';
    if ($teams) {
        // Read-only check on every page view; changes are only applied with the button.
        try {
            $diffs = github_differences($a);
            if ($diffs) {
                echo '<fieldset><legend>Changed on GitHub</legend><ul>';
                foreach ($diffs as $d) {
                    echo '<li>' . h($d['text']) . '</li>';
                }
                echo '</ul><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="sync">'
                    . '<button>Update the app to match</button></form></fieldset>';
            } else {
                echo '<p class="muted">In sync with GitHub.</p>';
            }
        } catch (RuntimeException $e) {
            echo '<p class="err">Could not compare with GitHub: ' . h($e->getMessage()) . '</p>';
        }

        // A button that opens a confirmation dialog holding the actual form; $extra is HTML added to the dialog.
        $button = fn(string $action, array $fields, string $label, string $confirm, string $extra = '') =>
            '<button type="button" class="small danger" onclick="this.nextElementSibling.showModal()">' . h($label) . '</button>'
            . '<dialog><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '">'
            . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . $k . '" value="' . h((string) $v) . '">', array_keys($fields), $fields))
            . '<p>' . h($confirm) . '</p>' . $extra
            . '<p><button type="button" class="secondary" onclick="this.closest(\'dialog\').close()">Cancel</button> '
            . '<button class="danger">' . h(ucfirst($label)) . '</button></p></form></dialog>';
        echo '<table><tr><th>Team</th><th>Repository</th><th>Members</th><th></th></tr>';
        foreach ($teams as $t) {
            $tm = team_members((int) $t['id']);
            // Only offered for the last member, so a repo is never deleted under other students.
            $deleteRepo = count($tm) === 1 && $t['repo_url'] !== null
                ? '<label><input type="checkbox" name="delete_repo" value="1"> Delete the repo ' . h($t['repo_name'])
                    . ' on GitHub as well, and the team</label><p class="muted">This can\'t be undone.</p>'
                : '';
            $members = array_map(fn($m) => h($m['sso_user']) . ' (' . h($m['github_login'] ?? '?') . ')'
                . ($m['invite_status'] === 'failed' ? ' <span class="err" title="' . h($m['invite_error']) . '">invite failed</span>' : '')
                . ' ' . $button('remove_member', ['team_id' => $t['id'], 'sso_user' => $m['sso_user']], 'remove',
                    "Remove {$m['sso_user']} from team {$t['name']}? Their access to the repo on GitHub is removed too.", $deleteRepo),
                $tm);
            echo '<tr><td>' . h($t['name']) . '</td><td>'
                . ($t['repo_url'] !== null ? '<a href="' . h($t['repo_url']) . '">' . h($t['repo_name']) . '</a>'
                    : ($t['creating_since'] !== null ? '<span class="muted">being created</span>'
                        : '<span class="muted">waiting for members</span> <form method="post" style="display:inline">' . csrf_field()
                            . '<input type="hidden" name="action" value="create_repo"><input type="hidden" name="team_id" value="' . $t['id'] . '">'
                            . '<button class="small">Create repo</button></form>')) . '</td>'
                . '<td>' . implode('<br>', $members) . '</td><td>'
                . $button('delete_team', ['team_id' => $t['id']], 'Delete team',
                    "Delete team {$t['name']} from the app? Its members can then create or join another team. The repo stays on GitHub.")
                . '</td></tr>';
        }
        echo '</table><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="retry">'
            . '<button>Retry failed invitations</button></form>';
    }
}
page_end();
