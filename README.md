# YES Germany — WordPress CI/CD

Code, staging environment and deployment pipeline for **www.yesgermany.com**.

---

## What is in this repository

Only code that we own and can safely redeploy:

```
wp-content/themes/zakra-child/    Child theme — all site customizations
wp-content/mu-plugins/            Environment guard (must-use)
wp-content/plugins/yesgermany-schema-engine/
docker/                           Staging stack config
deployment/                       Deploy, backup, rollback, migrate, health check
migrations/                       Reviewed SQL changes
.github/workflows/                CI and deploy pipelines
```

**Deliberately not in this repository:** WordPress core, third-party plugins,
`wp-config.php`, uploads, database dumps, and SSH keys. Core and plugins come
from the runtime image and the server; secrets come from GitHub Secrets and the
server's `.env`.

---

## Architecture

| | Production | Staging |
|---|---|---|
| Host | Client cPanel (OVH, `148.113.25.152`) | Your OVH Docker host |
| URL | `https://www.yesgermany.com` | `https://yg-staging.stealthlearn.in` |
| Stack | LiteSpeed + PHP 8.2 | nginx + php-fpm 8.2 + MySQL 8 |
| Database | `yesgermanycom_fdkf` (`wpb9_`) | `yg_staging` (`wpb9_`) |
| Uploads | 3.5 GB on disk | Proxied read-only from production |
| Deploys | Manual approval required | Automatic on push to `main` |

PHP is pinned to **8.2 on both sides**. Do not raise it: the site runs
**Elementor Pro 3.1.1**, which predates PHP 8.3 and will fatal.

---

## How a change reaches production

```
 push to main ──▶ CI ──▶ staging (automatic) ──▶ review
                                                    │
                                                    ▼
                        production ◀── manual approval ◀── "Deploy to production"
```

1. **CI** — PHP 8.2 lint on every file, `composer validate`, a check that no
   secret or SQL dump was committed, a check for world-writable files, and a
   check that the analytics and CRM modules still carry their production guard.
2. **Staging** — deploys automatically once CI passes, then health-checks itself
   and rolls back if the check fails.
3. **Production** — `workflow_dispatch` only. You must type `DEPLOY`, and the
   `production` GitHub Environment requires a reviewer's approval.

### Deploying to production

Actions → **Deploy to production** → Run workflow → type `DEPLOY` → approve.

The workflow backs up the database and current release first, deploys, health
checks, and rolls back automatically on failure.

---

## The two rules that keep this safe

**1. Code flows staging → production. Data never does.**

The production database holds live leads, form submissions and user accounts
that exist nowhere else. Overwriting it from staging would destroy them. No
script in this repository pushes a database to production, and none should be
added.

Deliberate database changes go through `migrations/`.

**2. Rollback restores code, never content.**

`rollback.sh` puts the previous release's files back and leaves the database
untouched. Rolling the database back would discard every lead captured since
the deploy.

---

## Migrations

Most of this site's configuration lives in the database rather than in files —
52 WPCode snippets, 526 header/footer entries, 284 Elementor templates. A
code-only deploy cannot carry those, so intentional database changes are written
as numbered SQL files:

```
migrations/0001-add-consent-field.sql
```

They are applied in filename order, tracked in `wpb9_yg_migrations`, and each
runs exactly once per environment.

---

## Required GitHub Secrets

Set per environment (Settings → Environments):

| Secret | Description |
|---|---|
| `DEPLOY_HOST` | Target hostname or IP |
| `DEPLOY_USER` | Deploy user (never the primary cPanel account) |
| `DEPLOY_SSH_KEY` | Private key, deploy-only |
| `DEPLOY_KNOWN_HOSTS` | Pinned host key — prevents MITM; do not omit |
| `REMOTE_ROOT` | WordPress document root |
| `REMOTE_BACKUP_DIR` | Backup path **outside** the web root |

The `production` environment should have **required reviewers** configured. That
approval gate is the actual safety mechanism; the `DEPLOY` confirmation string
only prevents accidental clicks.

> **Not currently active.** Required reviewers on a *private* repository need
> GitHub Pro, Team or Enterprise. On the Free plan the API rejects the rule:
>
> ```
> 422 Please ensure the billing plan supports the required reviewers protection rule.
> ```
>
> Until the plan is upgraded, production is protected only by being
> `workflow_dispatch`-only, requiring the `DEPLOY` confirmation string, and by
> who has permission to run workflows. There is no second-pair-of-eyes check.
>
> The workflow already declares `environment: production`, so the gate starts
> working the moment the plan supports it — no code change required.

---

## Staging behaviour

The must-use plugin `00-yg-environment.php` makes staging inert:

- **Outbound email is blocked** at `pre_wp_mail` — staging carries a copy of the
  production users table, so without this a cron job could email real students.
- **`noindex`** via both `blog_public` and an `X-Robots-Tag` header.
- **Analytics and CRM forwarding are disabled** — otherwise staging traffic
  pollutes GA4, Google Ads conversions and the live lead database.
- An **environment banner** in wp-admin, so nobody edits staging thinking it is
  live.

Uploads are read through to production and cached locally. This is a plain HTTP
`GET` for a static file; there is no path by which staging can write to the
client's server. Media uploaded on staging stays local.

---

## Local development

```bash
cp .env.example .env        # then edit
docker compose up -d
docker compose exec php wp --info --path=/var/www/html
```

---

## Known technical debt

Carried over from the existing site, documented rather than silently fixed:

- **`wp-content/plugins1/`** — 133 MB of abandoned 2023–24 plugin copies on
  production, publicly readable over HTTP (verified `HTTP 200`). Unpatched code
  reachable by URL. Should be deleted.
- **Shared database** — `yesgermanycom_fdkf` holds 642 tables across 12 sites
  plus a student-records application. Only 178 belong to this site. Any one of
  those sites being breached exposes all of them.
- **Elementor Pro 3.1.1** — roughly five years old, with known vulnerabilities.
  Test the upgrade on staging first.
- **Plugin redundancy** — 3 header/footer injectors, 3 table plugins, 3 popup
  plugins, 2 custom-CSS plugins all active simultaneously.
- **`send_mailer.php`** — custom form handler with no nonce, captcha or rate
  limit, and it writes `enquiry_log.csv` into the web root when it runs.
