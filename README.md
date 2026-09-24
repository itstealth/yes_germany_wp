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
| Host | Client cPanel (Cloudexter, `d601.bom.secure-web.cloud`) | Your OVH Docker host |
| URL | `https://www.yesgermany.com` | `https://yg-staging.stealthlearn.in` |
| Stack | LiteSpeed + PHP 8.2 | nginx + php-fpm 8.2 + MySQL 8 |
| Database | `yesgermanycom_fdkf` (`wpb9_`) | `yg_staging` (`wpb9_`) |
| Uploads | 3.5 GB on disk | Own copy, 64,549 files |
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
   check that the analytics and CRM modules still carry their production guard,
   and a check that no site-wide form `submit` listener has come back — the CRM
   is fed only by the final validated submission.
2. **Staging** — deploys automatically once CI passes, then health-checks itself
   and rolls back if the check fails.
3. **Production** — `workflow_dispatch` only. You must type `DEPLOY`, and the
   `production` GitHub Environment requires a reviewer's approval.

### Deploying to production

Actions → **Deploy to production** → Run workflow → type `DEPLOY` → approve.

The workflow backs up the database and current release first, deploys, health
checks, and rolls back automatically on failure.

---

## The rules that keep this safe

**1. Staging is the only place anyone edits content.**

Content pushes REPLACE production's content tables. If someone writes a post
directly on production, the next push deletes it. `push-content.sh` compares the
newest post on each side and refuses to run when production is ahead, but that
guard is a safety net — the rule is the real protection.

**2. Content flows staging → production. Certain data never does.**

Pushed: posts, pages, Elementor designs, menus, categories, media, plugin
settings and plugin activation.

Never pushed, because staging cannot have them: users (staging's are scrambled),
comments and job applications (submitted by real visitors to the live site), and
a short blocklist inside the options table — `siteurl`/`home`, API keys, OAuth
tokens and licences, which must differ between environments.

Job applications live inside the `posts` table that gets replaced, so they are
copied to holding tables first, restored afterwards, and the count is verified
before those tables are dropped.

**3. Rollback restores code, never content.**

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
| `DEPLOY_USER` | Deploy account — `ygdeploy` on staging, never root |
| `DEPLOY_PORT` | `2222` on staging (see below), `22` on production |
| `DEPLOY_SSH_KEY` | Private key, deploy-only |
| `DEPLOY_KNOWN_HOSTS` | Pinned host key — prevents MITM; do not omit |
| `REMOTE_ROOT` | WordPress document root |
| `REMOTE_BACKUP_DIR` | Backup path **outside** the web root |
| `HEALTH_AUTH` | `user:pass` for staging's basic auth |
| `TS_AUTHKEY` | Tailscale auth key (repo-level — both workflows need it) |

### Why staging uses port 2222 and a dedicated account

The OVH host is reachable only over Tailscale, and **Tailscale SSH intercepts
tailnet port 22**, ignoring SSH keys in favour of node-IP rules in the tailnet
policy. GitHub runners are ephemeral and get a new tailnet IP every run, so they
can never match such a rule — and one rule uses the `check` action, which waits
for interactive browser approval and makes a headless client hang indefinitely.

CI therefore connects on **port 2222**, which tailscaled does not intercept, as
**`ygdeploy`** — an account that owns `/opt/yesgermany` and is in the `docker`
group, and nothing else. A `Match User ygdeploy` block permits publickey only;
two-factor authentication remains required for every other account and on port
22. `ufw` exposes 2222 on the `tailscale0` interface alone, so it is not
internet-facing.

The tailnet range is whitelisted in CrowdSec and fail2ban, so a runner cannot be
banned mid-deploy — CrowdSec bans via an nftables set that `iptables` does not
show and `fail2ban-client` cannot clear, which is painful to diagnose.

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

Staging holds its own copy of the media library (64,549 files), so images
uploaded there travel to production with the next content push.
`deployment/media-sync.sh` compares the dated media folders on both servers and
sends only the files production lacks, before the content that uses them. It
only adds files: a push can never overwrite or delete existing production media.
Zips, `.php` and other non-media files in `uploads/` never travel.

Staging keeps upload URLs on its own domain. Its nginx serves a file locally when
it has it and reads through to production when it does not, and the push rewrites
staging URLs to production ones.

`harden-staging.sh` also disables nine plugins that would otherwise reach the
client's live third-party accounts — Site Kit, MonsterInsights, Microsoft UET,
AIOSEO IndexNow, OptinMonster, Broken Link Checker, LiteSpeed Cache and both
header-injection plugins. Those must stay ACTIVE in production, so
`push-content.sh` re-enables them there after every push.

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
