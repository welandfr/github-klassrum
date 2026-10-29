<?php
declare(strict_types=1);

/** SQLite database file: DB_PATH from the environment / .env (see env()), else in the config dir. */
function db_path(): string
{
    return env('DB_PATH') ?: CONFIG_DIR . '/classroom.sqlite';
}

function db_connect(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 15,  // seconds to wait for another request's write lock
    ]);
    // WAL lets reads continue during a write; foreign keys are off by default in SQLite.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/**
 * True when the dev conveniences (auto-migrate, reset) may touch the database: only under
 * PHP's built-in server and with DB_IS_DEV=1, so a dev setup pointed at production is safe.
 */
function is_dev_db(): bool
{
    return IS_DEV_SERVER && env('DB_IS_DEV') === '1';
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $pdo = db_connect(db_path());
        if (is_dev_db()) {
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

/**
 * Runs $fn in a write transaction and returns its result. BEGIN IMMEDIATE takes SQLite's
 * single write lock up front, so what $fn reads stays valid until it commits.
 * Keep it short (no GitHub calls inside): every other write waits for it.
 */
function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn();
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function is_duplicate_key(PDOException $e): bool
{
    return str_contains($e->getMessage(), 'UNIQUE constraint failed');
}

// ---- key/value settings (GitHub App config, cached installation tokens) ----

function &settings_cache(): array
{
    static $cache;
    $cache ??= q('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache;
}

function setting(string $k): ?string
{
    return settings_cache()[$k] ?? null;
}

function set_setting(string $k, string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT (k) DO UPDATE SET v = excluded.v', [$k, $v]);
    $cache = &settings_cache();
    $cache[$k] = $v;
}

// ---- migrations ----
// Files in migrations/ named NNN_description.sql, applied once each in name order and
// recorded in schema_migrations. Statements are separated by a ";" at the end of a line.
// Each migration runs in one transaction, so a failing one leaves no trace.

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

/** @return array<string,string> applied migration name => applied_at (UTC) */
function applied_migrations(PDO $pdo): array
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetch();
    return $exists ? $pdo->query('SELECT name, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
}

/** @return string[] names of migrations not yet applied */
function pending_migrations(PDO $pdo): array
{
    return array_values(array_diff(array_keys(migration_files()), array_keys(applied_migrations($pdo))));
}

/** False on a fresh install, before the first migration has created the tables. */
function schema_installed(): bool
{
    return applied_migrations(db()) !== [];
}

/** Applies pending migrations; returns the names applied. */
function migrate(PDO $pdo): array
{
    $pending = pending_migrations($pdo);
    $files = migration_files();
    // Table rebuilds (SQLite's way to change a column) must not cascade deletes, so foreign keys
    // are off while migrating and checked before each commit. The pragma is ignored inside a transaction.
    $pdo->exec('PRAGMA foreign_keys = OFF');
    try {
        migrate_files($pdo, $pending, $files);
    } finally {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pending;
}

function migrate_files(PDO $pdo, array $pending, array $files): void
{
    foreach ($pending as $name) {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                name TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) STRICT');
            foreach (preg_split('/;\s*$/m', file_get_contents($files[$name])) as $sql) {
                // Skip the empty tail and comment-only chunks.
                if (trim(preg_replace('/^\s*--.*$/m', '', $sql)) !== '') {
                    $pdo->exec($sql);
                }
            }
            if ($pdo->query('PRAGMA foreign_key_check')->fetch()) {
                throw new PDOException('it leaves rows that violate a foreign key');
            }
            $pdo->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $pdo->exec('COMMIT');
        } catch (PDOException $e) {
            $pdo->exec('ROLLBACK');
            throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
        }
    }
}

/** Drops every table, then re-runs all migrations. For the dev server only. */
function reset_database(PDO $pdo): array
{
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $pdo->exec('DROP TABLE "' . str_replace('"', '""', $t) . '"');
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    return migrate($pdo);
}

/** Banner for admin pages when the database schema is behind the migration files. */
function pending_migrations_notice(): string
{
    $n = count(pending_migrations(db()));
    return $n ? '<p class="err">' . $n . ' database migration(s) pending. '
        . '<a href="' . h(url('admin/settings.php')) . '">Run them on the settings page.</a></p>' : '';
}
