# mh.yesgermany.com: stop sending leads to the CRM (2026-10-08)

Client decision: mh leads go by email only, not to the CRM.

The CRM push for mh leads happens in the visitor's browser, from the mh-only
plugin `wp-content/plugins/yg-mh-enquiry/` on the mh install (not in this repo).
`form.js` posts to whatever URL is in the form wrapper's `data-crm` and skips the
push when it is empty. v1.3.0 renders `data-crm=""` through the new filter
`yg_mh_enquiry_crm_url` (default `''`).

`yg-mh-enquiry.php` here is the live file after the change. Applied to live
2026-10-08 07:54 UTC; backup of v1.2.0 in
`~/_yg_backups/20261008-075424-mh-enquiry-crm-off/` on production.

Unchanged: main site (mu-plugin 45 CRM push for www leads, mu-plugin 40 bridge),
mail routing (mu-plugin 53: mh Google Ads leads emailed to YG_MH_MAIL_RECIPIENTS,
mh Meta leads not emailed), LMS push (mu-plugin 48) and the lead list.

Undo: copy the backup back, or `add_filter( 'yg_mh_enquiry_crm_url', fn( $u, $p ) => $p['crm_url'] ?? '', 10, 2 );`
