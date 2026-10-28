# Mini-Classroom

A minimal replacement for GitHub Classroom's repo provisioning. Students open an assignment's join link, connect their GitHub account, and either create a team or join an existing one. Creating a team generates a **private** repo in the assignment's GitHub org (one org per student group) from the assignment's template repo, and each team member gets a push-access invitation. After that, everything is managed on GitHub.

Requires PHP 8.1+ with `pdo_sqlite` (SQLite 3.37+), `curl` and `openssl`, and SSO that sets `$_SERVER['REMOTE_USER']`. Data is stored in a single SQLite file. It has no other dependencies.

## 1. Deploy

1. Copy this folder to the server, e.g. `~/public_html/classroom/`, inside the SSO-protected area.
2. Create `~/classroom-config/`, one level above `public_html`, writable by the web server. It holds the config and the database.
3. Copy `config/.env.example` to `~/classroom-config/.env` and set `ADMINS` to your SSO username (comma-separate several).
   - The database is `classroom.sqlite` in the same directory unless `DB_PATH` says otherwise. SQLite also creates `-wal` and `-shm` files next to it, so the directory itself must be writable, not just the file.
   - If `~/classroom-config/` doesn't exist, `config/` inside the app is used. Avoid that: files in the web root are only protected by `.htaccess`, and the database and `.env` could be downloaded if the server ignores it.
   - `dev/check.php` checks the server: which user PHP runs as, writability and filesystem of `~/classroom-config/`, SQLite locking, and outbound HTTPS. Delete it from the server afterwards.
   - Real environment variables (e.g. Apache `SetEnv`) override `.env` values.
   - Until this is done, the admin pages show these instructions.

## 2. Create the GitHub App

One app serves all group orgs. Create it under one of the orgs (or your user account): **Settings → Developer settings → GitHub Apps → New GitHub App**

- **Homepage URL**: `https://<host>/~<you>/classroom/`
- **Callback URL**: `https://<host>/~<you>/classroom/oauth.php` (also shown on the settings page)
- **Webhook**: uncheck *Active*
- **Repository permissions**: *Administration*: Read and write · *Contents*: Read-only · *Metadata*: Read-only
- **Organization permissions** (optional): *Administration*: Read-only. This lets the app check each org's base permission.
- **Where can this app be installed**: Any account. The app only acts on orgs where it is installed.

After creating it, note the **Client ID**, generate a **client secret**, and generate a **private key** (.pem). Then use **Install App** to install it on each group's org with *All repositories*. For a new group, install it on that org and it appears in the assignment form.

## 3. Org settings

For each group org:


- **Member privileges → Base permissions: No permission.** Students are added as outside collaborators to their own repos only. Any org members (e.g. TAs) would otherwise see every repo.
- **Member privileges → Repository creation**: disable for members.
- Teachers should be org **owners**, which gives them access to all repos.
- If invitations fail with HTTP 403/422, check **Member privileges → Repository invitations**. Inviting outside collaborators must not be restricted in a way that blocks the app.

## 4. Configure the app

Open `https://<host>/~<you>/classroom/admin/settings.php`:

1. Check that the database shows its path without errors, and click **Run pending migrations** to create the tables.
2. Enter the Client ID, client secret and private key, then click **Test GitHub connection**. It lists the orgs the app is installed on.

## 5. Assignments

On the **Admin → New assignment** page:

- **GitHub organization**: one of the orgs the app is installed on. It can't be changed once teams exist.
- **Template**: a repo marked as a template (repo *Settings → Template repository*), given as `owner/repo` or its URL. It can be any public template, or a private one in the chosen org.
- **Slug**: repo name prefix, unique within the org. Repos are named `<slug>-<github-login of team creator>`, with `-2`, `-3`, … appended if the name is taken.
- **Max team size**: use 1 for individual work.
- **Open**: untick to stop new teams and joins.

Share the join link it shows. The assignment page lists teams, repos and members, and has a **Retry failed invitations** button. A typical cause of failed invitations is GitHub not having the repo ready yet.

## Database migrations

Schema changes live in `migrations/` as `NNN_description.sql` (e.g. `002_add_due_date.sql`). Each file is applied once, in name order, and recorded in the `schema_migrations` table. Separate statements with a `;` at the end of a line. Never edit a migration that has been applied in production; add a new one instead.

- **Production**: after deploying new migration files, admin pages show a "migrations pending" banner. Apply them with **Run pending migrations** on the settings page. Each migration runs in one transaction, so a failing one changes nothing.
- **Development** (PHP's built-in server with `DB_IS_DEV=1` in `.env`): pending migrations are applied on every page load. **Reset database** on the settings page drops all tables, including the GitHub settings, and re-runs every migration. Use it after editing an already-applied migration. Without `DB_IS_DEV=1`, e.g. when developing against the production database, the dev server behaves like production here: no reset button, and migrations only run from the button.

## Local development

```
php -S 0.0.0.0:8000 -t . dev/router.php
```

The router stands in for SSO: requests run as `dev` (or `$DEV_USER`), and `?as=<user>` switches user. Under the built-in server the app uses `http://` URLs and a non-`Secure` session cookie.

Create `config/.env` (gitignored) with `ADMINS=dev`. The dev database is then `config/classroom.sqlite` (gitignored); add `DB_IS_DEV=1` if it is disposable (see Database migrations).

## Notes

- Students must accept the GitHub invitation (`<repo-url>/invitations`) before the repo is visible to them.
- Each student can be in one team per assignment. Changes made later on GitHub (removing members, renaming repos) are not tracked here.
- Creating a team reserves it in the database, then creates the repo on GitHub outside any transaction, because SQLite allows one writer at a time. While that runs, the team is shown as "being created" and can't be joined. If the request dies halfway, the team is removed after 10 minutes so the student can try again; a repo GitHub did create stays in the org, and the retry gets the next name (`-2`).
- Back up the database with `sqlite3 classroom.sqlite ".backup backup.sqlite"` rather than copying the file while the app runs.
- A student who re-links a different GitHub account keeps their existing memberships. Invitations already sent stay with the old account.
