# Outbound Click Tracking

A lightweight, privacy-friendly WordPress plugin that counts clicks on outbound and affiliate links and reports them in wp-admin. No cookies, no per-click rows, no personal data, and it keeps working behind full-page caches such as WP Rocket or Cloudflare APO.

Plugin slug: `outbound-click-tracking`. All internals (functions, options, tables, REST route, filters) use the `octk_` prefix.

## How it works

1. When a single post or page renders, a content filter (priority 50, filterable) adds a server-generated `data-octk-token` to every eligible outbound link. Links to your own site (and any hosts you list as internal) are skipped. Classic editor, Gutenberg, custom HTML and shortcode-emitted links are all covered. Failures never break rendering.
2. A small deferred listener (about 690 bytes gzipped) watches for click, auxclick and keyboard activation and sends a beacon (`navigator.sendBeacon`, with a fetch keepalive fallback) to `POST /wp-json/octk/v1/click`.
3. The endpoint resolves the token and atomically increments an hourly aggregate counter. Unknown or malformed tokens are rejected cheaply and nothing on the public side can write link metadata.

Because tokens live in the rendered HTML, cached pages keep tracking without touching the database.

## Requirements

- WordPress 6.5 or newer, single site (multisite is refused at activation)
- PHP 8.0 or newer
- Optional: a persistent object cache (e.g. Redis) if you enable the built-in rate limiter

## Installation

1. Copy the `outbound-click-tracking/` folder into `wp-content/plugins/`.
2. Activate **Outbound Click Tracking** in wp-admin. Tables and the daily retention cron are created on activation; schema migrations run automatically on load.
3. Visit **Click Tracking > Settings** to review defaults.

Links are registered the first time a post renders uncached, so if you run a page cache, purge it once after activation to pick up existing content.

## Upgrading from the pre-1.0 release

Version 1.0.0 renamed the plugin (slug, prefix, hooks and REST route) to `outbound-click-tracking` / `octk_`. Existing data is adopted automatically on the first load: tables, options, the retention cron, transients and the dashboard widget preference are renamed in place. Nothing is copied and nothing is deleted.

1. Back up the database.
2. Upload the new `outbound-click-tracking/` folder next to the old one.
3. Deactivate **Click Tracking** (the old plugin). The migration refuses to run while it is active.
4. Activate **Outbound Click Tracking**. Check **Click Tracking > Status**: "Legacy data migration" should read "not needed or complete".
5. Purge any page cache, then delete the old plugin folder (its uninstall routine keeps data by default).
6. Update anything that referenced the old names: filters in site code, and any CDN or WAF rule on the old endpoint path (now `/wp-json/octk/v1/click`).

If the migration finds a problem it shows an admin notice with the reason and retries on every page load; tracking and reports stay paused until it completes.

## Admin screens

- **Report**: date presets or a custom range, host filter, search by source title (published posts only) or by destination URL/label, summary stat cards, sortable table, per-post "See all links" pin, CSV export (with spreadsheet formula-injection guard).
- **Labels**: manual labels per destination URL, overriding the auto-captured anchor text.
- **Settings** (administrators): master switch, excluded roles, internal hosts, retention window, rate limiter, uninstall behaviour.
- **Status** (administrators): schema version, cron and limiter health, environment.
- **Dashboard widget**: top links for Today / Yesterday / 7 / 30 / 90 days, per-user period preference, 5-minute cache.

## Settings

| Option | Default | Notes |
|---|---|---|
| Outbound click tracking | on | Master switch. When off, no links are annotated, the listener is not loaded and the endpoint returns 403 (including beacons from cached pages). |
| Excluded roles | administrator, editor | Logged-in users with these roles are never tracked and do not receive the listener. |
| Internal hosts | (empty) | Extra hostnames treated as internal, one per line. Your own site host and its www/non-www alias are always internal. |
| Keep hourly counts for | 24 months | 1 to 120. A daily cron prunes older aggregate rows. |
| Rate limiting | off | Per-IP fixed window on the persistent object cache; 60 clicks/minute by default. Fails open when the cache is unavailable. |
| On uninstall | keep data | Opt in to drop the tables and options when the plugin is deleted. |

## Data model

Three tables under your database prefix:

- `octk_links`: one row per (source post, destination URL), with token, destination host, auto-captured label and a destination hash.
- `octk_clicks_hourly`: click counts per link per UTC hour.
- `octk_label_overrides`: manual labels per exact destination URL.

No IP addresses, user agents, referrers or user IDs are stored.

## Abuse protection

The endpoint is public by design (it has to work from cached pages). Layers:

1. Recommended: a rate-limiting rule at your CDN or WAF on `POST /wp-json/octk/v1/click`.
2. Optional built-in per-IP limiter (Settings), fail-open.
3. Token syntax validation, POST-only, master switch, cheap 4xx for unknown tokens, bounded counters.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `octk_report_capability` | `manage_options` | Who can view the Report, dashboard widget and CSV export. |
| `octk_label_capability` | `manage_options` | Who can manage Labels. |
| `octk_internal_hosts` | option value | Programmatically add internal hosts. |
| `octk_content_filter_priority` | `50` | Priority of the `the_content` annotation filter. |
| `octk_click_allowed` | `true` | Veto a click from the endpoint (custom limiters). |
| `octk_generic_anchor_texts` | built-in list | Anchor texts ("click here", "buy now", ...) not used as auto labels. |
| `octk_public_token_hex` | generated | Override token generation. |
| `octk_migrations` | built-in | Extend the schema migration list. |

Example: let editors and authors see reports and manage labels.

```php
add_filter( 'octk_report_capability', fn() => 'edit_published_posts' );
add_filter( 'octk_label_capability', fn() => 'edit_published_posts' );
```

## Development

Requires Docker and Node 20 (`.nvmrc`).

```sh
npm install
npx wp-env start                       # dev site on localhost:8888, tests on 8889
docker run --rm -v "$PWD/tests":/app -w /app composer:2 install
npx wp-env run tests-cli --env-cwd=octk-tests vendor/bin/phpunit
```

## Releasing

Build the distributable zip from the repo root (`wp package install wp-cli/dist-archive-command` once):

```sh
wp dist-archive outbound-click-tracking build/outbound-click-tracking.zip --create-target-dir --plugin-dirname=outbound-click-tracking
```

or without extra WP-CLI packages: `mkdir -p build && git archive --format=zip --prefix=outbound-click-tracking/ --output=build/outbound-click-tracking.zip HEAD:outbound-click-tracking`. The zip must contain a single `outbound-click-tracking/` root with `outbound-click-tracking.php` and `readme.txt` directly inside it. `wp-org-assets/` holds the WordPress.org banner, icon and screenshots (SVN `/assets`, not part of the plugin).

## License

GPL-2.0-or-later. See `LICENSE`.
