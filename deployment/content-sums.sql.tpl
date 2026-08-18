-- Emits one row per content row: table, key, hash of the whole row.
--
-- Read by content-delta.sh on staging to work out what production is missing.
-- Both sides run this same template, so the hashed input is identical except
-- where the content genuinely differs; the two hostnames collapse to one token
-- first, because the sides are meant to differ by domain.
--
-- The column list comes from information_schema ordered by position rather than
-- being hardcoded, so adding a WordPress column does not silently drop it from
-- the comparison.
--
-- Derived and production-owned postmeta is excluded (__META_EXCL__):
--   _elementor_css, _elementor_element_cache, _elementor_page_assets
--       generated caches. Production regenerates them after every import, so
--       including them makes a no-op publish look like 70 changed rows.
--   ekit_post_views_count
--       live visitor counters. This is production's own data; the full-table
--       push has been overwriting it with staging's counts on every publish.
--   _edit_lock, _edit_last
--       who has the post open in wp-admin. Meaningless off its own site.
--
-- Three hosts collapse to the same token, not two: production stores some URLs
-- without the www (og_image_url is one), so normalising only the www form left
-- those rows looking different forever.
--
-- Placeholders substituted by the caller: __PREFIX__ __STG_URL__ __PROD_URL__ __PROD_URL2__ __META_EXCL__
SET SESSION group_concat_max_len=1000000;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__posts');
SET @q := CONCAT('SELECT ''posts'', ID, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__posts',
                 ' WHERE post_type<>''awsm_job_application''');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__postmeta');
SET @q := CONCAT('SELECT ''postmeta'', meta_id, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__postmeta',
                 ' WHERE post_id NOT IN (SELECT ID FROM __PREFIX__posts WHERE post_type=''awsm_job_application'')',
                 ' AND meta_key NOT IN (__META_EXCL__)');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__terms');
SET @q := CONCAT('SELECT ''terms'', term_id, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__terms');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__termmeta');
SET @q := CONCAT('SELECT ''termmeta'', meta_id, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__termmeta');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__term_taxonomy');
SET @q := CONCAT('SELECT ''term_taxonomy'', term_taxonomy_id, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__term_taxonomy');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__term_relationships');
SET @q := CONCAT('SELECT ''term_relationships'', CONCAT(object_id,''-'',term_taxonomy_id), MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__term_relationships');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- SEO metadata: page titles, meta descriptions, canonicals, per-page noindex.
--
-- Keyed on post_id / term_id, NOT on the table's own auto_increment id, and
-- `id` is excluded from the hash. The two sides' id sequences have already
-- drifted: id 8200 is post 139170 on production and post 139169 on staging,
-- because each side independently creates a row the first time it sees a post.
-- Keying on id would have written one page's SEO onto a different page.
--
-- Revisions are excluded too. AIOSEO creates a row the first time it sees any
-- post, revisions included — 6,121 of the rows on production hang off revisions
-- — and SEO metadata for a revision means nothing. Each side creates them at
-- different moments, so they drift apart forever.
--
-- The row set mirrors the posts rule exactly: only pages that exist on this
-- side, are not job applications, and are not auto-drafts or trash. Excluding
-- job applications by post_id alone was asymmetric — production has the post so
-- it matched there, staging does not have the post so it did not, and the row
-- showed up as a difference on every single publish. Same for auto-drafts.
--
-- aioseo_terms has no keyphrases column, so the focus-keyphrase expression
-- applies to aioseo_posts only.
--
-- Excluded from the hash: id, updated, seo_score, page_analysis, keyphrases,
-- truseo, and anything matching %_scan_date (video_scan_date,
-- seo_analyzer_scan_date, ...) — AIOSEO stamps those when it scans, per site,
-- images, videos — every one of them computed by AIOSEO for its own site, not
-- authored by anybody. 47 pages differed on updated/seo_score/keyphrases alone
-- while every SEO field on them was identical. The focus keyphrase, which IS
-- typed by a person, is pulled back out of the keyphrases blob and hashed on its
-- own, normalised so an empty array and an empty string compare equal.
--
-- `updated` is excluded from the hash as well. AIOSEO stamps it during its own
-- background scans, so 47 pages differed between the sides on that column alone
-- while every SEO field on them was identical — a diff that would churn on every
-- publish and carry nothing.
--
-- post_id is not unique either — AIOSEO leaves the odd stray empty duplicate
-- (2 of them here). Those post_ids are skipped rather than guessed at.
-- ---------------------------------------------------------------------------
SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__aioseo_posts' AND column_name NOT IN ('id','updated','seo_score','page_analysis','keyphrases','images','videos','truseo') AND column_name NOT LIKE '%\\_scan\\_date');
SET @q := CONCAT('SELECT ''aioseo_posts'', post_id, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 ',IFNULL(JSON_UNQUOTE(JSON_EXTRACT(keyphrases,''$.focus.keyphrase'')),'''')',
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__aioseo_posts',
                 ' WHERE post_id NOT IN (SELECT post_id FROM (SELECT post_id FROM __PREFIX__aioseo_posts',
                 ' GROUP BY post_id HAVING COUNT(*)>1) d)',
                 ' AND post_id IN (SELECT ID FROM __PREFIX__posts WHERE post_type NOT IN (''awsm_job_application'',''revision'')',
                 ' AND post_status NOT IN (''auto-draft'',''trash''))');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__aioseo_terms' AND column_name NOT IN ('id','updated','seo_score','page_analysis','keyphrases','images','videos','truseo') AND column_name NOT LIKE '%\\_scan\\_date');
SET @q := CONCAT('SELECT ''aioseo_terms'', term_id, MD5(REPLACE(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@''),''__PROD_URL2__'',''@'')) FROM __PREFIX__aioseo_terms',
                 ' WHERE term_id NOT IN (SELECT term_id FROM (SELECT term_id FROM __PREFIX__aioseo_terms',
                 ' GROUP BY term_id HAVING COUNT(*)>1) d)');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
