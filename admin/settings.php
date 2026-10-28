<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();

$db = db_config();
$dbError = null;
if ($db) {
    try {
        db();
    } catch (PDOException $e) {
        $dbError = $e->getMessage();
        $db = null;  // skip the actions and sections below that need the DB
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'migrate' && $db) {
        try {
            $applied = migrate(db());
            flash('ok', 'Migrations applied: ' . ($applied ? implode(', ', $applied) : 'none pending') . '.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('admin/settings.php');
    } elseif ($action === 'reset' && $db && is_dev_db()) {
        try {
            reset_database(db());
            flash('ok', 'Database reset: all tables dropped and migrations re-run.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('admin/settings.php');
    } elseif ($action === 'github' && $db) {
        foreach (['gh_app_id', 'gh_client_id'] as $k) {
            set_setting($k, trim((string) $_POST[$k]));
        }
        foreach (['gh_client_secret', 'gh_private_key'] as $k) {
            if (trim((string) $_POST[$k]) !== '') {
                set_setting($k, trim((string) $_POST[$k]));
            }
        }
        gh_forget_tokens();  // force fresh tokens with the new settings
        flash('ok', 'GitHub settings saved.');
        redirect('admin/settings.php');
    } elseif ($action === 'test' && $db) {
        try {
            $orgs = gh_installed_orgs();
            if ($orgs) {
                flash('ok', 'OK: the app is installed on ' . implode(', ', $orgs) . '.');
            } else {
                flash('err', 'The app credentials work, but the app is not installed on any organization yet.');
            }
        } catch (RuntimeException $e) {
            flash('err', 'GitHub test failed: ' . $e->getMessage());
        }
        redirect('admin/settings.php');
    }
}

page_start('Settings');
echo '<p><a href="' . h(url('admin/')) . '">← Assignments</a></p>';

$envFile = CONFIG_DIR . '/.env';
echo '<fieldset><legend>1. Database</legend>';
if ($dbError !== null) {
    echo '<p class="err">Could not connect: ' . h($dbError) . '</p>';
} elseif ($db) {
    echo '<p class="ok">Connected to <code>' . h("{$db['user']}@{$db['host']}:{$db['port']}/{$db['name']}") . '</code>.</p>';
} else {
    echo '<p class="err">The database is not configured in <code>.env</code>.</p>' . env_instructions();
}
if ($db || $dbError !== null) {
    echo '<p class="muted">Connection settings are read from <code>' . h($envFile) . '</code>'
        . ' or the server environment. Edit them there and reload.</p>';
}
echo '</fieldset>';

if (!$db) {
    page_end();
    exit;
}

$applied = db()->query('SELECT name, applied_at FROM classroom_schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$pending = pending_migrations(db());
echo '<fieldset><legend>Database schema</legend><table><tr><th>Migration</th><th>Applied</th></tr>';
foreach (migration_files() as $name => $_) {
    echo '<tr><td><code>' . h($name) . '</code></td><td>'
        . (isset($applied[$name]) ? h($applied[$name]) : '<span class="err">pending</span>') . '</td></tr>';
}
echo '</table><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="migrate">'
    . '<button' . ($pending ? '' : ' disabled') . '>Run pending migrations</button></form>';
if (is_dev_db()) {
    echo '<form method="post" onsubmit="return confirm(\'Drop all classroom_ tables, including GitHub settings, and re-run all migrations?\')">'
        . csrf_field() . '<input type="hidden" name="action" value="reset">'
        . '<p><button style="background:var(--err)">Reset database (dev only)</button></p></form>';
}
echo '</fieldset>';

if ($pending) {
    echo '<p class="err">Run the pending migrations before configuring GitHub.</p>';
    page_end();
    exit;
}

$secretSet = fn(string $k) => setting($k) !== null && setting($k) !== '' ? '(saved; leave empty to keep)' : '';
$code = fn(string $s) => '<code>' . h($s) . '</code>';
echo '<fieldset><legend>2. GitHub App</legend>'
    // Expanded until the app is configured.
    . '<details' . (setting('gh_client_id') ? '' : ' open') . '><summary>Setup instructions for GitHub</summary>'
    . '<p><b>Create the app</b> (once; it serves all group orgs). In one of the orgs or your own account: '
    . '<i>Settings → Developer settings → GitHub Apps → New GitHub App</i>.</p><ul>'
    . '<li><b>Homepage URL:</b> ' . $code(url()) . '</li>'
    . '<li><b>Callback URL:</b> ' . $code(url('oauth.php')) . '</li>'
    . '<li><b>Webhook:</b> uncheck <i>Active</i>.</li>'
    . '<li><b>Repository permissions:</b> Administration: Read and write · Contents: Read-only · Metadata: Read-only.</li>'
    . '<li><b>Organization permissions</b> (optional): Administration: Read-only, so assignment saves can check the org\'s base permission.</li>'
    . '<li><b>Where can this app be installed:</b> Any account. It only acts on orgs where it is installed.</li></ul>'
    . '<p>After creating it, copy the <b>App ID</b> and <b>Client ID</b> into the form below, '
    . 'and use <i>Generate a new client secret</i> and <i>Generate a private key</i> for the other two fields.</p>'
    . '<p><b>Install the app</b> on each group\'s org (<i>Install App</i> in the app settings), with <i>All repositories</i>. '
    . 'Installed orgs appear in the assignment form; <b>Test GitHub connection</b> lists them.</p>'
    . '<p><b>In each group org</b>, under <i>Settings → Member privileges</i>:</p><ul>'
    . '<li><b>Base permissions:</b> No permission, so members can\'t see every student repo.</li>'
    . '<li><b>Repository creation:</b> disabled for members.</li>'
    . '<li><b>Repository invitations:</b> must allow inviting outside collaborators (students are invited that way).</li>'
    . '<li>Make teachers org <b>owners</b>; that gives them access to all repos.</li></ul>'
    . '</details>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="github">'
    . '<label>App ID</label><input type="text" name="gh_app_id" value="' . h(setting('gh_app_id')) . '" required>'
    . '<label>Client ID</label><input type="text" name="gh_client_id" value="' . h(setting('gh_client_id')) . '" required>'
    . '<label>Client secret</label><input type="password" name="gh_client_secret" placeholder="' . $secretSet('gh_client_secret') . '">'
    . '<label>Private key (.pem contents)</label><textarea name="gh_private_key" rows="4" placeholder="'
    . $secretSet('gh_private_key') . '"></textarea>'
    . '<p><button>Save</button></p></form>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="test">'
    . '<button>Test GitHub connection</button></form></fieldset>';
page_end();
