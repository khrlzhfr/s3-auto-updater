<?php
/**
 * Hooks into the WordPress update system and injects update data
 * for custom plugins and themes hosted in an S3 bucket.
 *
 * Bucket naming convention:
 *   plugins/{slug}---{version}.zip
 *   themes/{slug}---{version}.zip
 *
 * The slug must match the installed plugin/theme directory name.
 * The zip must contain a top-level directory matching the slug.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Auto_Updater_Updater {

    /** @var S3_Auto_Updater_Client */
    private $client;

    /** @var string Delimiter between slug and version in filenames. */
    private $delimiter = '---';

    /** @var int Cache duration in seconds (12 hours). */
    private $cache_expiry = 43200;

    /** @var string Transient key prefix. */
    private $cache_prefix = 's3_auto_updater_';

    /**
     * @param S3_Auto_Updater_Client $client
     */
    public function __construct( S3_Auto_Updater_Client $client ) {
        $this->client = $client;
    }

    /**
     * Register all hooks.
     */
    public function init() {
        // Inject updates into WordPress transients.
        add_filter( 'site_transient_update_plugins', array( $this, 'check_plugin_updates' ) );
        add_filter( 'site_transient_update_themes', array( $this, 'check_theme_updates' ) );

        // Handle authenticated downloads from S3.
        add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), 10, 4 );

        // Fix extracted directory names if they don't match the slug.
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );

        // Clear our cache when WordPress force-checks for updates.
        add_action( 'load-update-core.php', array( $this, 'maybe_flush_cache' ) );
    }

    /* ------------------------------------------------------------------
     * Update checks
     * ----------------------------------------------------------------*/

    /**
     * Filter: site_transient_update_plugins
     *
     * Compares installed plugin versions against what's in the S3 bucket
     * and injects update entries for anything with a newer version.
     *
     * @param  object $transient
     * @return object
     */
    public function check_plugin_updates( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        // $transient->checked may be empty on some early calls.
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $s3_items = $this->get_s3_items( 'plugins' );
        if ( empty( $s3_items ) ) {
            return $transient;
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $installed = get_plugins();

        foreach ( $installed as $plugin_file => $plugin_data ) {
            $slug = dirname( $plugin_file );
            if ( '.' === $slug ) {
                continue; // Single-file plugins without a directory.
            }

            if ( ! isset( $s3_items[ $slug ] ) ) {
                continue;
            }

            $s3_version        = $s3_items[ $slug ]['version'];
            $installed_version = $plugin_data['Version'];

            if ( version_compare( $s3_version, $installed_version, '>' ) ) {
                $transient->response[ $plugin_file ] = (object) array(
                    'slug'        => $slug,
                    'plugin'      => $plugin_file,
                    'new_version' => $s3_version,
                    'package'     => $this->build_s3_url( $s3_items[ $slug ]['key'] ),
                    'url'         => '',
                    'icons'       => array(),
                    'banners'     => array(),
                );
            }
        }

        return $transient;
    }

    /**
     * Filter: site_transient_update_themes
     *
     * @param  object $transient
     * @return object
     */
    public function check_theme_updates( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $s3_items = $this->get_s3_items( 'themes' );
        if ( empty( $s3_items ) ) {
            return $transient;
        }

        $installed = wp_get_themes();

        foreach ( $installed as $slug => $theme ) {
            if ( ! isset( $s3_items[ $slug ] ) ) {
                continue;
            }

            $s3_version        = $s3_items[ $slug ]['version'];
            $installed_version = $theme->get( 'Version' );

            if ( version_compare( $s3_version, $installed_version, '>' ) ) {
                $transient->response[ $slug ] = array(
                    'theme'       => $slug,
                    'new_version' => $s3_version,
                    'package'     => $this->build_s3_url( $s3_items[ $slug ]['key'] ),
                    'url'         => '',
                );
            }
        }

        return $transient;
    }

    /* ------------------------------------------------------------------
     * Download handling
     * ----------------------------------------------------------------*/

    /**
     * Filter: upgrader_pre_download
     *
     * Intercepts download requests for S3-hosted packages, performs
     * an authenticated download, and returns the local temp file path.
     *
     * @param  bool|WP_Error $reply      Default false (continue normal download).
     * @param  string        $package    The package URL.
     * @param  WP_Upgrader   $upgrader
     * @param  array         $hook_extra
     * @return bool|string|WP_Error      Temp file path, or false/WP_Error.
     */
    public function pre_download( $reply, $package, $upgrader, $hook_extra ) {
        if ( ! $this->is_our_url( $package ) ) {
            return $reply;
        }

        $key = $this->url_to_key( $package );
        if ( ! $key ) {
            return new WP_Error(
                's3_updater_bad_url',
                'S3 Auto Updater: could not extract object key from package URL.'
            );
        }

        $upgrader->skin->feedback( 'Downloading from S3&hellip;' );

        $tmpfile = $this->client->download( $key );

        if ( is_wp_error( $tmpfile ) ) {
            return new WP_Error(
                's3_updater_download_failed',
                'S3 Auto Updater: download failed &ndash; ' . $tmpfile->get_error_message()
            );
        }

        return $tmpfile;
    }

    /* ------------------------------------------------------------------
     * Post-extraction directory fix
     * ----------------------------------------------------------------*/

    /**
     * Filter: upgrader_source_selection
     *
     * If the extracted directory doesn't match the expected plugin/theme
     * slug, rename it so WordPress can install it correctly.
     *
     * Only acts on items managed by this updater.
     *
     * @param  string       $source        Path to the extracted directory.
     * @param  string       $remote_source Parent directory.
     * @param  WP_Upgrader  $upgrader
     * @param  array        $hook_extra
     * @return string|WP_Error
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
        $slug = $this->get_slug_from_hook_extra( $hook_extra );
        if ( ! $slug ) {
            return $source;
        }

        // Only act on items we manage.
        if ( ! $this->is_managed( $slug ) ) {
            return $source;
        }

        $source_dir = basename( untrailingslashit( $source ) );

        // Already correct – nothing to do.
        if ( $source_dir === $slug ) {
            return $source;
        }

        $new_source = trailingslashit( $remote_source ) . $slug . '/';

        if ( true === @rename( $source, $new_source ) ) {
            return $new_source;
        }

        // Rename failed – log and return original (WordPress may still cope).
        error_log( sprintf(
            'S3 Auto Updater: could not rename "%s" to "%s".',
            $source,
            $new_source
        ) );

        return $source;
    }

    /* ------------------------------------------------------------------
     * Cache management
     * ----------------------------------------------------------------*/

    /**
     * Clear our transient cache when the user clicks "Check again"
     * on the Dashboard › Updates page.
     */
    public function maybe_flush_cache() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['force-check'] ) ) {
            delete_transient( $this->cache_prefix . 'plugins' );
            delete_transient( $this->cache_prefix . 'themes' );
        }
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Fetch and cache the list of available items from S3.
     *
     * @param  string $type 'plugins' or 'themes'.
     * @return array        Keyed by slug: [ 'version' => '...', 'key' => '...' ].
     */
    private function get_s3_items( $type ) {
        $cache_key = $this->cache_prefix . $type;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $prefix  = $type . '/';
        $objects = $this->client->list_objects( $prefix );

        if ( is_wp_error( $objects ) ) {
            error_log( 'S3 Auto Updater: ' . $objects->get_error_message() );
            return array();
        }

        $items = array();

        foreach ( $objects as $key ) {
            $filename = basename( $key );

            // Skip anything that isn't a zip.
            if ( substr( $filename, -4 ) !== '.zip' ) {
                continue;
            }

            $parsed = $this->parse_filename( $filename );
            if ( ! $parsed ) {
                continue;
            }

            $slug    = $parsed['slug'];
            $version = $parsed['version'];

            // If multiple versions of the same slug exist, keep the highest.
            if ( isset( $items[ $slug ] ) ) {
                if ( version_compare( $version, $items[ $slug ]['version'], '<=' ) ) {
                    continue;
                }
            }

            $items[ $slug ] = array(
                'version' => $version,
                'key'     => $key,
            );
        }

        set_transient( $cache_key, $items, $this->cache_expiry );

        return $items;
    }

    /**
     * Parse a filename like 'my-plugin---1.2.0.zip' into slug and version.
     *
     * @param  string     $filename
     * @return array|null [ 'slug' => '...', 'version' => '...' ] or null.
     */
    private function parse_filename( $filename ) {
        $name  = substr( $filename, 0, -4 ); // Strip .zip
        $parts = explode( $this->delimiter, $name, 2 );

        if ( count( $parts ) !== 2 || '' === $parts[0] || '' === $parts[1] ) {
            return null;
        }

        return array(
            'slug'    => $parts[0],
            'version' => $parts[1],
        );
    }

    /**
     * Build the raw (unsigned) S3 URL for an object key.
     * Used as the package URL; actual download is authenticated
     * via the upgrader_pre_download filter.
     *
     * @param  string $key
     * @return string
     */
    private function build_s3_url( $key ) {
        return sprintf(
            'https://%s.s3.%s.amazonaws.com/%s',
            S3_UPDATER_BUCKET,
            S3_UPDATER_REGION,
            rawurlencode( $key )
        );
    }

    /**
     * Check whether a URL belongs to our S3 bucket.
     *
     * @param  string $url
     * @return bool
     */
    private function is_our_url( $url ) {
        $base = sprintf(
            'https://%s.s3.%s.amazonaws.com/',
            S3_UPDATER_BUCKET,
            S3_UPDATER_REGION
        );

        return 0 === strpos( $url, $base );
    }

    /**
     * Extract the S3 object key from a package URL.
     *
     * @param  string      $url
     * @return string|null
     */
    private function url_to_key( $url ) {
        $base = sprintf(
            'https://%s.s3.%s.amazonaws.com/',
            S3_UPDATER_BUCKET,
            S3_UPDATER_REGION
        );

        if ( 0 !== strpos( $url, $base ) ) {
            return null;
        }

        $key = substr( $url, strlen( $base ) );

        return rawurldecode( $key );
    }

    /**
     * Determine the expected slug from the $hook_extra array
     * passed by the WordPress upgrader.
     *
     * @param  array       $hook_extra
     * @return string|null
     */
    private function get_slug_from_hook_extra( $hook_extra ) {
        if ( ! empty( $hook_extra['plugin'] ) ) {
            $slug = dirname( $hook_extra['plugin'] );
            return ( '.' === $slug ) ? null : $slug;
        }

        if ( ! empty( $hook_extra['theme'] ) ) {
            return $hook_extra['theme'];
        }

        return null;
    }

    /**
     * Check whether a slug is managed by this updater.
     *
     * @param  string $slug
     * @return bool
     */
    private function is_managed( $slug ) {
        $plugins = $this->get_s3_items( 'plugins' );
        $themes  = $this->get_s3_items( 'themes' );

        return isset( $plugins[ $slug ] ) || isset( $themes[ $slug ] );
    }
}
