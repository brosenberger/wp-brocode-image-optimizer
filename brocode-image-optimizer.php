<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */
/**
 * Plugin Name:       BroCode Image Optimizer
 * Plugin URI:        https://github.com/brosenberger/wp-brocode-image-optimizer
 * Description:       Cron-driven WebP & AVIF sidecar generation. Serving is handled at the web-server layer via Accept content negotiation — no PHP in the image path.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Benjamin Rosenberger
 * Author URI:        https://brocode.at
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       brocode-image-optimizer
 */

declare(strict_types=1);

namespace Brocode\ImageOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_CLI;
use WP_CLI_Command;
use WP_REST_Request;
use WP_REST_Response;

const OPTION_KEY = 'brocode_image_optimizer_settings';
const CRON_HOOK = 'brocode_image_optimizer_scan';

// Source extensions that get modern-format sidecars.
const SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

// Upper bound on conversions per scan run. A large library drains over
// successive cron runs instead of timing out a single one — the deliberate,
// queue-free throttle (no async, by design).
const SCAN_BATCH_LIMIT = 200;

add_action('admin_init', __NAMESPACE__ . '\\registerSettings');
add_action('admin_menu', __NAMESPACE__ . '\\registerAdminPage');
add_action('admin_post_' . CRON_HOOK, __NAMESPACE__ . '\\handleScanNow');
add_action('init', __NAMESPACE__ . '\\loadTextdomain');
add_action('init', __NAMESPACE__ . '\\ensureScheduled');
add_action(CRON_HOOK, __NAMESPACE__ . '\\runScan');
add_action('init', __NAMESPACE__ . '\\registerCliCommand');
add_action('rest_api_init', __NAMESPACE__ . '\\registerRestRoutes');

register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate');

function defaultSettings(): array
{
    return [
        'enable_webp' => 1,
        'enable_avif' => 1,
        'webp_quality' => 82,
        'avif_quality' => 58,
    ];
}

function getSettings(): array
{
    $settings = get_option(OPTION_KEY, []);
    if (!is_array($settings)) {
        $settings = [];
    }

    return array_merge(defaultSettings(), $settings);
}

/**
 * Whether this server's active image library can actually produce AVIF. Drives
 * every AVIF-specific surface (admin fields, web-server snippets, scan formats)
 * so the feature degrades to WebP-only where AVIF is unsupported (e.g. WP < 6.5
 * or a GD/Imagick build without AVIF).
 */
function avifSupported(): bool
{
    return wp_image_editor_supports(['mime_type' => 'image/avif']);
}

/**
 * Whether the active image library can produce WebP (WP >= 5.8 with a WebP-capable
 * GD/Imagick build). Gating the scan on this avoids endlessly re-counting WebP as
 * "pending" and retrying it on installs that cannot encode it.
 */
function webpSupported(): bool
{
    return wp_image_editor_supports(['mime_type' => 'image/webp']);
}

function registerSettings(): void
{
    register_setting(
        'brocode_image_optimizer',
        OPTION_KEY,
        [
            'type' => 'array',
            'sanitize_callback' => static function ($value): array {
                $defaults = defaultSettings();
                $value = is_array($value) ? $value : [];

                return [
                    'enable_webp' => !empty($value['enable_webp']) ? 1 : 0,
                    'enable_avif' => !empty($value['enable_avif']) ? 1 : 0,
                    'webp_quality' => isset($value['webp_quality']) ? max(10, min(100, (int) $value['webp_quality'])) : $defaults['webp_quality'],
                    'avif_quality' => isset($value['avif_quality']) ? max(10, min(100, (int) $value['avif_quality'])) : $defaults['avif_quality'],
                ];
            },
            'default' => defaultSettings(),
        ]
    );
}

/**
 * Self-healing cron schedule: survives plugin updates and lost cron entries
 * without depending solely on the activation hook.
 */
function ensureScheduled(): void
{
    if (!wp_next_scheduled(CRON_HOOK)) {
        wp_schedule_event(time() + 60, 'hourly', CRON_HOOK);
    }
}

function deactivate(): void
{
    $timestamp = wp_next_scheduled(CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, CRON_HOOK);
    }
}

