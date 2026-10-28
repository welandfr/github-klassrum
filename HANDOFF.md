# Session handoff (2026-10-28)

Context for continuing this work in a new Claude Code session. Start the new session with:
"Read classroom/HANDOFF.md and README.md, then continue."

## Goal
A minimal substitute for the retired GitHub Classroom, running on the university's SSO-protected PHP server. Students open a join link per assignment, then create or join a team. Creating a team generates a **private** repo in the classroom org from the assignment's template repo. Only team members (invited as push collaborators) and the teachers (org owners) can access it. No autograding, and nothing is managed after the repo is created; that all happens on GitHub.

## Decisions made with the user
- **Identity**: `$_SERVER['REMOTE_USER']` from the SSO, linked to a **verified GitHub login via OAuth**.
- **GitHub API**: a **GitHub App** installed on the org. It provides installation tokens for API calls, and the same app handles the OAuth login.
- **Storage**: **SQLite** (switched from MySQL once the server got pdo_sqlite), one file, by default `classroom.sqlite` next to `.env` (`~/classroom-config/`, else `config/`); `DB_PATH` overrides. Config is in `.env`, edited by hand and never committed; `config/.env.example` is the template.
- **Admins**: SSO usernames in `ADMINS` in `.env`, comma-separated.
- **Orgs**: one GitHub org per student group, chosen per assignment from the orgs the single GitHub App is installed on (app set to "Any account"). The template can be any public template, or a private one in that org.
- **Team creation**: short transactions only (SQLite has one writer): the team is reserved with `repo_url` NULL ("being created", not joinable), the repo is generated outside any transaction, and the team is deleted on failure or after 10 minutes if abandoned.
- **Teams**: the creator makes the team and repo, and others join until `max_team_size`. The repo name is fixed as `<slug>-<creator github login>`, never renamed on join, with `-2`, `-3`… added if the name is taken.
- **No dependencies**: no Composer.

## Server facts (from the user's check script)
- PHP 8.4.27 with pdo_sqlite (SQLite 3.46.1), pdo_mysql, curl and openssl.
- The app is deployed in a **user dir** (`~user/public_html/...`). HTTPS is on, and SSO sets `REMOTE_USER`.
- Not yet known: which user PHP runs as, whether `~/classroom-config/` is writable by it, and whether home is on NFS (bad for SQLite locking). `dev/check.php` reports all of these; run it on the server.

## Status
- All files lint clean. The admin pages, migrations and `.env` setup were tested locally against MySQL. The SQLite version is **not yet run in PHP** (the dev container lacks pdo_sqlite); its SQL was checked with Python's sqlite3 3.40. Nothing that calls GitHub has been tested yet.
- Layout: `index.php`, `join.php`, `oauth.php`, `admin/{index,assignment,settings}.php`, `lib/{bootstrap,db,github,actions}.php`, `config/.env.example`, `migrations/*.sql`, `dev/router.php`, `dev/check.php`, `.htaccess` deny in `lib/` and `config/`.

## Next steps
1. Add `php8.4-sqlite3` to the dev image and test the SQLite version locally.
2. Run `dev/check.php` on the server.
3. Deploy, create `~/classroom-config/.env` with `ADMINS`, open `admin/settings.php`, run the migrations, and set up the GitHub App (see README).
4. End-to-end test: create an assignment with team size 2. Then, with two accounts, create a team and join it, check that a third join is blocked, check the repo is private in the org, and check the invitations arrived.

## Points to watch while testing
- `base_path()` in `lib/bootstrap.php` derives the URL prefix from `SCRIPT_FILENAME`/`SCRIPT_NAME`. Check that this works with userdir and any symlinks.
- Whether an org's installation token may generate a repo from a public template owned by another account.
- `invite_member()` retries on 404, because a freshly generated repo may not be ready yet.
- The org's base permission must be "No permission". Saving an assignment warns if it isn't (or says it can't check without the optional org permission).
- Not verified: whether the org's "Repository invitations" policy lets the app invite outside collaborators.

## Side topic (unrelated): running containers on the school server "xena"
- `unshare -Ur` works and `/dev/fuse` exists, but there are no `/etc/subuid` entries and no `newuidmap`. That means only single-UID rootless Podman is possible.
- Plan:
  1. Install a static Podman build (mgoltzsche/podman-static) into `~/.local`.
  2. In `storage.conf`, set `ignore_chown_errors = "true"` and use `fuse-overlayfs`. In `containers.conf`, set `cgroup_manager = "cgroupfs"` and `events_logger = "file"`.
  3. Copy the image over with `podman save`/`podman load`, and run it with `--user 0 -e HOME=/root`.
  4. Use `tmux`, and check the disk quota first.
- None of this has been tested on xena yet.
