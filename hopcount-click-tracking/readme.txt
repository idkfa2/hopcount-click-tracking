=== Hopcount Click Tracking ===
Contributors: aph5
Tags: outbound links, affiliate, click tracking, analytics, privacy
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.3.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Counts clicks on outbound and affiliate links without redirects, cookies, per-click rows or personal data. Works behind full-page caches.

== Description ==

Hopcount Click Tracking records how often visitors click the outbound and affiliate links in your posts and pages, and shows the totals in wp-admin. It does this without redirecting or cloaking anything: your links keep their real href. If you already use cloaked links on your own site (Redirection, Pretty Links, ThirstyAffiliates, a server rule), those are followed and counted under their real destination too, best-effort.

**What it stores**

* Hourly click counts per link. That is the whole data model: no per-click rows, no IP addresses, no user agents, no referrers, no user IDs, no cookies.
* Which post each link appeared in, the destination URL and its host, and the link text (used as an automatic label).

There is nothing to anonymise and nothing to disclose in a privacy policy beyond "aggregate click counts are kept".

**How it works**

1. When a post renders, eligible outbound links get a short server-generated token attribute. Ordinary links to your own site (and any hosts you list as internal) are skipped. A same-site link that your site answers with an off-site redirect (a cloaked affiliate link) is treated as outbound and tracked under the redirect's destination.
2. A small deferred script (about 700 bytes compressed) sends a beacon to a REST endpoint when a link is activated, including middle-click and keyboard activation.
3. The endpoint resolves the token and increments the hourly counter.

Because the token lives in the rendered HTML, the plugin keeps working behind WP Rocket, WP Super Cache, Cloudflare APO and similar full-page caches. Cached page views cost zero database queries; only the click itself reaches PHP.

**Reporting**

* Report screen with date presets or a custom range, host filter, one search box across post titles, destination URLs and labels, per-link or per-page totals, summary cards and CSV export.
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

1. Upload the `hopcount-click-tracking` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
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

= I use cloaked links like /go/product or /recommends/product. Are they counted? =

Yes, since 1.2.0. The plugin sends a small signed request for each same-site link to your own server, with redirects disabled, and reads the answer. If the answer is a redirect to another host, the link is tracked under that destination, exactly like a link written out in full, so the host filter, labels and grouping all work. If the answer is a normal page, a 404 or an on-site redirect, the link stays untracked. Redirects made by Redirection, Pretty Links, ThirstyAffiliates, your web server or your host all look the same to this check. Answers are kept in a small table and re-checked about once a week by WP-Cron. It is best-effort: redirects that depend on cookies, geography or the request method are not seen, and redirect rules applied at a CDN in front of your origin are not visible to it.

= Will the redirect check slow my site down or overload the server? =

No. Work is bounded by content, not by traffic: one row per distinct same-site link, checked about once a week. An uncached page view checks at most three never-seen links with a three-second budget; everything else is done by cron in small batches. Any transport error pauses all checks for an hour, there is a daily cap of 10,000 requests, and the Status screen shows the state. Fully cached page views never touch it. To turn the feature off entirely, define the constant `OCTK_DISABLE_RESOLVE` as true or return false from the `octk_resolve_enabled` filter; behaviour then matches 1.1.x.

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

= 1.3.1 =
* The Report's host filter is now a text box with suggestions (the most-clicked hosts in the selected range). The old drop-down listed only the first 200 hosts alphabetically, so busy hosts late in the alphabet could not be chosen, and a host outside that list was lost when the form was submitted again. Any host can be typed, and a pasted URL is reduced to its host.
* CSV export: source titles are plain text. "&" no longer appears as "&#038;", and curly quotes are real characters. The spreadsheet formula guard still applies.
* Cloaked-link checks: old daily request counters are now cleaned up, not just yesterday's; a redirect that sends the Location header twice now uses the first value instead of the text "Array"; ordinary page views no longer run two database checks looking for a probe signature they do not carry.

= 1.3.0 =
* Report search now looks everywhere by default: the new "All" mode matches each word against the source post title, the destination URL or the link label, so "hone" finds every Hone Health link and "joe rogan hone" finds the Hone links on Joe Rogan posts. "Source content" and "Destination" remain as narrower options.
* A full affiliate URL pasted into the search box now matches. Previously the percent-encoded parts (%3A, %2F) were stripped before searching. Searches longer than 2048 characters are refused rather than shortened.
* "&amp;" and common numeric entities in post titles and labels now match what you type ("R&D" finds a title stored as "R&amp;D").
* When a Source content or Destination search finds nothing but "All" would, the Report says how many clicks match and links there.
* New "Group by: Page" option totals the matching links on each post (links, clicks, last activity). "See all links" on a page row opens the links behind that total. CSV export follows the grouping (page exports have their own file name and columns; link exports are unchanged).
* Destination URLs in the Report are shown decoded and shortened in the middle, so links that differ only at the end can be told apart.

= 1.2.0 =
* Cloaked links on your own site (for example /go/product or /recommends/product created by Redirection, Pretty Links, ThirstyAffiliates or a server rule) are now tracked under their real destination. The plugin asks your own server where the link goes, with a signed request that never renders a page, and records the answer in two new tables (schema version 3). Answers are refreshed weekly by WP-Cron and are bounded by a per-render budget, a per-run budget, a daily cap and a circuit breaker.
* Status screen: new rows for the resolution state, counts, probes today, the probe route and the cron, plus a "Clear resolution cache" button.
* New filters `octk_resolve_enabled`, `octk_pre_resolve_same_site`, `octk_resolve_ttl`, `octk_resolve_daily_cap`, `octk_resolve_probe_origin`, `octk_resolve_probe_sslverify`; new action `octk_resolution_changed`; new constant `OCTK_DISABLE_RESOLVE`.
* Frontend output changes on pages that contain cloaked links, so purge your page cache after updating.

= 1.1.1 =
* Every database query now passes table names through wpdb::prepare() (%i placeholders) and the report sort clause is chosen from a fixed list of complete ORDER BY strings. No behaviour change; addresses the WordPress.org plugin review feedback.

= 1.1.0 =
* Renamed to Hopcount Click Tracking (slug and text domain `hopcount-click-tracking`). Code prefix, hooks, tables, options and the REST route are unchanged, so existing data and site code carry over.

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

= 1.3.1 =
Report host filter reaches every host, CSV titles are plain text, and small cloaked-link fixes. No cache purge needed.

= 1.3.0 =
Report search covers titles, destinations and labels by default, pasted URLs match, and results can be totalled per page. Admin screens only; no cache purge needed.

= 1.2.0 =
Cloaked same-site links are now tracked under their real destination. Adds two tables and a WP-Cron job; purge your page cache after updating so the new tokens appear.

= 1.1.0 =
Plugin folder renamed to hopcount-click-tracking. Deactivate the old folder, upload the new one, activate. Data and settings are preserved.

= 1.0.1 =
Hardening of the pre-1.0 data migration. No functional changes for fresh installs.

= 1.0.0 =
Renamed plugin. Deactivate the pre-1.0 plugin before activating this one; data migrates automatically. Site code using the old filter names and any rate-limit rule on the old endpoint path must be updated.
