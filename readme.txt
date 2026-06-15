=== BroCode Image Optimizer ===
Contributors:      brosenberger
Tags:              webp, avif, image optimization, performance, image conversion
Requires at least: 5.8
Tested up to:      6.8
Stable tag:        2.0.0
Requires PHP:      7.4
License:           MIT
License URI:       https://opensource.org/licenses/MIT

Cron-driven WebP & AVIF sidecar generation. Serving via web-server content negotiation — no PHP in the image path.

== Description ==

BroCode Image Optimizer generates WebP and AVIF sidecars for every JPEG and PNG in your uploads directory. Delivery is handled entirely at the web-server layer via HTTP `Accept` header negotiation — there is no PHP involved in serving images, which means zero per-request overhead.

**How it works**

An hourly WordPress cron job scans the uploads directory for JPEG and PNG files that are missing their `.webp` and/or `.avif` sidecars. When found, it converts them using WordPress's own image editor API (GD or Imagick — whichever is active). The original files are never modified or deleted.

Delivery requires a small web-server rewrite rule (nginx or Apache) that checks the browser's `Accept` header and transparently serves the best available sidecar. The plugin's settings page shows the exact snippet for your server.

**Features**

* Automatic hourly conversion via WP-Cron
* Manual scan trigger from the settings page or WP-CLI (`wp brocode-image scan`)
* Configurable WebP and AVIF quality (10–100)
* AVIF support is auto-detected and disabled gracefully when the server's image library does not support it
* REST API endpoint (`POST /wp-json/brocode/v1/image-optimizer/scan`) for on-demand conversion in environments without WP-CLI
* No JavaScript, no page builders, no third-party services — pure PHP and web server

**Web-server configuration is required.** The plugin converts images but cannot install the rewrite rules itself. Without those rules, browsers always receive the original JPEG/PNG files.

== Installation ==

1. Upload the `brocode-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → BroCode Image Optimizer** and configure quality settings.
4. Add the web-server rewrite snippet shown on the settings page to your nginx or Apache configuration.

== Web Server Configuration ==

The plugin writes sidecar files but relies on the web server to serve them. Add the snippet for your server once; no further changes are needed when images are added later.

Sidecars use the pattern `photo.jpg.webp` / `photo.jpg.avif` — the format is appended to the full original filename. This lets the rewrite rules use a simple suffix lookup without parsing the filename.

**nginx** — add the `map` directives inside the `http {}` block and the `location` block inside your `server {}` block:

    # --- http {} context ---
    map $http_accept $brocode_avif { default ""; "~*image/avif" ".avif"; }
    map $http_accept $brocode_webp { default ""; "~*image/webp" ".webp"; }

    # --- server {} context ---
    location ~* ^/wp-content/uploads/.+\.(jpe?g|png)$ {
        add_header Vary Accept;
        try_files $uri$brocode_avif $uri$brocode_webp $uri =404;
    }

If your server does not support AVIF (GD/Imagick built without AVIF), omit the `brocode_avif` map and the `$uri$brocode_avif` entry from `try_files`.

**Apache** — place this in `.htaccess` in your uploads directory, or in the site root `.htaccess` inside the WordPress rewrite block:

    <IfModule mod_rewrite.c>
        RewriteEngine On

        # Serve AVIF when accepted and the sidecar exists.
        RewriteCond %{HTTP_ACCEPT} image/avif
        RewriteCond %{REQUEST_FILENAME} (?i)\.(jpe?g|png)$
        RewriteCond %{REQUEST_FILENAME}.avif -f
        RewriteRule (?i)^(.+\.(jpe?g|png))$ $1.avif [T=image/avif,E=ACCEPT:1,L]

        # Serve WebP when accepted and the sidecar exists.
        RewriteCond %{HTTP_ACCEPT} image/webp
        RewriteCond %{REQUEST_FILENAME} (?i)\.(jpe?g|png)$
        RewriteCond %{REQUEST_FILENAME}.webp -f
        RewriteRule (?i)^(.+\.(jpe?g|png))$ $1.webp [T=image/webp,E=ACCEPT:1,L]
    </IfModule>

    <IfModule mod_headers.c>
        Header append Vary Accept env=ACCEPT
    </IfModule>

If your server does not support AVIF, omit the first `RewriteCond`/`RewriteRule` block.

The `Vary: Accept` header tells CDNs and reverse proxies to cache WebP and AVIF responses separately from the original, so the correct variant is served to each browser.

The settings page inside WordPress (**Settings → BroCode Image Optimizer**) generates these snippets dynamically, reflecting whether AVIF is supported on your specific server.

== Frequently Asked Questions ==

= Does this plugin modify original image files? =

No. Original JPEG and PNG files are never modified. The plugin writes sidecar files alongside the originals (e.g. `photo.jpg.webp` next to `photo.jpg`).

= What happens if AVIF is not supported by my server? =

The plugin auto-detects AVIF support using WordPress's `wp_image_editor_supports()` check. If the active GD or Imagick build does not include AVIF, the AVIF option is hidden in the settings and no AVIF conversion is attempted.

= Do I need to reconfigure my web server? =

Yes. The plugin generates the sidecar files but cannot install the web-server rewrite rules that serve them. Without those rules, browsers always receive the original files. The settings page shows the exact nginx and Apache snippets you need.

= Can I trigger a scan without waiting for cron? =

Yes — via the **Scan now** button on the settings page, the WP-CLI command `wp brocode-image scan`, or the REST endpoint `POST /wp-json/brocode/v1/image-optimizer/scan` (requires admin authentication via application password).

== Changelog ==

= 2.0.0 =
* Rewritten as a namespaced single-file plugin.
* Added AVIF support with runtime detection.
* Added REST API trigger endpoint.
* Added WP-CLI command (`wp brocode-image scan --list`).
* Added configurable quality settings per format.
* Sidecar naming uses the full original filename as prefix (`photo.jpg.webp`) for compatibility with `try_files $uri$suffix` nginx rewrites.

= 1.0.0 =
* Initial release. WebP-only sidecar generation.
