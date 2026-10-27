<?php
declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));
// Prefer a config dir outside the web root (~/classroom-config) when it exists.
define('CONFIG_DIR', is_dir(dirname(APP_DIR, 2) . '/classroom-config')
    ? dirname(APP_DIR, 2) . '/classroom-config'
    : APP_DIR . '/config');

const CSS = <<<'CSS'
:root{--fg:#1f2328;--bg:#fff;--muted:#656d76;--line:#d0d7de;--accent:#0969da;--ok:#1a7f37;--err:#cf222e}
@media (prefers-color-scheme:dark){:root{--fg:#e6edf3;--bg:#0d1117;--muted:#8d96a0;--line:#30363d;--accent:#4493f8;--ok:#3fb950;--err:#f85149}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.5 system-ui,sans-serif}
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
    'secure' => true,
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
    static $admins;
    if ($admins === null) {
        $f = CONFIG_DIR . '/admins.php';
        $admins = is_file($f) ? (array) (require $f) : [];
    }
    return in_array(sso_user(), $admins, true);
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(403);
        exit('Admins only.');
    }
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
    return 'https://' . $_SERVER['HTTP_HOST'] . base_path() . '/' . ltrim($path, '/');
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
        . '<title>' . h($title) . '</title><style>' . CSS . '</style></head><body><main>'
        . '<h1>' . h($title) . '</h1>';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<p class="' . h($type) . '">' . h($msg) . '</p>';
    }
    unset($_SESSION['flash']);
}

function page_end(): void
{
    echo '</main></body></html>';
}

/** Students see this when the admin hasn't finished setup. */
function require_configured(): void
{
    if (!db_config() || setting('gh_client_id') === null) {
        page_start('Not ready');
        echo '<p>This service has not been set up yet. Contact the teacher.</p>';
        page_end();
        exit;
    }
}
