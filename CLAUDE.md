# Hopcount Click Tracking (`hopcount-click-tracking`)

WordPress plugin that records clicks on outbound/affiliate links and reports them in wp-admin. Site-agnostic since v0.5.1; renamed to `outbound-click-tracking` / `octk_` in v1.0.0 for WordPress.org (account `aph5`), then to `hopcount-click-tracking` in v1.1.0 after the wp.org review flagged the generic name (prefix `octk_` deliberately kept). Public GitHub repo: `idkfa2/hopcount-click-tracking` (branch `main`, releases tagged `vX.Y.Z`).

Site-specific deployment material is not part of this repo; a gitignored `local/` folder and `CLAUDE.local.md` may hold it on a developer's machine.

## Layout

- `hopcount-click-tracking/` - **the plugin. Only this folder is ever deployed.**
- `tests/` - PHPUnit suite (runs inside wp-env tests container); run it for current counts
- `wp-org-assets/` - banner/icon/screenshots for the wp.org SVN `assets/` dir (not part of the plugin)
- `README.md` - user-facing docs (install, settings, filters); `CHANGELOG.md` - release notes

Code comments cite "decision N" and "scope §N": these refer to the original design spec, which is not in this repo. Treat them as historical rationale pointers only.

## Dev environment and tests

- **All wp-env/npm commands need Node 20:** `PATH="/opt/homebrew/opt/node@20/bin:$PATH" npx wp-env start` (Node 26 silently half-starts wp-env; `.nvmrc` pins 20). Dev site localhost:8888, tests localhost:8889.
- If Docker Desktop is not running: `open -a Docker` first.
- Test deps: `docker run --rm -v "$PWD/tests":/app -w /app composer:2 install` (Composer is not installed on the host).
- Test run: `PATH="/opt/homebrew/opt/node@20/bin:$PATH" npx wp-env run tests-cli --env-cwd=octk-tests vendor/bin/phpunit`
- Rule: local work only - never deploy or write to a live site without explicit user approval.

## How it works (one paragraph)

At render time a filter (priority 50, filterable) annotates eligible outbound links in singular post content with a server-generated `data-octk-token` (covers Classic, Gutenberg, custom HTML, and Shortcoder-emitted links; internal hosts skipped; fail-soft - rendering is never broken by tracking failures). A small deferred listener (~690 bytes gzipped, click/auxclick/keyboard) fires `sendBeacon` (fetch-keepalive fallback) to the public REST endpoint `POST /wp-json/octk/v1/click`, which resolves the token and atomically increments a bounded hourly aggregate - no per-click rows, no PII, no cookies. Survives full-page caching (WP Rocket, Cloudflare APO, etc.) by construction: tokens live in the cached HTML; cached hits do zero registration queries.

## Data model (schema v3)

- `{prefix}octk_links` - one row per (source, destination) identity: source_type/source_id, destination_url + host, token, auto-captured `label` (anchor text), destination_hash
- `{prefix}octk_clicks_hourly` - aggregate counts per link per UTC hour bucket
- `{prefix}octk_label_overrides` - manual labels per exact destination URL (win over auto labels)
- `{prefix}octk_redirects` + `{prefix}octk_redirect_sources` (v3, 1.2.0) - verdicts for cloaked same-site links (`/go/x`) found by signed `HEAD` probes against the site's own origin, and which posts reference them. Logic in `includes/resolve.php`; design notes in `local/archive/TEMP-cloaked-links-plan.md`. Kill switch `OCTK_DISABLE_RESOLVE`.

Retention: daily cron prunes hourly rows past the configured window (default 24 months, clamp 1-120). Uninstall preserves data by default (opt-in drop in Settings). Migrations run on plugin load; activation is idempotent (tables + retention cron).

## Admin UI and capability model

Click Tracking menu in wp-admin: **Report** (presets + custom range, host filter, search - default All = each word in published source title OR destination URL OR label, or narrowed to Source/Destination; typographic chars and common entities folded both sides in one ordered pass (`FOLD_MAP`); pasted URLs match (no `sanitize_text_field`), >2048 chars refused - group by link or page, stat cards, CSV export with formula-injection guard), **Labels** (manual destination-label overrides), **Settings** (admin-only: master switch, excluded roles, internal hosts, retention, rate limiter, uninstall opt-in), **Status** (admin-only: schema/cron/limiter health), plus a **Dashboard widget** (Today/Yesterday/7/30/90-day, per-user preference, 5-min caches).

- Report/Labels/widget default to `manage_options` but are filterable: `octk_report_capability`, `octk_label_capability` (a site plugin can map them to e.g. `edit_published_posts`). Settings and Status are hard-coded `manage_options`. These are filter hooks, not capabilities.
- Excluded roles (default administrator, editor; `octk_default_excluded_roles()`) are not tracked and don't get the listener.

## Abuse protection (layered)

1. Recommended: a CDN/WAF rate-limiting rule on `POST /wp-json/octk/v1/click`
2. Built-in per-IP fixed-window limiter on the persistent object cache (`octk_rate_limit=1`, 60/min default, fail-open when the cache is down; off by default)
3. Endpoint validates token syntax, master switch, POST-only; unknown tokens are cheap 4xx; no writes to link metadata from the public side

## Gotchas

- Auto labels are captured only on uncached renders - a full cache purge is the backfill trigger on already-cached pages.
- Uncached renders may probe never-seen same-site links (up to 3 probes / 3 s per render, `includes/resolve.php`); WP-Cron does the rest and all refreshes.
- Listener loads on a static front page (`is_singular()` is true) - intentional no-op, do not tighten the enqueue.
- The endpoint is intentionally public and POST-only; the master switch (Settings) 403s beacons instantly, including from cached pages.
- Multisite is refused at activation.
- **Legacy adoption** (`octk_adopt_legacy()` in `schema.php`): the only place `flhct` appears in shipped code. Runs when `flhct_schema_version` exists and `octk_schema_version` does not; renames tables/options/cron/meta in place; refuses (admin notice + Status row, transient `octk_adopt_error`) while the old plugin is active or when populated tables exist on both sides. `octk_schema_version` is written last. Tests: `tests/test-adoption.php` (base tables; DDL commits the test transaction, so tear_down cleans explicitly).
- Plugin Check: `npx wp-env run cli wp plugin check hopcount-click-tracking --categories=plugin_repo` (plugin-check is in `.wp-env.json`). Must be error-free before any wp.org submission.

## Release workflow

Minor bump per feature, tests green, manual check on the wp-env dev site, tag `vX.Y.Z`, GitHub release with the zip from `git archive --format=zip --prefix=hopcount-click-tracking/ vX.Y.Z:hopcount-click-tracking`. Commits authored as the repo's git identity, never with AI trailers.

## Writing style

Any user-facing strings shipped in the plugin, README, and CHANGELOG: no em dashes.
