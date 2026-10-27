<?php
declare(strict_types=1);

/** DB credentials from the config file written by admin/settings.php, or null before setup. */
function db_config(): ?array
{
    static $cfg;
    if ($cfg === null) {
        $f = CONFIG_DIR . '/settings.php';
        $cfg = is_file($f) ? (require $f)['db'] ?? false : false;
    }
    return $cfg ?: null;
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
    $cache ??= q('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $cache;
}

function setting(string $k): ?string
{
    return db_config() ? settings_cache()[$k] ?? null : null;
}

function set_setting(string $k, string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    $cache = &settings_cache();
    $cache[$k] = $v;
}

function create_schema(PDO $pdo): void
{
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT NOT NULL
    ) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        sso_user VARCHAR(191) PRIMARY KEY,
        github_id BIGINT UNSIGNED NOT NULL UNIQUE,
        github_login VARCHAR(100) NOT NULL,
        linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS assignments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(40) NOT NULL UNIQUE,
        title VARCHAR(200) NOT NULL,
        template_owner VARCHAR(100) NOT NULL,
        template_repo VARCHAR(100) NOT NULL,
        max_team_size TINYINT UNSIGNED NOT NULL DEFAULT 1,
        invite_code CHAR(16) NOT NULL UNIQUE,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS teams (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        assignment_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        repo_name VARCHAR(100) NOT NULL UNIQUE,
        repo_url VARCHAR(255) NULL,
        created_by VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY team_name (assignment_id, name),
        FOREIGN KEY (assignment_id) REFERENCES assignments(id)
    ) $opts");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_members (
        team_id INT UNSIGNED NOT NULL,
        assignment_id INT UNSIGNED NOT NULL,
        sso_user VARCHAR(191) NOT NULL,
        invite_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        invite_error VARCHAR(255) NULL,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_id, sso_user),
        UNIQUE KEY one_team_per_assignment (assignment_id, sso_user),
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
    ) $opts");
}
