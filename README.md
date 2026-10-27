# Mini-Classroom

A minimal replacement for GitHub Classroom's repo provisioning. Students open an assignment's join link, connect their GitHub account, and either create a team or join an existing one. Creating a team generates a **private** repo in the classroom org from the assignment's template repo, and each team member gets a push-access invitation. After that, everything is managed on GitHub.

Requires PHP 8.1+ with `pdo_mysql`, `curl` and `openssl`, a MySQL/MariaDB database, and SSO that sets `$_SERVER['REMOTE_USER']`. It has no other dependencies.

## 1. Deploy

1. Copy this folder to the server, e.g. `~/public_html/classroom/`, inside the SSO-protected area.
2. Add your SSO username to `config/admins.php`.
3. Config location: if `~/classroom-config/` exists (one level above `public_html`), it is used instead of `config/`. Put `admins.php` there too. This keeps the DB password outside the web root. Otherwise `config/` is used, protected by `.htaccess`. Its PHP files output nothing even if `.htaccess` is ignored.
4. The config dir must be writable by the web server for the first save on the settings page. If it isn't, the settings page shows the file content so you can create `settings.php` by hand.

## 2. Create the GitHub App

In the classroom org: **Settings → Developer settings → GitHub Apps → New GitHub App**

- **Homepage URL**: `https://<host>/~<you>/classroom/`
- **Callback URL**: `https://<host>/~<you>/classroom/oauth.php` (also shown on the settings page)
- **Webhook**: uncheck *Active*
- **Repository permissions**: *Administration*: Read and write · *Contents*: Read-only · *Metadata*: Read-only
- **Where can this app be installed**: Only on this account

After creating it, note the **App ID** and **Client ID**, generate a **client secret**, and generate a **private key** (.pem). Then use **Install App** to install it on the org with *All repositories*.

## 3. Org settings

- **Member privileges → Base permissions: No permission.** Students are added as outside collaborators to their own repos only. Any org members (e.g. TAs) would otherwise see every repo.
- **Member privileges → Repository creation**: disable for members.
- Teachers should be org **owners**, which gives them access to all repos.
- If invitations fail with HTTP 403/422, check **Member privileges → Repository invitations**. Inviting outside collaborators must not be restricted in a way that blocks the app.

## 4. Configure the app

Open `https://<host>/~<you>/classroom/admin/settings.php`:

1. Enter the MySQL credentials. The tables are created automatically.
2. Enter the org login, App ID, Client ID, client secret and private key, then click **Test GitHub connection**.

## 5. Assignments

On the **Admin → New assignment** page:

- **Template**: an org repo marked as a template (repo *Settings → Template repository*), given as `owner/repo` or its URL.
- **Slug**: repo name prefix. Repos are named `<slug>-<github-login of team creator>`, with `-2`, `-3`, … appended if the name is taken.
- **Max team size**: use 1 for individual work.
- **Open**: untick to stop new teams and joins.

Share the join link it shows. The assignment page lists teams, repos and members, and has a **Retry failed invitations** button. A typical cause of failed invitations is GitHub not having the repo ready yet.

## Notes

- Students must accept the GitHub invitation (`<repo-url>/invitations`) before the repo is visible to them.
- Each student can be in one team per assignment. Changes made later on GitHub (removing members, renaming repos) are not tracked here.
- A student who re-links a different GitHub account keeps their existing memberships. Invitations already sent stay with the old account.
