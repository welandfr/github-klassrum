<?php
declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));
const APP_NAME = 'Klassrum';
const APP_REPO = 'https://github.com/welandfr/github-klassrum';
// PHP's built-in server (local dev, see dev/router.php) runs without TLS.
define('IS_DEV_SERVER', PHP_SAPI === 'cli-server');
// Prefer a config dir outside the web root (~/classroom-config) when it exists.
define('CONFIG_DIR', is_dir(dirname(APP_DIR, 2) . '/classroom-config')
    ? dirname(APP_DIR, 2) . '/classroom-config'
    : APP_DIR . '/config');

const CSS = <<<'CSS'
:root{--fg:#1f2328;--bg:#fff;--muted:#656d76;--line:#d0d7de;--accent:#0969da;--ok:#1a7f37;--err:#cf222e}
@media (prefers-color-scheme:dark){:root{--fg:#e6edf3;--bg:#0d1117;--muted:#8d96a0;--line:#30363d;--accent:#4493f8;--ok:#3fb950;--err:#f85149}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,sans-serif}
header{border-bottom:1px solid var(--line)}
header>div{position:relative;max-width:860px;margin:0 auto;padding:10px 16px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
header .name{font-weight:700;color:var(--fg);text-decoration:none}
header details{display:inline}
header summary{display:inline;list-style:none;font-weight:400;margin:0;color:var(--accent)}
header summary::-webkit-details-marker{display:none}
header .about{position:absolute;right:16px;top:100%;z-index:1;width:min(340px,calc(100vw - 32px));box-sizing:border-box;padding:12px 16px;background:var(--bg);color:var(--fg);border:1px solid var(--line);border-radius:8px}
header .about p{margin:0 0 8px}
main{max-width:860px;margin:0 auto;padding:24px 16px}
a{color:var(--accent)}
table{border-collapse:collapse;width:100%;margin:12px 0}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line);vertical-align:top}
input,textarea,select,button{font:inherit;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg);color:var(--fg)}
input[type=text],input[type=password],input[type=number],textarea{width:100%;box-sizing:border-box}
button{cursor:pointer;background:var(--accent);color:#fff;border:0}
button:disabled{opacity:.5;cursor:default}
label{display:block;margin:10px 0 4px;font-weight:600}
fieldset{border:1px solid var(--line);border-radius:8px;margin:16px 0;padding:12px 16px}
summary{cursor:pointer;font-weight:600;margin:4px 0 8px}
button.small{padding:0 6px;font-size:13px}
button.danger{background:var(--err)}
button.secondary{background:transparent;color:var(--fg);border:1px solid var(--line)}
dialog{max-width:420px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--fg)}
dialog::backdrop{background:rgba(0,0,0,.4)}
dialog label{font-weight:400}
.ok{color:var(--ok)}.err{color:var(--err)}.muted{color:var(--muted)}
code,pre{background:rgba(127,127,127,.15);padding:2px 4px;border-radius:4px;overflow-x:auto}
pre{padding:8px}
CSS;

require __DIR__ . '/db.php';
require __DIR__ . '/github.php';
require __DIR__ . '/actions.php';

ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e): void {
    error_log('classroom: ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
    }
    page_start('Error');
    echo '<p class="err">Something went wrong. Try again, or contact the teacher if the problem persists.</p>';
    if (is_admin()) {
        echo '<pre>' . h($e->getMessage()) . '</pre>';
    }
    page_end();
});

