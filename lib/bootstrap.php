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
.actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 0}
tr.group th{padding-top:14px;font-size:14px;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
td.actions-cell{white-space:nowrap;text-align:right}
button[data-copy]{min-width:8.5em}
.badge{display:inline-block;padding:0 8px;border-radius:10px;font-size:12px;font-weight:600;color:var(--accent);background:rgba(127,127,127,.15)}
figure{margin:12px 0}
figure img{display:block;max-width:100%;height:auto;border:1px solid var(--line);border-radius:6px}
dd{margin:0 0 12px 0}
h3{margin:16px 0 4px}
body[data-busy] button{pointer-events:none;cursor:progress}
button.busy{opacity:.75}
button.busy::after{content:"";display:inline-block;width:.8em;height:.8em;margin-left:.5em;vertical-align:-.05em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){button.busy::after{animation-duration:2s}}
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

/** An attribute from the SSO login (mod_auth_mellon: MELLON_displayName, MELLON_arcadaRole, ...), or ''. */
function sso_attr(string $name): string
{
    return $_SERVER["MELLON_$name"] ?? $_SERVER["REDIRECT_MELLON_$name"] ?? '';
}

/** @return string[] the user's roles from the SSO login (MELLON_arcadaRole_0, _1, ...) */
function sso_roles(): array
{
    $roles = [];
    for ($i = 0; ($r = sso_attr("arcadaRole_$i")) !== ''; $i++) {
        $roles[] = $r;
    }
    return $roles ?: array_filter([sso_attr('arcadaRole')]);
}

/** Main admins (ADMINS): their own assignments, like any teacher, plus the settings. */
function is_admin(): bool
{
    $admins = array_filter(array_map('trim', explode(',', env('ADMINS') ?? '')));
    return in_array(sso_user(), $admins, true);
}

/** Teachers create assignments and only see their own. */
function is_teacher(): bool
{
    return is_admin() || in_array('teacher', sso_roles(), true);
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

function require_teacher(): void
{
    if (!is_teacher()) {
        if ((env('ADMINS') ?? '') === '') {
            require_admin();  // fresh install: shows the setup instructions
        }
        http_response_code(403);
        exit('Teachers only.');
    }
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

/**
 * A button that opens a confirmation dialog holding the actual form. $extra is HTML added to the
 * dialog, $class styles the button (a "danger" class also marks the dialog's OK button), and $ok
 * labels the OK button (default: $label).
 */
function confirm_button(string $action, array $fields, string $label, string $confirm, string $extra = '',
    string $class = 'small danger', ?string $ok = null): string
{
    $hidden = '';
    foreach (['action' => $action] + $fields as $k => $v) {
        $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return '<button type="button" class="' . $class . '" onclick="this.nextElementSibling.showModal()">' . h($label) . '</button>'
        . '<dialog><form method="post">' . csrf_field() . $hidden . '<p>' . h($confirm) . '</p>' . $extra
        . '<p><button type="button" class="secondary" onclick="this.closest(\'dialog\').close()">Cancel</button> '
        . '<button' . (str_contains($class, 'danger') ? ' class="danger"' : '') . '>' . h($ok ?? ucfirst($label)) . '</button></p></form></dialog>';
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

/** The commit the app's git checkout is at, or null when it isn't a checkout. */
function deployed_commit(): ?string
{
    $git = APP_DIR . '/.git';
    $head = trim((string) @file_get_contents("$git/HEAD"));
    if (str_starts_with($head, 'ref: ')) {
        $ref = substr($head, 5);
        $head = trim((string) @file_get_contents("$git/$ref"));
        if ($head === '') {
            // After git gc the branch may only be listed in packed-refs.
            $packed = (string) @file_get_contents("$git/packed-refs");
            $head = preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m) ? $m[1] : '';
        }
    }
    return preg_match('/^[0-9a-f]{40}$/', $head) ? $head : null;
}

function page_start(string $title): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · ' . APP_NAME . '</title><style>' . CSS . '</style></head><body>'
        . '<header><div><a class="name" href="' . h(url()) . '">' . APP_NAME . '</a>';
    // Not sso_user(): that exits, and this also renders error pages.
    $user = $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '';
    $name = sso_attr('displayName');
    $role = sso_attr('arcadaRole');
    if ($user !== '') {
        remember_name($user);
    }
    echo '<span class="muted">' . ($user !== '' ? ($name !== '' ? h($name) . ' (' . h($user) . ')' : h($user))
            . ($role !== '' ? ' · ' . h($role) : '') . ' · ' : '')
        . ($user !== '' && is_teacher() ? '<a href="' . h(url('admin/')) . '">Teacher</a> · ' : '')
        . '<details><summary>About</summary><div class="about">'
        . '<p><b>' . APP_NAME . '</b> creates private GitHub repositories for student teams from assignment templates.</p>'
        . '<p>By Fredrik Welander.</p>'
        . '<p>Free software under the <a href="https://www.gnu.org/licenses/gpl-3.0.html">GNU General Public License v3</a> or later.</p>'
        . '<p>Source: <a href="' . APP_REPO . '">' . h(substr(APP_REPO, 8)) . '</a></p>'
        . (($commit = deployed_commit()) ? '<p>Version: <a href="' . APP_REPO . '/commit/' . $commit . '">' . substr($commit, 0, 7) . '</a></p>' : '')
        . '</div></details></span></div></header><main><h1>' . h($title) . '</h1>';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<p class="' . h($type) . '">' . h($msg) . '</p>';
    }
    unset($_SESSION['flash']);
}

// Page script. After the first submit, show a spinner on the clicked button and ignore further submits until the
// next page loads, so slow GitHub calls aren't started twice. Buttons aren't disabled, since a
// disabled submit button would drop its name/value from the form data.
const BUSY_JS = <<<'JS'
document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;  // e.g. a cancelled confirm()
    if (document.body.dataset.busy) { e.preventDefault(); return; }
    document.body.dataset.busy = '1';
    if (e.submitter) e.submitter.classList.add('busy');
});
// The header's About panel closes on a click outside it, or on Escape.
document.addEventListener('click', function (e) {
    var about = document.querySelector('header details[open]');
    if (about && !about.contains(e.target)) about.open = false;
});
document.addEventListener('keydown', function (e) {
    var about = document.querySelector('header details[open]');
    if (about && e.key === 'Escape') about.open = false;
});
// Buttons with data-copy copy that text (e.g. a join link) to the clipboard.
document.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-copy]');
    if (!b || !navigator.clipboard) return;
    navigator.clipboard.writeText(b.dataset.copy).then(function () {
        var label = b.textContent;
        b.textContent = 'Copied';
        setTimeout(function () { b.textContent = label; }, 1500);
    });
});
window.addEventListener('pageshow', function (e) {  // coming back via the back button
    if (e.persisted) {
        delete document.body.dataset.busy;
        document.querySelectorAll('button.busy').forEach(function (b) { b.classList.remove('busy'); });
    }
});
JS;

function page_end(): void
{
    echo '</main><script>' . BUSY_JS . '</script></body></html>';
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
