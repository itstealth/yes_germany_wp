# Migrations

A migration is a small SQL file that makes a **deliberate, reviewed change to the
database** — the same way a code change is deliberate and reviewed.

You need them because most of this site's configuration does not live in files.
It lives in the database: 11 WPCode snippets, 526 header/footer entries, 284
Elementor templates, plus every plugin setting. A normal deploy copies **code
only**, so none of that travels with it.

Migrations are the supported way to move a database change from staging to live.

---

## The rules

1. **One file per change.** Numbered, in order.
2. **Each file runs exactly once per environment.** Applied files are recorded in
   `wpb9_yg_migrations`, so re-running a deploy is safe.
3. **Never edit a file after it has run anywhere.** Its checksum is recorded.
   Write a new migration to correct an old one.
4. **Always test on staging first.** That is what staging is for.
5. **Write it so it cannot run twice destructively** — see "Make it repeatable".

---

## Naming

```
migrations/0001-short-description.sql
migrations/0002-another-change.sql
```

Four digits, a dash, a short description in lower case. They run in filename
order, so the numbers decide the sequence.

---

## Writing one

### Example 1 — change a site setting

```sql
-- 0001-update-tagline.sql
-- Change the site tagline for the new campaign.

UPDATE wpb9_options
   SET option_value = 'Study in Germany with YES Germany'
 WHERE option_name = 'blogdescription';
```

### Example 2 — add a redirect

```sql
-- 0002-redirect-old-scholarships-page.sql
-- The old /scholarship/ URL still gets traffic; point it at the new page.

INSERT INTO wpb9_redirection_items (url, match_url, action_data, action_code, group_id, status, position)
SELECT '/scholarship/', '/scholarship/', '{"url":"/scholarships/"}', 301, 1, 'enabled', 0
 WHERE NOT EXISTS (
   SELECT 1 FROM wpb9_redirection_items WHERE url = '/scholarship/'
 );
```

Note the `WHERE NOT EXISTS` — that is what makes it safe to run twice.

### Example 3 — turn a plugin setting off everywhere

```sql
-- 0003-disable-popup-on-mobile.sql
UPDATE wpb9_options
   SET option_value = REPLACE(option_value, '"mobile_enabled":true', '"mobile_enabled":false')
 WHERE option_name = 'sgpb_settings';
```

---

## Make it repeatable

Write every migration so running it twice does no harm. This is the difference
between a migration and a one-off query.

| Instead of | Write |
|---|---|
| `INSERT INTO ...` | `INSERT ... WHERE NOT EXISTS (...)` |
| `DELETE FROM x` | `DELETE FROM x WHERE <specific condition>` |
| `ALTER TABLE ADD COLUMN` | Check `information_schema.columns` first |

---

## Serialised data — the one real trap

WordPress stores many settings as **serialised PHP**, which embeds string
lengths:

```
a:1:{s:5:"title";s:11:"YES Germany";}
                          ^^ length of the string
```

If you change `YES Germany` (11 characters) to `YES Germany India` (17) with a
plain `UPDATE`, the recorded length stays `11` and **WordPress silently fails to
read the value**. The setting appears to reset itself.

If the value starts with `a:` or `O:`, do not hand-edit it. Use WP-CLI instead,
which re-serialises correctly:

```bash
docker compose run --rm wpcli wp option update sgpb_settings --format=json < new.json --path=/var/www/html
```

For those cases, put the WP-CLI command in the pull request description and run
it as a documented step rather than forcing it into SQL.

---

## How to test one

Write the file, then run it against staging:

```bash
export DEPLOY_HOST=100.66.61.11 DEPLOY_USER=root
bash deployment/migrate.sh staging
```

Check the site, then confirm it was recorded:

```bash
docker compose run --rm wpcli wp db query \
  "SELECT filename, applied_at FROM wpb9_yg_migrations" --path=/var/www/html
```

Run it a second time — it should say `already applied` and change nothing. If it
does not, it is not repeatable yet.

---

## How it reaches live

Nothing special. Commit the file and deploy as normal:

1. `git push` → staging deploys and applies the migration
2. Verify on staging
3. Actions → **Deploy to production** → type `DEPLOY` → get it approved

`deploy.sh` runs `migrate.sh` automatically after the files are copied, so the
migration is applied to live as part of the same approved deploy — after the
database has already been backed up.

---

## When not to use a migration

For a one-off content edit — fixing a typo, swapping an image — just make the
change on the live site. Migrations are for changes that need to be **reviewed,
repeatable, or applied to several environments in the same way**.
