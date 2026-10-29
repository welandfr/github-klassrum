<?php
declare(strict_types=1);

// Server check for the SQLite switch and general deployment. Standalone: does not load the app.
// Upload with the app, open https://<host>/~<you>/classroom/dev/check.php, then delete it.
// It creates and removes a test database; it changes nothing else.

header('Content-Type: text/plain; charset=utf-8');

function line(string $label, string $value): void
{
    printf("%-38s %s\n", $label . ':', $value);
}

function yn(bool $b): string
{
    return $b ? 'yes' : 'NO';
}

/** Filesystem type of the mount containing $path, from /proc/mounts. */
function fs_type(string $path): string
{
    $best = '';
    $type = 'unknown (/proc/mounts not readable)';
    foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $m) {
        [, $mnt, $t] = explode(' ', $m) + [null, '', ''];
        $mnt = str_replace('\040', ' ', $mnt);
        if (str_starts_with($path . '/', rtrim($mnt, '/') . '/') && strlen($mnt) >= strlen($best)) {
            [$best, $type] = [$mnt, "$t (mounted at $mnt)"];
        }
    }
    return $type;
}

function user_name(int $uid): string
{
    return function_exists('posix_getpwuid') ? (posix_getpwuid($uid)['name'] ?? "uid $uid") : "uid $uid";
}

echo "== PHP\n";
line('PHP version / SAPI', PHP_VERSION . ' / ' . PHP_SAPI);
foreach (['pdo_sqlite', 'pdo_mysql', 'curl', 'openssl', 'posix'] as $ext) {
    line($ext, yn(extension_loaded($ext)));
}
line('open_basedir', ini_get('open_basedir') ?: '(none)');
line('max_execution_time', ini_get('max_execution_time') . ' s');

echo "\n== Identity\n";
$owner = fileowner(__FILE__);
$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
line('Script file owner', user_name($owner));
line('PHP process runs as', $euid === null ? 'unknown (no posix extension)' : user_name($euid));
if ($euid !== null) {
    line('Runs as the file owner', $euid === $owner
        ? 'yes (good: other users\' scripts cannot read your files via PHP)'
        : 'NO: PHP runs as a shared user; files it can read, other users\' PHP scripts can too');
}
line('REMOTE_USER', $_SERVER['REMOTE_USER'] ?? $_SERVER['REDIRECT_REMOTE_USER'] ?? '(not set)');
line('HTTPS', ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' ? 'on' : 'off/unknown');

echo "\n== Directories\n";
$appDir = dirname(__DIR__);
$home = function_exists('posix_getpwuid') ? (posix_getpwuid($owner)['dir'] ?? null) : null;
$home ??= dirname($appDir, 2);  // assumes ~/public_html/<app>
$configDir = $home . '/classroom-config';
$candidates = [];
foreach (['Home' => $home, '~/classroom-config' => $configDir, 'App dir' => $appDir] as $label => $dir) {
    if (!is_dir($dir)) {
        line($label, "$dir  (does not exist)");
        continue;
    }
    $writable = is_writable($dir);
    line($label, sprintf('%s  perms %o, writable by PHP: %s', $dir, fileperms($dir) & 0777, yn($writable)));
    line('  filesystem', fs_type(realpath($dir) ?: $dir));
    if ($writable && $label !== 'App dir') {
        $candidates[] = $dir;
    }
}

echo "\n== SQLite test\n";
if (!extension_loaded('pdo_sqlite')) {
    echo "Skipped: pdo_sqlite is not loaded.\n";
} elseif (!$candidates) {
    echo "Skipped: neither the home dir nor ~/classroom-config is writable by PHP.\n"
        . "Create ~/classroom-config and make it writable by the PHP user, then reload.\n";
} else {
    $dir = in_array($configDir, $candidates, true) ? $configDir : $candidates[0];
    $file = $dir . '/.check-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $a = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        line('Test database', $file);
        line('SQLite version', (string) $a->query('SELECT sqlite_version()')->fetchColumn());
        line('journal_mode=WAL', (string) $a->query('PRAGMA journal_mode=WAL')->fetchColumn());
        $a->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $a->exec("INSERT INTO t (v) VALUES ('ok')");
        // A second connection must see the committed row and be locked out during a write.
        $b = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 1]);
        line('Second connection reads', (string) $b->query('SELECT v FROM t')->fetchColumn());
        $a->exec('BEGIN IMMEDIATE');
        try {
            $b->exec('BEGIN IMMEDIATE');
            line('Write locking', 'NO: second writer was not blocked (locking is broken on this filesystem)');
            $b->exec('ROLLBACK');
        } catch (PDOException $e) {
            line('Write locking', 'yes (second writer blocked as expected)');
        }
        $a->exec('COMMIT');
        line('Result', 'OK');
    } catch (Throwable $e) {
        line('Result', 'FAILED: ' . $e->getMessage());
    } finally {
        $a = $b = null;
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            @unlink($file . $suffix);
        }
    }
}

echo "\n== Outbound HTTPS\n";
if (extension_loaded('curl')) {
    foreach (['https://api.github.com/zen', 'https://github.com/'] as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'klassrum-check']);
        $ok = curl_exec($ch) !== false;
        line($u, $ok ? 'HTTP ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) : 'FAILED: ' . curl_error($ch));
    }
}

echo "\n== Manual check\n"
    . "Open .../classroom/migrations/001_initial.sql in the browser. It should be 403 Forbidden;\n"
    . "if the SQL is shown, the server ignores .htaccess files.\n"
    . "\nDelete dev/check.php from the server when done.\n";