session_name('classroom');
session_set_cookie_params([
    'path' => base_path() . '/',
    'secure' => !IS_DEV_SERVER,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// ---- identity ----

function sso_user(): string
{
    $u = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '';
    if ($u === '') {
        http_response_code(403);
        exit('Not logged in: REMOTE_USER is not set. Is SSO enabled for this directory?');
    }
    return $u;
}

function is_admin(): bool
{
    $admins = array_filter(array_map('trim', explode(',', env('ADMINS') ?? '')));
    return in_array(sso_user(), $admins, true);
}

// ---- config ----

/**
 * Config value from the real environment (e.g. Apache SetEnv), else from CONFIG_DIR/.env.
 * .env lines are KEY=VALUE; blank lines and lines starting with # are ignored, and one pair
 * of surrounding quotes is removed from the value. A # inside a value is kept as is.
 */
function env(string $k): ?string
{
    static $file;
    if ($file === null) {
        $file = [];
        $path = CONFIG_DIR . '/.env';
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
                $file[$m[1]] = preg_replace('/^([\'"])(.*)\1$/', '$2', $m[2]);
            }
        }
    }
    $v = $_SERVER[$k] ?? getenv($k);
    return is_string($v) ? $v : $file[$k] ?? null;
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(403);
        if ((env('ADMINS') ?? '') !== '') {
            exit('Admins only.');
        }
        // Nobody is an admin yet, so this is a fresh install: show how to set it up.
        page_start('Setup');
        echo '<p class="err">No admins are configured yet.</p>' . env_instructions();
        page_end();
        exit;
    }
}

/** How to create the .env file, with the current user filled in as admin. */
function env_instructions(): string
{
    $path = CONFIG_DIR . '/.env';
    $example = 'ADMINS=' . sso_user() . "\n# Optional; default: " . CONFIG_DIR . "/classroom.sqlite\n# DB_PATH=/path/to/classroom.sqlite\n";
    return '<p>Configuration is read from <code>' . h($path) . '</code>'
        . (is_file($path) ? '' : ' <span class="err">(file not found)</span>') . '. '
        . 'Real environment variables (e.g. Apache <code>SetEnv</code>) override it.</p>'
        . '<ol><li>Copy <code>config/.env.example</code> to that path'
        . (str_ends_with(CONFIG_DIR, '/classroom-config') ? '' : ', or preferably create <code>~/classroom-config/</code> outside the web root and copy it there')
        . '.</li><li>Fill in the admin SSO usernames (comma-separated) and, if needed, the database path:'
        . '<pre>' . h($example) . '</pre></li>'
        . '<li>Make sure the web server can write to the database file\'s directory.</li>'
        . '<li>Reload this page.</li></ol>';
}

// ---- urls ----

/** URL path of the app root, e.g. "/~teacher/classroom". */
function base_path(): string
{
    static $base;
    if ($base === null) {
        $rel = substr(realpath($_SERVER['SCRIPT_FILENAME']), strlen(realpath(APP_DIR)));
        $base = substr($_SERVER['SCRIPT_NAME'], 0, -strlen($rel));
    }
    return $base;
}

function url(string $path = ''): string
{
    return (IS_DEV_SERVER ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'] . base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// ---- forms & output ----

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

function csrf_field(): string
{
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid form token. Go back, reload the page and try again.');
    }
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function page_start(string $title): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · ' . APP_NAME . '</title><style>' . CSS . '</style></head><body>'
        . '<header><div><a class="name" href="' . h(url()) . '">' . APP_NAME . '</a>';
    // Not sso_user(): that exits, and this also renders error pages.
    $user = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '';
    echo '<span class="muted">' . ($user !== '' ? h($user) . ' · ' : '')
        . ($user !== '' && is_admin() ? '<a href="' . h(url('admin/')) . '">Admin</a> · ' : '')
        . '<details><summary>About</summary><div class="about">'
        . '<p><b>' . APP_NAME . '</b> creates private GitHub repositories for student teams from assignment templates.</p>'
        . '<p>By Fredrik Welander.</p>'
        . '<p>Free software under the <a href="https://www.gnu.org/licenses/gpl-3.0.html">GNU General Public License v3</a> or later.</p>'
        . '<p>Source: <a href="' . APP_REPO . '">' . h(substr(APP_REPO, 8)) . '</a></p>'
        . '</div></details></span></div></header><main><h1>' . h($title) . '</h1>';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<p class="' . h($type) . '">' . h($msg) . '</p>';
    }
    unset($_SESSION['flash']);
}

function page_end(): void
{
    echo '</main></body></html>';
}

/** True once the tables exist and the GitHub App is configured. */
function app_ready(): bool
{
    return schema_installed() && setting('gh_client_id') !== null;
}

/** Students see this when the admin hasn't finished setup. */
function require_configured(): void
{
    if (!app_ready()) {
        page_start('Not ready');
        echo '<p>This service has not been set up yet. Contact the teacher.</p>';
        page_end();
        exit;
    }
}