function loadTextdomain(): void
{
    load_plugin_textdomain(
        'brocode-image-optimizer',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

function registerAdminPage(): void
{
    add_options_page(
        'BroCode Image Optimizer',
        'BroCode Image Optimizer',
        'manage_options',
        'brocode-image-optimizer',
        __NAMESPACE__ . '\\renderAdminPage'
    );
}

function renderAdminPage(): void
{
    $settings = getSettings();
    $scanned = isset($_GET['scanned']) ? (int) $_GET['scanned'] : null;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('BroCode Image Optimizer', 'brocode-image-optimizer'); ?></h1>
        <?php if ($scanned !== null) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(
                /* translators: %d: number of sidecar files generated */
                __('Scan complete — generated %d sidecar(s).', 'brocode-image-optimizer'),
                $scanned
            )); ?></p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('brocode_image_optimizer'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Generate WebP', 'brocode-image-optimizer'); ?></th>
                    <td><input type="checkbox" name="<?php echo esc_attr(OPTION_KEY); ?>[enable_webp]" value="1" <?php checked((int) $settings['enable_webp'], 1); ?>></td>
                </tr>
                <?php if (avifSupported()) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Generate AVIF', 'brocode-image-optimizer'); ?></th>
                    <td><input type="checkbox" name="<?php echo esc_attr(OPTION_KEY); ?>[enable_avif]" value="1" <?php checked((int) $settings['enable_avif'], 1); ?>></td>
                </tr>
                <?php else : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Generate AVIF', 'brocode-image-optimizer'); ?></th>
                    <td><em><?php esc_html_e("Not available — this server's image library was not built with AVIF support.", 'brocode-image-optimizer'); ?></em></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('WebP Quality', 'brocode-image-optimizer'); ?></th>
                    <td><input type="number" name="<?php echo esc_attr(OPTION_KEY); ?>[webp_quality]" value="<?php echo esc_attr((string) $settings['webp_quality']); ?>" min="10" max="100"></td>
                </tr>
                <?php if (avifSupported()) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e('AVIF Quality', 'brocode-image-optimizer'); ?></th>
                    <td><input type="number" name="<?php echo esc_attr(OPTION_KEY); ?>[avif_quality]" value="<?php echo esc_attr((string) $settings['avif_quality']); ?>" min="10" max="100"></td>
                </tr>
                <?php endif; ?>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2><?php esc_html_e('Run a scan', 'brocode-image-optimizer'); ?></h2>
        <p><?php printf(
            /* translators: 1: WP-CLI command, 2: WP-CLI list flag */
            wp_kses(
                __('An hourly cron job converts new images automatically. Trigger one immediately below, or run %1$s from the CLI (%2$s counts pending without writing).', 'brocode-image-optimizer'),
                ['code' => []]
            ),
            '<code>ddev wp brocode-image scan</code>',
            '<code>--list</code>'
        ); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(CRON_HOOK); ?>">
            <?php wp_nonce_field(CRON_HOOK); ?>
            <?php submit_button(__('Scan now', 'brocode-image-optimizer'), 'secondary'); ?>
        </form>

        <h2><?php esc_html_e('Web-server configuration (required)', 'brocode-image-optimizer'); ?></h2>
        <p><?php
            $webpSidecar = '<code>' . esc_html('photo.jpg.webp') . '</code>';
            $avifSidecar = avifSupported() ? ' / <code>' . esc_html('photo.jpg.avif') . '</code>' : '';
            printf(
                /* translators: 1: WebP sidecar example, 2: optional AVIF sidecar example, 3: Accept HTTP header name, 4: bold disclaimer phrase */
                wp_kses(
                    __('Conversion writes %1$s%2$s sidecars next to each original. Delivery happens entirely in the web server — it inspects the browser\'s %3$s header and serves the best sidecar that exists, with no PHP in the path and no template changes. This plugin %4$s these rules; add the snippet for your server. Until it is in place, browsers receive the original files.', 'brocode-image-optimizer'),
                    ['code' => [], 'strong' => []]
                ),
                $webpSidecar,
                $avifSidecar,
                '<code>Accept</code>',
                '<strong>' . esc_html__('documents but cannot install', 'brocode-image-optimizer') . '</strong>'
            );
        ?></p>

        <h3><?php printf(
            /* translators: 1: map directive, 2: http block, 3: location directive, 4: server block — keep code tags as-is */
            wp_kses(
                __('nginx (%1$s in the %2$s block, %3$s in the %4$s block)', 'brocode-image-optimizer'),
                ['code' => []]
            ),
            '<code>map</code>',
            '<code>http</code>',
            '<code>location</code>',
            '<code>server</code>'
        ); ?></h3>
        <pre><code><?php echo esc_html(nginxSnippet()); ?></code></pre>

        <h3><?php printf(
            /* translators: 1: .htaccess filename */
            wp_kses(
                __('Apache (%1$s in the uploads directory, or the site root)', 'brocode-image-optimizer'),
                ['code' => []]
            ),
            '<code>.htaccess</code>'
        ); ?></h3>
        <pre><code><?php echo esc_html(apacheSnippet()); ?></code></pre>
    </div>
    <?php
}

function handleScanNow(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'brocode-image-optimizer'));
    }
    check_admin_referer(CRON_HOOK);

    $result = runScan();

    wp_safe_redirect(
        add_query_arg(
            ['page' => 'brocode-image-optimizer', 'scanned' => $result['converted']],
            admin_url('options-general.php')
        )
    );
    exit;
}

