<?php
declare(strict_types=1);

/** DB credentials from the environment / .env (see env()), or null before setup. */
function db_config(): ?array
{
    $c = [
        'host' => env('DB_HOST') ?? 'localhost',
        'port' => (int) (env('DB_PORT') ?: 3306),
        'name' => env('DB_NAME') ?? '',
        'user' => env('DB_USER') ?? '',
        'pass' => env('DB_PASS') ?? '',
    ];
    return $c['name'] !== '' && $c['user'] !== '' ? $c : null;
}

function db_connect(array $c): PDO
{
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $c = db_config() ?? throw new RuntimeException('Database is not configured.');
        $pdo = db_connect($c);
        if (IS_DEV_SERVER) {
            // New migration files take effect on reload. A failure is only flashed, so the
            // settings page (with its reset button) stays usable.
            try {
                migrate($pdo);
            } catch (RuntimeException $e) {
                flash('err', $e->getMessage());
            }
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function is_duplicate_key(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) === 1062;
}

// ---- key/value settings (GitHub App config, cached installation token) ----

function &settings_cache(): array
{
    static $cache;
    $cache ??= q('SELECT k, v FROM classroom_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache;
}

function setting(string $k): ?string
{
    return db_config() ? settings_cache()[$k] ?? null : null;
}

function set_setting(string $k, string $v): void
{
    q('INSERT INTO classroom_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    $cache = &settings_cache();
    $cache[$k] = $v;
}

// ---- migrations ----
// Files in migrations/ named NNN_description.sql, applied once each in name order and
// recorded in schema_migrations. Statements are separated by a ";" at the end of a line.
// MySQL commits DDL immediately, so a migration that fails halfway is left partly applied.

/** @return array<string,string> migration name => file path, in apply order */
function migration_files(): array
{
    $files = [];
    foreach (glob(APP_DIR . '/migrations/*.sql') as $f) {
        $files[basename($f, '.sql')] = $f;
    }
    ksort($files, SORT_STRING);
    return $files;
}

/** @return string[] names of migrations not yet applied */
function pending_migrations(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS classroom_schema_migrations (
        name VARCHAR(191) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $applied = $pdo->query('SELECT name FROM classroom_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff(array_keys(migration_files()), $applied));
}

/** Applies pending migrations; returns the names applied. */
function migrate(PDO $pdo): array
{
    $pending = pending_migrations($pdo);
    $files = migration_files();
    foreach ($pending as $name) {
        foreach (preg_split('/;\s*$/m', file_get_contents($files[$name])) as $sql) {
            // Skip the empty tail and comment-only chunks.
            if (trim(preg_replace('/^\s*--.*$/m', '', $sql)) === '') {
                continue;
            }
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
            }
        }
        $pdo->prepare('INSERT INTO classroom_schema_migrations (name) VALUES (?)')->execute([$name]);
    }
    return $pending;
}

/** Drops the app's (classroom_*) tables, then re-runs all migrations. For the dev server only. */
function reset_database(PDO $pdo): array
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query("SHOW TABLES LIKE 'classroom\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec('DROP TABLE `' . str_replace('`', '``', $t) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return migrate($pdo);
}

/** Banner for admin pages when the database schema is behind the migration files. */
function pending_migrations_notice(): string
{
    $n = count(pending_migrations(db()));
    return $n ? '<p class="err">' . $n . ' database migration(s) pending. '
        . '<a href="' . h(url('admin/settings.php')) . '">Run them on the settings page.</a></p>' : '';
}
