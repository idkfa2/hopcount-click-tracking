# Changelog

## 1.3.0 - 2026-09-28

- Report search has a new default mode, **All**: every search word must appear in the source post title (published only), the destination URL or the resolved label, and different words may match in different places ("joe rogan hone" = Hone links on Joe Rogan posts). It joins published titles in the report query, so it has no 100-post cap. **Source content** and **Destination** keep their previous behaviour; `search_in` now defaults to `all`.
- The search value is no longer passed through `sanitize_text_field()`, which stripped `%XX` octets and made pasted affiliate URLs unmatchable. Searches over 2048 characters (the `destination_url` column size) are refused with a notice, and the CSV export answers them with HTTP 400 instead of exporting unfiltered rows.
- Search folding now also covers `&amp;`, `&#038;`, `&#039;`, `&#8211;`, `&#8212;`, `&#8216;`, `&#8217;` and `&quot;`, applied in the same order to the search text and to titles and labels (`&amp;` last). Other entities stay literal on both sides.
- Empty Source content or Destination searches show how many clicks the same search matches in All, with a link.
- **Group by: Page** rolls the table up to one row per source post (matching links, clicks, last activity), with sorting, pagination and CSV export (`clicks-by-page_<from>_<to>.csv`, columns `source_post_id, source_title, source_url, links, clicks, last_activity_utc, last_activity_site_tz`). Link-mode CSV columns are unchanged. "See all links" from a page row keeps the search.
- Destination URLs are displayed percent-decoded and middle-truncated, wrapping only at the omitted middle; links, tooltips and CSV keep the stored URL. The host filter is capped at 220px so the filter bar fits on one row.

## 1.2.0 - 2026-09-14

- Cloaked same-site links (`/go/product`, `/recommends/product`, bare `/product-name` created by Redirection, Pretty Links, ThirstyAffiliates or a server rule) are now tracked under their real destination. The plugin asks its own server where each same-site link goes with a signed `HEAD` request that never renders a page, and stores the answer in two new tables, `octk_redirects` and `octk_redirect_sources` (schema version 3). Discovery happens at render, on save and by an upgrade backfill; answers are refreshed weekly by WP-Cron with backoff for errors. A changed destination purges the referencing posts (post cache, WP Rocket) and fires `octk_resolution_changed`.
- Probes reach the origin directly when a signed self-test proves a route (explicit filter, `SERVER_ADDR`, loopback), so no CDN or page cache ever sees one; otherwise the public URL is used and the origin renders the page with the resolver off and `DONOTCACHEPAGE` set. Unsigned or invalid probe headers are ignored entirely.
- Ceilings: 3 probes and 3 s per uncached render (never-seen links only), 20 probes or 20 s per cron run, a 10,000-request daily cap, a one-hour circuit breaker after any transport error, a 3-hop limit. Kill switch: `OCTK_DISABLE_RESOLVE` or filter `octk_resolve_enabled`.
- Status screen: resolution state, counts by verdict, probes today, probe route and cron rows, plus a "Clear resolution cache" button (bumps the generation and rotates the signing secret; verdicts keep serving until re-confirmed).
- New filters `octk_resolve_enabled`, `octk_pre_resolve_same_site`, `octk_resolve_ttl`, `octk_resolve_daily_cap`, `octk_resolve_probe_origin`, `octk_resolve_probe_sslverify`; new action `octk_resolution_changed`; new public REST route `GET /wp-json/octk/v1/probe-selftest` (404 unless signed).
- Frontend output changes on pages that contain cloaked links: purge the page cache after updating.

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
