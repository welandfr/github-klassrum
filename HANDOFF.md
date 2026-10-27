# Session handoff (2026-10-27)

Context for continuing this work in a new Claude Code session. Start the new session with:
"Read classroom/HANDOFF.md and README.md, then continue."

## Goal
A minimal substitute for the retired GitHub Classroom, running on the university's SSO-protected PHP server. Students open a join link per assignment, then create or join a team. Creating a team generates a **private** repo in the classroom org from the assignment's template repo. Only team members (invited as push collaborators) and the teachers (org owners) can access it. No autograding, and nothing is managed after the repo is created; that all happens on GitHub.

## Decisions made with the user
- **Identity**: `$_SERVER['REMOTE_USER']` from the SSO, linked to a **verified GitHub login via OAuth**.
- **GitHub API**: a **GitHub App** installed on the org. It provides installation tokens for API calls, and the same app handles the OAuth login.
- **Storage**: **MySQL**. The server has no pdo_sqlite. DB credentials are entered on the admin page and written to `config/settings.php`.
- **Admins**: SSO usernames listed in `config/admins.php`, which is edited by hand.
- **Orgs**: one GitHub org per student group, chosen per assignment from the orgs the single GitHub App is installed on (app set to "Any account"). The template can be any public template, or a private one in that org.
- **Teams**: the creator makes the team and repo, and others join until `max_team_size`. The repo name is fixed as `<slug>-<creator github login>`, never renamed on join, with `-2`, `-3`… added if the name is taken.
- **No dependencies**: no Composer.

## Server facts (from the user's check script)
- PHP 8.4.27, with pdo_mysql, curl and openssl. **No pdo_sqlite.**
- The app is deployed in a **user dir** (`~user/public_html/...`). HTTPS is on, and SSO sets `REMOTE_USER`.
- `/var/www` is not writable. Whether the home dir is writable hasn't been checked; `bootstrap.php` uses `~/classroom-config/` if it exists.

## Status
- All files are written (see README "Deploy" and the layout below), but **none of it has been run or linted**, because the old container had no PHP.
- Layout: `index.php`, `join.php`, `oauth.php`, `admin/{index,assignment,settings}.php`, `lib/{bootstrap,db,github,actions}.php`, `config/admins.php`, `.htaccess` deny in `lib/` and `config/`.

## Next steps
1. `php -l` every file, either locally if PHP is available or on the server:
   `for f in $(find . -name '*.php'); do php -l "$f"; done`
2. Deploy, add the admin SSO user, open `admin/settings.php`, and set up the DB and the GitHub App (see README).
3. End-to-end test: create an assignment with team size 2. Then, with two accounts, create a team and join it, check that a third join is blocked, check the repo is private in the org, and check the invitations arrived.

## Points to watch while testing
- `base_path()` in `lib/bootstrap.php` derives the URL prefix from `SCRIPT_FILENAME`/`SCRIPT_NAME`. Check that this works with userdir and any symlinks.
- `create_team()` (`lib/actions.php`) keeps the DB transaction open while calling GitHub's generate endpoint. This is intentional, because the unique keys block double submits.
- `invite_member()` retries on 404, because a freshly generated repo may not be ready yet.
- The org's base permission must be "No permission". The settings page's "Test" button warns if it isn't.
- Not verified: whether the org's "Repository invitations" policy lets the app invite outside collaborators.

## Side topic (unrelated): running containers on the school server "xena"
- `unshare -Ur` works and `/dev/fuse` exists, but there are no `/etc/subuid` entries and no `newuidmap`. That means only single-UID rootless Podman is possible.
- Plan:
  1. Install a static Podman build (mgoltzsche/podman-static) into `~/.local`.
  2. In `storage.conf`, set `ignore_chown_errors = "true"` and use `fuse-overlayfs`. In `containers.conf`, set `cgroup_manager = "cgroupfs"` and `events_logger = "file"`.
  3. Copy the image over with `podman save`/`podman load`, and run it with `--user 0 -e HOME=/root`.
  4. Use `tmux`, and check the disk quota first.
- None of this has been tested on xena yet.
