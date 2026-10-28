-- Initial schema (SQLite). Timestamps are UTC text, 'YYYY-MM-DD HH:MM:SS'.
-- GitHub names (orgs, repos) are case-insensitive, hence COLLATE NOCASE.

CREATE TABLE settings (
    k TEXT PRIMARY KEY,
    v TEXT NOT NULL
) STRICT;

CREATE TABLE users (
    sso_user TEXT PRIMARY KEY,
    github_id INTEGER NOT NULL UNIQUE,
    github_login TEXT NOT NULL,
    linked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
) STRICT;

CREATE TABLE assignments (
    id INTEGER PRIMARY KEY,
    org TEXT NOT NULL COLLATE NOCASE,
    slug TEXT NOT NULL COLLATE NOCASE,
    title TEXT NOT NULL,
    template_owner TEXT NOT NULL,
    template_repo TEXT NOT NULL,
    max_team_size INTEGER NOT NULL DEFAULT 1,
    invite_code TEXT NOT NULL UNIQUE,
    is_open INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (org, slug)
) STRICT;

CREATE TABLE teams (
    id INTEGER PRIMARY KEY,
    assignment_id INTEGER NOT NULL REFERENCES assignments (id),
    name TEXT NOT NULL COLLATE NOCASE,
    org TEXT NOT NULL COLLATE NOCASE,
    repo_name TEXT NOT NULL COLLATE NOCASE,
    repo_url TEXT,  -- NULL while the repo is being created
    created_by TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (assignment_id, name),
    UNIQUE (org, repo_name)
) STRICT;

CREATE TABLE team_members (
    team_id INTEGER NOT NULL REFERENCES teams (id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL,
    sso_user TEXT NOT NULL,
    invite_status TEXT NOT NULL DEFAULT 'pending',
    invite_error TEXT,
    joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (team_id, sso_user),
    UNIQUE (assignment_id, sso_user)
) STRICT;
