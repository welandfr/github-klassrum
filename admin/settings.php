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
            create_schema(db_connect($c));
        } catch (PDOException $e) {
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
            flash('ok', 'Database connected and tables created.');
            redirect('admin/settings.php');
        }
    } elseif ($action === 'github' && $db) {
        foreach (['gh_org', 'gh_app_id', 'gh_client_id'] as $k) {
            set_setting($k, trim((string) $_POST[$k]));
        }
        foreach (['gh_client_secret', 'gh_private_key'] as $k) {
            if (trim((string) $_POST[$k]) !== '') {
                set_setting($k, trim((string) $_POST[$k]));
            }
        }
        set_setting('gh_token_expires', '0');  // force a fresh token with the new settings
        flash('ok', 'GitHub settings saved.');
        redirect('admin/settings.php');
    } elseif ($action === 'test' && $db) {
        try {
            $org = (string) setting('gh_org');
            [$s, $info] = gh('GET', '/orgs/' . rawurlencode($org));
            if ($s !== 200) {
                throw gh_error("Could not read org '$org'", $s, $info);
            }
            flash('ok', "OK: the app is installed on {$info['login']} and has an access token.");
            $perm = $info['default_repository_permission'] ?? null;
            if ($perm !== null && $perm !== 'none') {
                flash('err', "Warning: the org's base permission is '$perm'. Set it to 'No permission' so students can't see each other's repos.");
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

$secretSet = fn(string $k) => setting($k) !== null && setting($k) !== '' ? '(saved; leave empty to keep)' : '';
echo '<fieldset><legend>2. GitHub App</legend>'
    . '<p class="muted">Callback URL to enter in the GitHub App: <code>' . h(url('oauth.php')) . '</code></p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="github">'
    . '<label>Organization (login)</label><input type="text" name="gh_org" value="' . h(setting('gh_org')) . '" required>'
    . '<label>App ID</label><input type="text" name="gh_app_id" value="' . h(setting('gh_app_id')) . '" required>'
    . '<label>Client ID</label><input type="text" name="gh_client_id" value="' . h(setting('gh_client_id')) . '" required>'
    . '<label>Client secret</label><input type="password" name="gh_client_secret" placeholder="' . $secretSet('gh_client_secret') . '">'
    . '<label>Private key (.pem contents)</label><textarea name="gh_private_key" rows="4" placeholder="'
    . $secretSet('gh_private_key') . '"></textarea>'
    . '<p><button>Save</button></p></form>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="test">'
    . '<button>Test GitHub connection</button></form></fieldset>';
page_end();
