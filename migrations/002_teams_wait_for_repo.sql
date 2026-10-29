-- Teams can now exist before their repo: repo_name stays NULL until creation starts, and
-- creating_since is set while the repo is being created (repo_url is still NULL then).
-- SQLite can't drop NOT NULL in place, so the table is rebuilt (foreign keys are off during migrations).

CREATE TABLE teams_new (
    id INTEGER PRIMARY KEY,
    assignment_id INTEGER NOT NULL REFERENCES assignments (id),
    name TEXT NOT NULL COLLATE NOCASE,
    org TEXT NOT NULL COLLATE NOCASE,
    repo_name TEXT COLLATE NOCASE,
    repo_url TEXT,
    creating_since TEXT,
    created_by TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (assignment_id, name),
    UNIQUE (org, repo_name)
) STRICT;

INSERT INTO teams_new (id, assignment_id, name, org, repo_name, repo_url, creating_since, created_by, created_at)
SELECT id, assignment_id, name, org, repo_name, repo_url, CASE WHEN repo_url IS NULL THEN created_at END, created_by, created_at
FROM teams;

DROP TABLE teams;

ALTER TABLE teams_new RENAME TO teams;
