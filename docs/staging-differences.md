# Deliberate differences between staging and production

Staging aims to match production, because a staging environment that behaves
differently is not evidence of anything. These differences are intentional, and
each one is recorded here with its reason.

## Plugins disabled on staging

### `litespeed-cache` — disabled

LiteSpeed Cache requires the **LiteSpeed web server**. Production runs LiteSpeed;
staging runs nginx, so the plugin provides no caching here at all — while still
running its media-optimization pass on every request.

That pass calls `getimagesize()` on image URLs. Because those URLs are remote
rather than local paths, each call is a **blocking HTTP request**:

```
PHP Warning: getimagesize(https://yesgermany.in/version5/wp-content/uploads/2025/03/logo-3.png):
  HTTP request failed! HTTP/1.1 404 Not Found
  in wp-content/plugins/litespeed-cache/src/media.cls.php on line 1158
```

Measured: **34 such calls per page load**, all 404ing, all sequential.

| | TTFB |
|---|---|
| With `litespeed-cache` active | **88.4s** |
| Disabled | **1.16s** |

The `object-cache.php` dropin was removed for the same reason — it belongs to
LiteSpeed and does nothing without it.

## PHP configuration

`docker/php/opcache.ini` raises OPcache from the defaults (128 MB / 4,000 files)
to 512 MB / 30,000 files. The install has **13,523 PHP files**; at the defaults
only ~3,300 were cached and the hit rate sat near **50%**, meaning thousands of
files were recompiled per request. This was not the main cause of the slow page
load, but it is a real misconfiguration and the fix is kept.

## MySQL configuration

`innodb_buffer_pool_size` is raised to 2 GB (default 128 MB) for a ~560 MB
database. Like the OPcache change this did not fix the slow page load — queries
were never the bottleneck (498 queries, 0 slow queries per request) — but 128 MB
for a 560 MB dataset is wrong regardless.

## Worth raising with the client

The 34 broken image references point at **`yesgermany.in/version5/`** — a third
domain, separate from `.com` and `.co`. They are broken on production too; they
are simply invisible there because LiteSpeed serves most pages from cache
without invoking PHP. Any cache miss on production pays part of this cost.

Fixing the content to stop referencing `yesgermany.in/version5/` would speed up
uncached production requests and remove 34 failing outbound requests per render.
