# Mini-Classroom

A minimal replacement for GitHub Classroom's repo provisioning. Students open an assignment's join link, connect their GitHub account, and either create a team or join an existing one. Creating a team generates a **private** repo in the assignment's GitHub org (one org per student group) from the assignment's template repo, and each team member gets a push-access invitation. After that, everything is managed on GitHub.

Requires PHP 8.1+ with `pdo_mysql`, `curl` and `openssl`, a MySQL/MariaDB database, and SSO that sets `$_SERVER['REMOTE_USER']`. It has no other dependencies.

## 1. Deploy

1. Copy this folder to the server, e.g. `~/public_html/classroom/`, inside the SSO-protected area.
2. Create the config file: copy `config/.env.example` to `~/classroom-config/.env`, one level above `public_html`. Fill in the MySQL credentials and `ADMINS`, your SSO username (comma-separate several), then make it readable only by you and the web server (e.g. `chmod 640`).
   - If `~/classroom-config/` exists it is used, otherwise `config/.env` inside the app. Prefer the former: a `.env` inside the web root is only protected by `.htaccess`, and would be served as plain text if the server ignores it.
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

1. Check that the database shows as connected, and click **Run pending migrations** to create the tables.
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

Schema changes live in `migrations/` as `NNN_description.sql` (e.g. `002_add_due_date.sql`). Each file is applied once, in name order, and recorded in the `classroom_schema_migrations` table. All tables use the `classroom_` prefix, so the app can share a database. Separate statements with a `;` at the end of a line. Never edit a migration that has been applied in production; add a new one instead.

- **Production**: after deploying new migration files, admin pages show a "migrations pending" banner. Apply them with **Run pending migrations** on the settings page. MySQL commits schema changes immediately, so a migration that fails halfway stays partly applied and has to be fixed by hand.
- **Development** (PHP's built-in server with `DB_IS_DEV=1` in `.env`): pending migrations are applied on every page load. **Reset database** on the settings page drops all `classroom_` tables, including the GitHub settings, and re-runs every migration. Use it after editing an already-applied migration. Without `DB_IS_DEV=1`, e.g. when developing against the production database, the dev server behaves like production here: no reset button, and migrations only run from the button.

## Local development

```
php -S 0.0.0.0:8000 -t . dev/router.php
```

The router stands in for SSO: requests run as `dev` (or `$DEV_USER`), and `?as=<user>` switches user. Under the built-in server the app uses `http://` URLs and a non-`Secure` session cookie.

Create `config/.env` (gitignored) for your dev database, with `ADMINS=dev`. For a MySQL container on the Docker host, use `DB_HOST=host.docker.internal`, and add `DB_IS_DEV=1` if the database is disposable (see Database migrations).

## Notes

- Students must accept the GitHub invitation (`<repo-url>/invitations`) before the repo is visible to them.
- Each student can be in one team per assignment. Changes made later on GitHub (removing members, renaming repos) are not tracked here.
- A student who re-links a different GitHub account keeps their existing memberships. Invitations already sent stay with the old account.
