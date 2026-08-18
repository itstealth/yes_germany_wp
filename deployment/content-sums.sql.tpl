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
-- Placeholders substituted by the caller: __PREFIX__ __STG_URL__ __PROD_URL__ __META_EXCL__
SET SESSION group_concat_max_len=1000000;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__posts');
SET @q := CONCAT('SELECT ''posts'', ID, MD5(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@'')) FROM __PREFIX__posts',
                 ' WHERE post_type<>''awsm_job_application''');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__postmeta');
SET @q := CONCAT('SELECT ''postmeta'', meta_id, MD5(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@'')) FROM __PREFIX__postmeta',
                 ' WHERE post_id NOT IN (SELECT ID FROM __PREFIX__posts WHERE post_type=''awsm_job_application'')',
                 ' AND meta_key NOT IN (__META_EXCL__)');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__terms');
SET @q := CONCAT('SELECT ''terms'', term_id, MD5(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@'')) FROM __PREFIX__terms');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__termmeta');
SET @q := CONCAT('SELECT ''termmeta'', meta_id, MD5(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@'')) FROM __PREFIX__termmeta');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__term_taxonomy');
SET @q := CONCAT('SELECT ''term_taxonomy'', term_taxonomy_id, MD5(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@'')) FROM __PREFIX__term_taxonomy');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @cols := (SELECT GROUP_CONCAT(CONCAT('IFNULL(`',column_name,'`,\'\')') ORDER BY ordinal_position SEPARATOR ',')
              FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='__PREFIX__term_relationships');
SET @q := CONCAT('SELECT ''term_relationships'', CONCAT(object_id,''-'',term_taxonomy_id), MD5(REPLACE(REPLACE(CONCAT_WS(''|'',', @cols,
                 '),''__STG_URL__'',''@''),''__PROD_URL__'',''@'')) FROM __PREFIX__term_relationships');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
