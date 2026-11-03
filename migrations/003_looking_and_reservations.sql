-- "Looking for teammates" flag on teams, and team slots reserved for a classmate's Arcada
-- username (only that student can take the slot; the row is removed when they join a team).

ALTER TABLE teams ADD COLUMN looking INTEGER NOT NULL DEFAULT 0;

CREATE TABLE team_reservations (
    team_id INTEGER NOT NULL REFERENCES teams (id) ON DELETE CASCADE,
    assignment_id INTEGER NOT NULL,
    sso_user TEXT NOT NULL COLLATE NOCASE,
    reserved_by TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (team_id, sso_user),
    UNIQUE (assignment_id, sso_user)
) STRICT;