/**
 * REST trigger for the scan, so environments without WP-CLI (e.g. production)
 * can run a conversion pass on demand — invoked by the brocode-content MCP.
 * Same brocode/v1 namespace and manage_options gate as the showcase plugin's
 * routes; the MCP authenticates as an admin via application password.
 */
function registerRestRoutes(): void
{
    register_rest_route(
        'brocode/v1',
        '/image-optimizer/scan',
        [
            'methods' => 'POST',
            'callback' => __NAMESPACE__ . '\\handleRestScan',
            'permission_callback' => static fn() => current_user_can('manage_options'),
            'args' => [
                'list' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]
    );
}

function handleRestScan(WP_REST_Request $request): WP_REST_Response
{
    $result = runScan((bool) $request->get_param('list'));

    return new WP_REST_Response($result, 200);
}

/**
 * Walk the uploads directory and create any missing WebP/AVIF sidecars.
 *
 * @return array{converted:int,pending:int}
 */
function runScan(bool $listOnly = false): array
{
    $settings = getSettings();
    $upload = wp_get_upload_dir();
    $baseDir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';

    $result = ['converted' => 0, 'pending' => 0];
    if ($baseDir === '' || !is_dir($baseDir)) {
        return $result;
    }

    $formats = [];
    if ((int) $settings['enable_webp'] === 1 && webpSupported()) {
        $formats['webp'] = (int) $settings['webp_quality'];
    }
    if ((int) $settings['enable_avif'] === 1 && avifSupported()) {
        $formats['avif'] = (int) $settings['avif_quality'];
    }
    if ($formats === []) {
        return $result;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$listOnly && $result['converted'] >= SCAN_BATCH_LIMIT) {
            break;
        }
        if (!$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, SOURCE_EXTENSIONS, true)) {
            continue;
        }

        foreach ($formats as $format => $quality) {
            if (!needsSidecar($path, sidecarPath($path, $format))) {
                continue;
            }
            $result['pending']++;
            if ($listOnly) {
                continue;
            }
            if (createSidecar($path, $format, $quality)) {
                $result['converted']++;
            }
        }
    }

    return $result;
}

/**
 * Article-faithful sidecar naming: the format is appended to the full original
 * filename (photo.jpg -> photo.jpg.webp) so the web server can `try_files
 * $uri$suffix`.
 */
function sidecarPath(string $sourcePath, string $format): string
{
    return $sourcePath . '.' . $format;
}

/**
 * A sidecar must be (re)generated when it is missing, or when the original has
 * been modified more recently than the sidecar — i.e. the original was replaced
 * in place. Mirrors the source module's needsConversion() mtime check; works
 * because createSidecar() syncs the sidecar's mtime back to the original.
 */
