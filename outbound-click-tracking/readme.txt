=== Outbound Click Tracking ===
Contributors: aph5
Tags: outbound links, affiliate, click tracking, analytics, privacy
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Counts clicks on outbound and affiliate links without redirects, cookies, per-click rows or personal data. Works behind full-page caches.

== Description ==

Outbound Click Tracking records how often visitors click the outbound and affiliate links in your posts and pages, and shows the totals in wp-admin. It does this without redirecting or cloaking anything: your links keep their real destination URLs, which search engines and affiliate programme terms both prefer.

**What it stores**

* Hourly click counts per link. That is the whole data model: no per-click rows, no IP addresses, no user agents, no referrers, no user IDs, no cookies.
* Which post each link appeared in, the destination URL and its host, and the link text (used as an automatic label).

There is nothing to anonymise and nothing to disclose in a privacy policy beyond "aggregate click counts are kept".

**How it works**

1. When a post renders, eligible outbound links get a short server-generated token attribute. Links to your own site (and any hosts you list as internal) are skipped.
2. A small deferred script (about 700 bytes compressed) sends a beacon to a REST endpoint when a link is activated, including middle-click and keyboard activation.
3. The endpoint resolves the token and increments the hourly counter.

Because the token lives in the rendered HTML, the plugin keeps working behind WP Rocket, WP Super Cache, Cloudflare APO and similar full-page caches. Cached page views cost zero database queries; only the click itself reaches PHP.

**Reporting**

* Report screen with date presets or a custom range, host filter, search by post title or destination, summary cards and CSV export.
* Labels screen to give any destination URL a friendly name.
* Dashboard widget with the top links for today, yesterday, 7, 30 or 90 days.
* Status screen showing schema, cron and rate-limiter health.

**Operations**

* Bounded retention: hourly rows older than the configured window (default 24 months) are pruned daily.
* Layered abuse protection: the endpoint is POST-only and token-validated, an optional built-in per-IP limiter runs on your persistent object cache, and the documentation recommends a CDN or WAF rate-limiting rule.
* Uninstall keeps your data unless you opt in to deletion.
* Two filters let you grant report and label access to editors or authors.

**Compared with redirect-based link plugins**

Redirect or "cloaking" plugins route every click through your server and typically store one row per click. This plugin keeps links as real hrefs, records aggregates only, and adds no PHP work to cached page views.

== Installation ==

1. Upload the `outbound-click-tracking` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin. The tables and the daily retention task are created on activation.
3. Review **Click Tracking > Settings** (master switch, excluded roles, internal hosts, retention, rate limiting).
4. If you run a page cache, purge it once so existing posts are annotated on their next render.

= Upgrading from the pre-1.0 release =

Upload this plugin next to the old one, deactivate the old plugin first, then activate this one. Existing tables, settings and the widget preference are renamed in place automatically. Check **Click Tracking > Status** afterwards, purge your page cache, then delete the old plugin folder. Update any site code using the old filter names and any CDN or WAF rule on the old endpoint path.

== Frequently Asked Questions ==

= Does it work with caching plugins and CDNs? =

Yes, by design. The tracking token is part of the cached HTML and the click beacon goes to a REST endpoint that bypasses page caches. WP Rocket, WP Super Cache, W3 Total Cache, Cloudflare APO and similar all work without special configuration.

= Does it store IP addresses or any personal data? =

No. Only hourly aggregate counts per link are stored. The optional rate limiter keeps a short-lived per-IP counter in your object cache (a few seconds to a minute) and never writes it to the database.

= Is it GDPR friendly? =

There are no cookies, no personal data and no per-visitor records, so no consent banner is needed for this plugin. Always check with your own adviser for your specific situation.

= Does it change my links or redirect visitors? =

No. Links keep their original href. The plugin only adds a data attribute at render time.

= What counts as an outbound link? =

Any link in post or page content whose host is not your site (including its www and non-www forms) and not in your internal-hosts list. Links elsewhere in the theme (menus, widgets, footers) are not tracked.

= Which users are tracked? =

Visitors and logged-in users whose role is not in the excluded-roles list (administrators and editors by default).

= Can editors or authors see the report? =

Yes, by adding two filters in a small site plugin or your theme's functions.php:

`add_filter( 'octk_report_capability', fn() => 'edit_published_posts' );`
`add_filter( 'octk_label_capability', fn() => 'edit_published_posts' );`

= Does it support multisite? =

Not yet. Activation on a multisite network is refused.

= What happens on uninstall? =

Data is kept by default. Tick the option on the Settings screen if you want the tables and settings removed when the plugin is deleted.

== Screenshots ==

1. Report: date range, host filter, search, summary cards and the sortable link table.
2. Labels: manual names for destination URLs.
3. Settings: master switch, excluded roles, internal hosts, retention, rate limiting and uninstall behaviour.
4. Status: schema, cron and limiter health.
5. Dashboard widget with the top links for the selected period.

== Changelog ==

= 1.0.1 =
* Legacy data adoption now verifies every copied option and user preference before removing the source, checks that the old cron hook was cleared, and records the schema version only after the migrated tables pass the structure probe.
* Plugin URI points at the public GitHub repository.

= 1.0.0 =
* First WordPress.org release. Pre-1.0 installs are migrated automatically on first load.
* Breaking: all hooks, the REST route and the link attribute now use the `octk` prefix.
* All strings are translatable.
* Admin styles and scripts use the WordPress enqueue API.

= 0.6.0 =
* Dashboard widget lists the top 10 links.

= 0.5.x =
* De-branded for general use; CSV export filename includes the date range; documentation added.

= 0.3.x to 0.4.x =
* Report search rework, per-post link pinning, security hardening.

= 0.1.0 to 0.3.1 =
* Initial private releases: token annotation, beacon listener, REST endpoint, hourly aggregates, Report, Labels, Settings and Status screens, dashboard widget, retention, rate limiter, CSV export.

== Upgrade Notice ==

= 1.0.1 =
Hardening of the pre-1.0 data migration. No functional changes for fresh installs.

= 1.0.0 =
Renamed plugin. Deactivate the pre-1.0 plugin before activating this one; data migrates automatically. Site code using the old filter names and any rate-limit rule on the old endpoint path must be updated.
