<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();

// $db is false when the database file can't be opened; the page then only shows why.
$dbError = null;
try {
    db();
    $db = true;
} catch (PDOException $e) {
    $dbError = $e->getMessage();
    $db = false;
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
        set_setting('gh_client_id', trim((string) $_POST['gh_client_id']));
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

$path = db_path();
$real = realpath($path) ?: $path;
echo '<fieldset><legend>1. Database</legend>';
if ($dbError !== null) {
    echo '<p class="err">Could not open the SQLite database <code>' . h($path) . '</code>: ' . h($dbError) . '</p>'
        . '<p>Its directory must exist and be writable by the web server (SQLite also creates '
        . '<code>-wal</code> and <code>-shm</code> files next to it).</p>' . env_instructions();
} else {
    echo '<p class="ok">SQLite ' . h((string) db()->query('SELECT sqlite_version()')->fetchColumn())
        . ' database <code>' . h($real) . '</code> (' . number_format((int) filesize($real) / 1024) . ' KiB).</p>';
}
if (str_starts_with($real, (realpath(APP_DIR) ?: APP_DIR) . '/')) {
    echo '<p class="' . (IS_DEV_SERVER ? 'muted' : 'err') . '">The database file is inside the web root. If the server ignores '
        . '<code>.htaccess</code>, it can be downloaded. Move it outside, e.g. to <code>~/classroom-config/</code>, '
        . 'or set <code>DB_PATH</code>.</p>';
}
echo '<p class="muted">The location is <code>DB_PATH</code> in <code>' . h(CONFIG_DIR . '/.env') . '</code>, '
    . 'by default <code>classroom.sqlite</code> in the config directory. Back it up by copying it with '
    . '<code>sqlite3 ' . h($real) . ' ".backup backup.sqlite"</code>, not with <code>cp</code>.</p></fieldset>';

if (!$db) {
    page_end();
    exit;
}

$applied = applied_migrations(db());
$pending = pending_migrations(db());
echo '<fieldset><legend>Database schema</legend><table><tr><th>Migration</th><th>Applied</th></tr>';
foreach (migration_files() as $name => $_) {
    echo '<tr><td><code>' . h($name) . '</code></td><td>'
        . (isset($applied[$name]) ? h($applied[$name]) . ' UTC' : '<span class="err">pending</span>') . '</td></tr>';
}
echo '</table><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="migrate">'
    . '<button' . ($pending ? '' : ' disabled') . '>Run pending migrations</button></form>';
if (is_dev_db()) {
    echo '<form method="post" onsubmit="return confirm(\'Drop all tables, including GitHub settings, and re-run all migrations?\')">'
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
    . '<p>After creating it, copy the <b>Client ID</b> into the form below, '
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
    . '<label>Client ID</label><input type="text" name="gh_client_id" value="' . h(setting('gh_client_id')) . '" required>'
    . '<label>Client secret</label><input type="password" name="gh_client_secret" placeholder="' . $secretSet('gh_client_secret') . '">'
    . '<label>Private key (.pem contents)</label><textarea name="gh_private_key" rows="4" placeholder="'
    . $secretSet('gh_private_key') . '"></textarea>'
    . '<p><button>Save</button></p></form>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="test">'
    . '<button>Test GitHub connection</button></form></fieldset>';
page_end();
