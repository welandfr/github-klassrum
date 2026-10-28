-- Initial schema.

CREATE TABLE classroom_settings (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE classroom_users (
    sso_user VARCHAR(191) PRIMARY KEY,
    github_id BIGINT UNSIGNED NOT NULL UNIQUE,
    github_login VARCHAR(100) NOT NULL,
    linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE classroom_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    org VARCHAR(100) NOT NULL,
    slug VARCHAR(40) NOT NULL,
    title VARCHAR(200) NOT NULL,
    template_owner VARCHAR(100) NOT NULL,
    template_repo VARCHAR(100) NOT NULL,
    max_team_size TINYINT UNSIGNED NOT NULL DEFAULT 1,
    invite_code CHAR(16) NOT NULL UNIQUE,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY org_slug (org, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE classroom_teams (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    org VARCHAR(100) NOT NULL,
    repo_name VARCHAR(100) NOT NULL,
    repo_url VARCHAR(255) NULL,
    created_by VARCHAR(191) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY team_name (assignment_id, name),
    UNIQUE KEY org_repo (org, repo_name),
    FOREIGN KEY (assignment_id) REFERENCES classroom_assignments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE classroom_team_members (
    team_id INT UNSIGNED NOT NULL,
    assignment_id INT UNSIGNED NOT NULL,
    sso_user VARCHAR(191) NOT NULL,
    invite_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    invite_error VARCHAR(255) NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (team_id, sso_user),
    UNIQUE KEY one_team_per_assignment (assignment_id, sso_user),
    FOREIGN KEY (team_id) REFERENCES classroom_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
