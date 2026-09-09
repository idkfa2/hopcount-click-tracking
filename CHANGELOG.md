# Changelog

## 1.1.1 - 2026-09-09

- Every SQL statement, including schema migrations, legacy adoption (RENAME/DROP), status counts and the report queries, now goes through `wpdb::prepare()` with `%i` identifier placeholders for table names. The report sort is chosen by array lookup from four complete `ORDER BY` literals instead of substituting whitelisted fragments into the query. No behaviour change; addresses the WordPress.org plugin review (unsafe SQL calls, review R hopcount-click-tracking/aph5/31Aug26/T2 9Sep26).

## 1.1.0 - 2026-09-03

- Renamed to **Hopcount Click Tracking**: slug and text domain `hopcount-click-tracking`, main file `hopcount-click-tracking/hopcount-click-tracking.php`. The code prefix `octk_`, all hooks, table names, options, the REST route and the link attribute are unchanged, so existing data and site code carry over. Upgrading is a folder swap: deactivate the `outbound-click-tracking` folder, upload `hopcount-click-tracking`, activate.
- Source repository is now https://github.com/idkfa2/hopcount-click-tracking; `Plugin URI` updated.

## 1.0.1 - 2026-08-29

- Legacy adoption hardening: option and user-meta copies are verified before the legacy sources are deleted, the cron clear is checked, and `octk_schema_version` 2 is recorded only after the migrated tables pass the v2 probe (a malformed overrides table refuses adoption; an absent one lands at version 1 and migration 2 builds it).
- Source now lives in the public repository https://github.com/idkfa2/outbound-click-tracking; `Plugin URI` updated.
- Test suite runs on WordPress 7.1.

## 1.0.0 - 2026-08-29

First WordPress.org release.

- Renamed to **Outbound Click Tracking**: slug and text domain `outbound-click-tracking`, code prefix `octk_`. Main file is now `outbound-click-tracking/outbound-click-tracking.php`.
- Existing pre-1.0 installs are migrated automatically on first load: tables, options, cron, transients and the widget preference are renamed in place (no copy, no data loss). The migration refuses to run while the old plugin is still active and reports problems as an admin notice and on the Status page.
- **Breaking:** no aliases for the pre-1.0 names. Filters are now `octk_report_capability`, `octk_label_capability`, `octk_internal_hosts`, `octk_content_filter_priority`, `octk_click_allowed`, `octk_generic_anchor_texts`, `octk_public_token_hex`, `octk_migrations`; the REST route is `POST /wp-json/octk/v1/click`; the link attribute is `data-octk-token`. Update site code and any CDN/WAF rule that named the old path.
- All user-facing strings are translatable (text domain `outbound-click-tracking`).
- Admin CSS and the report-page script are now enqueued through the WordPress script/style API instead of inline echoes.
- Added `readme.txt`, `license.txt` and Plugin Check compliance annotations.
- Uninstall now also removes the per-user dashboard widget preference.

## 0.6.0 - 2026-08-29

- Dashboard widget now lists the top 10 links (was 5).

## 0.5.1 - 2026-08-29

- De-branded for general use: plugin name is now "Click Tracking", no site-specific strings, comments or defaults. Slug, text domain, option names, tables and REST route are unchanged, so existing installs upgrade in place.
- CSV export filename is now `clicks_<from>_<to>.csv`.
- Excluded-roles default centralised in a single default function.
- Added README, LICENSE and this changelog.

## 0.5.0 - 2026-08-14

- Report: per-post "See all links" row action pins the report to one source post (`source_id`), kept across filter submissions and CSV export.

## 0.4.0 - 2026-08-14

- Report: search rework. Source mode matches published post titles only; destination mode matches URL or resolved label. Word-based, any order, typographic dashes and quotes folded on both sides.
- Removed the "Last activity" summary card.

## 0.3.2 - 2026-08-12

- Security hardening: source search limited to published posts, Status page locked to `manage_options`, `noreferrer` on admin destination links, uninstall drops rate-limit options.

## 0.3.x and earlier - 2026-08-10 to 2026-08-11

- Initial release: render-time token annotation, deferred beacon listener, REST click endpoint, hourly aggregates, Report/Labels/Settings/Status screens, dashboard widget, retention cron, optional per-IP rate limiter, CSV export, label overrides.
