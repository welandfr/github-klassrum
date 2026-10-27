<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_admin();

$db = db_config();
$manualConfig = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'db') {
        $c = [
            'host' => trim((string) $_POST['host']),
            'port' => (int) $_POST['port'] ?: 3306,
            'name' => trim((string) $_POST['name']),
            'user' => trim((string) $_POST['user']),
            'pass' => $_POST['pass'] !== '' ? (string) $_POST['pass'] : ($db['pass'] ?? ''),
        ];
        try {
            $applied = migrate(db_connect($c));
        } catch (RuntimeException $e) {
            flash('err', 'Could not connect or create tables: ' . $e->getMessage());
            redirect('admin/settings.php');
        }
        $php = "<?php\n// Written by admin/settings.php\nreturn " . var_export(['db' => $c], true) . ";\n";
        $file = CONFIG_DIR . '/settings.php';
        if (@file_put_contents($file, $php, LOCK_EX) === false) {
            $manualConfig = $php;  // shown below so the admin can create the file by hand
        } else {
            chmod($file, 0600);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            flash('ok', 'Database connected. Migrations applied: ' . ($applied ? implode(', ', $applied) : 'none pending') . '.');
            redirect('admin/settings.php');
        }
    } elseif ($action === 'migrate' && $db) {
        try {
            $applied = migrate(db());
            flash('ok', 'Migrations applied: ' . ($applied ? implode(', ', $applied) : 'none pending') . '.');
        } catch (RuntimeException $e) {
            flash('err', $e->getMessage());
        }
        redirect('admin/settings.php');
    } elseif ($action === 'reset' && $db && IS_DEV_SERVER) {
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

if ($manualConfig !== null) {
    echo '<p class="err">The connection works, but <code>' . h(CONFIG_DIR) . '</code> is not writable by the web server. '
        . 'Create <code>' . h(CONFIG_DIR) . '/settings.php</code> with this content (readable only by the web server) and reload:</p>'
        . '<pre>' . h($manualConfig) . '</pre>';
}

$c = $db ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => ''];
echo '<fieldset><legend>1. MySQL database</legend><form method="post">' . csrf_field()
    . '<input type="hidden" name="action" value="db">'
    . '<label>Host</label><input type="text" name="host" value="' . h($c['host']) . '" required>'
    . '<label>Port</label><input type="number" name="port" value="' . h((string) $c['port']) . '">'
    . '<label>Database name</label><input type="text" name="name" value="' . h($c['name']) . '" required>'
    . '<label>User</label><input type="text" name="user" value="' . h($c['user']) . '" required>'
    . '<label>Password</label><input type="password" name="pass" placeholder="' . ($db ? '(unchanged)' : '') . '">'
    . '<p class="muted">Saved to <code>' . h(CONFIG_DIR) . '/settings.php</code>. Tables are created if missing.</p>'
    . '<button>Save and test connection</button></form></fieldset>';

if (!$db) {
    page_end();
    exit;
}

$applied = db()->query('SELECT name, applied_at FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$pending = pending_migrations(db());
echo '<fieldset><legend>Database schema</legend><table><tr><th>Migration</th><th>Applied</th></tr>';
foreach (migration_files() as $name => $_) {
    echo '<tr><td><code>' . h($name) . '</code></td><td>'
        . (isset($applied[$name]) ? h($applied[$name]) : '<span class="err">pending</span>') . '</td></tr>';
}
echo '</table><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="migrate">'
    . '<button' . ($pending ? '' : ' disabled') . '>Run pending migrations</button></form>';
if (IS_DEV_SERVER) {
    echo '<form method="post" onsubmit="return confirm(\'Drop ALL tables in this database, including GitHub settings, and re-run all migrations?\')">'
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
echo '<fieldset><legend>2. GitHub App</legend>'
    . '<p class="muted">Callback URL to enter in the GitHub App: <code>' . h(url('oauth.php')) . '</code></p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="github">'
    . '<label>App ID</label><input type="text" name="gh_app_id" value="' . h(setting('gh_app_id')) . '" required>'
    . '<label>Client ID</label><input type="text" name="gh_client_id" value="' . h(setting('gh_client_id')) . '" required>'
    . '<label>Client secret</label><input type="password" name="gh_client_secret" placeholder="' . $secretSet('gh_client_secret') . '">'
    . '<label>Private key (.pem contents)</label><textarea name="gh_private_key" rows="4" placeholder="'
    . $secretSet('gh_private_key') . '"></textarea>'
    . '<p><button>Save</button></p></form>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="test">'
    . '<button>Test GitHub connection</button></form>'
    . '<p class="muted">Install the app on each group\'s organization; each assignment picks one of them.</p></fieldset>';
page_end();