function needsSidecar(string $sourcePath, string $sidecarPath): bool
{
    if (!file_exists($sidecarPath)) {
        return true;
    }

    return filemtime($sourcePath) > filemtime($sidecarPath);
}

function createSidecar(string $sourcePath, string $format, int $quality): bool
{
    $mimeType = 'image/' . $format;
    if (!wp_image_editor_supports(['mime_type' => $mimeType])) {
        return false;
    }

    try {
        $editor = wp_get_image_editor($sourcePath);
        if (is_wp_error($editor)) {
            return false;
        }

        // save() takes the mime type as a string second argument; quality is a
        // separate setter. Passing the legacy array form fatals in WP's editor.
        $editor->set_quality($quality);
        $saved = $editor->save(sidecarPath($sourcePath, $format), $mimeType);
        if (is_wp_error($saved) || !isset($saved['path'])) {
            return false;
        }

        // Sync the sidecar's mtime to the original so re-scans only regenerate
        // when the original is genuinely replaced (mirrors the source module's
        // touch()), keeping the needsSidecar() comparison stable.
        touch((string) $saved['path'], filemtime($sourcePath));

        return true;
    } catch (\Throwable $exception) {
        return false;
    }
}

function nginxSnippet(): string
{
    $avif = avifSupported();

    $lines = ['# --- http {} context ---'];
    if ($avif) {
        $lines[] = 'map $http_accept $brocode_avif { default ""; "~*image/avif" ".avif"; }';
    }
    $lines[] = 'map $http_accept $brocode_webp { default ""; "~*image/webp" ".webp"; }';
    $lines[] = '';
    $lines[] = '# --- server {} context ---';
    $lines[] = 'location ~* ^/wp-content/uploads/.+\.(jpe?g|png)$ {';
    $lines[] = '    add_header Vary Accept;';
    $lines[] = '    try_files ' . ($avif ? '$uri$brocode_avif ' : '') . '$uri$brocode_webp $uri =404;';
    $lines[] = '}';

    return implode("\n", $lines);
}

function apacheSnippet(): string
{
    $avifRule = <<<'AVIF'

    # Serve AVIF when accepted and the sidecar exists.
    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME} (?i)\.(jpe?g|png)$
    RewriteCond %{REQUEST_FILENAME}.avif -f
    RewriteRule (?i)^(.+\.(jpe?g|png))$ $1.avif [T=image/avif,E=ACCEPT:1,L]

AVIF;

    $webpRule = <<<'WEBP'

    # Serve WebP when accepted and the sidecar exists.
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_FILENAME} (?i)\.(jpe?g|png)$
    RewriteCond %{REQUEST_FILENAME}.webp -f
    RewriteRule (?i)^(.+\.(jpe?g|png))$ $1.webp [T=image/webp,E=ACCEPT:1,L]
WEBP;

    return "<IfModule mod_rewrite.c>\n    RewriteEngine On\n"
        . (avifSupported() ? $avifRule : '')
        . $webpRule
        . "\n</IfModule>\n\n"
        . "<IfModule mod_headers.c>\n    Header append Vary Accept env=ACCEPT\n</IfModule>";
}

function registerCliCommand(): void
{
    if (!class_exists('WP_CLI')) {
        return;
    }

    WP_CLI::add_command('brocode-image', new class extends WP_CLI_Command {
        /**
         * Generate missing WebP/AVIF sidecars across the uploads directory.
         *
         * ## OPTIONS
         *
         * [--list]
         * : Only count pending conversions; do not write any files.
         *
         * ## EXAMPLES
         *
         *     wp brocode-image scan
         *     wp brocode-image scan --list
         */
        public function scan(array $args, array $assocArgs): void
        {
            $listOnly = isset($assocArgs['list']);
            $result = runScan($listOnly);

            if ($listOnly) {
                WP_CLI::success(sprintf(
                    /* translators: %d: number of pending sidecars */
                    __('%d sidecar(s) pending conversion.', 'brocode-image-optimizer'),
                    $result['pending']
                ));

                return;
            }

            WP_CLI::success(sprintf(
                /* translators: %d: number of generated sidecars */
                __('Generated %d sidecar(s).', 'brocode-image-optimizer'),
                $result['converted']
            ));
        }
    });
}
