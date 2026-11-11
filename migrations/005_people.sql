-- Real names from the SSO login, saved when a user visits, so lists can show other users' names.
CREATE TABLE people (
    sso_user TEXT PRIMARY KEY COLLATE NOCASE,
    display_name TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
) STRICT;
