# WordPress.org listing assets

These files go into the SVN `assets/` directory of the wp.org listing, not into the plugin zip.

| File | Size | Notes |
|---|---|---|
| `banner-772x250.png` | 772 x 250 | Listing header (also `.jpg` accepted) |
| `banner-1544x500.png` | 1544 x 500 | Retina header |
| `icon-128x128.png` | 128 x 128 | Plugin icon |
| `icon-256x256.png` | 256 x 256 | Retina icon |
| `icon.svg` | vector | Optional, preferred when present |
| `screenshot-1.png` | any | Report page (caption 1 in readme.txt) |
| `screenshot-2.png` | any | Labels page |
| `screenshot-3.png` | any | Settings page |
| `screenshot-4.png` | any | Status page |
| `screenshot-5.png` | any | Dashboard widget |

Screenshot captions live in the `== Screenshots ==` section of `outbound-click-tracking/readme.txt` and must stay in the same order. Spec: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/

## Regenerating

`source.html` holds the banner and icon as HTML/SVG. Open it in Chrome at 2x device pixel ratio, take a full-page screenshot, crop the banner (`1544x500` at 2x, then resize) and the icon (`256x256` at 2x). Screenshots were taken on the wp-env dev site (1440x900 viewport, 2x, resized to 1x) with sample posts and seeded hourly counts.
