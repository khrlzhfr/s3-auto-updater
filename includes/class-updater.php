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

        // Add "Update all now" action link on the S3 Auto Updater row.
        add_filter(
            'plugin_action_links_s3-auto-updater/s3-auto-updater.php',
            array( $this, 'add_update_all_link' )
        );

        // Handle the bulk update request.
        add_action( 'admin_post_s3_update_all', array( $this, 'handle_update_all' ) );

        // Show results after a bulk update.
        add_action( 'admin_notices', array( $this, 'show_update_results' ) );
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
     * Plugin action links and bulk update
     * ----------------------------------------------------------------*/

    /**
     * Filter: plugin_action_links_s3-auto-updater/s3-auto-updater.php
     *
     * Adds an "Update all now" link before "Deactivate" on the
     * S3 Auto Updater's own row in the Plugins page.
     *
     * @param  string[] $actions Array of action links.
     * @return string[]
     */
    public function add_update_all_link( $actions ) {
        $update_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=s3_update_all' ),
            's3_update_all'
        );

        $link = sprintf(
            '<a href="%s" style="font-weight:700;">Update all now</a>',
            esc_url( $update_url )
        );

        // Insert before 'deactivate' if it exists, otherwise prepend.
        $new_actions = array();
        $inserted    = false;

        foreach ( $actions as $key => $value ) {
            if ( 'deactivate' === $key && ! $inserted ) {
                $new_actions['s3_update_all'] = $link;
                $inserted = true;
            }
            $new_actions[ $key ] = $value;
        }

        if ( ! $inserted ) {
            $new_actions = array( 's3_update_all' => $link ) + $new_actions;
        }

        return $new_actions;
    }

    /**
     * Action: admin_post_s3_update_all
     *
     * Handles the "Update all now" request. Clears the S3 cache,
     * forces a fresh update check, then bulk-updates all S3-managed
     * plugins and themes that have newer versions available.
     */
    public function handle_update_all() {
        if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'update_themes' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 's3_update_all' );

        // Clear cached S3 data so we get a fresh listing.
        delete_transient( $this->cache_prefix . 'plugins' );
        delete_transient( $this->cache_prefix . 'themes' );

        // Force WordPress to re-check updates with our fresh S3 data.
        delete_site_transient( 'update_plugins' );
        delete_site_transient( 'update_themes' );
        wp_update_plugins();
        wp_update_themes();

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        // --- Update plugins ---
        $s3_plugins = $this->get_s3_items( 'plugins' );
        $installed  = get_plugins();
        $plugins_to_update = array();

        foreach ( $installed as $plugin_file => $plugin_data ) {
            $slug = dirname( $plugin_file );
            if ( '.' === $slug || ! isset( $s3_plugins[ $slug ] ) ) {
                continue;
            }
            if ( version_compare( $s3_plugins[ $slug ]['version'], $plugin_data['Version'], '>' ) ) {
                $plugins_to_update[] = $plugin_file;
            }
        }

        // --- Update themes ---
        $s3_themes = $this->get_s3_items( 'themes' );
        $installed_themes = wp_get_themes();
        $themes_to_update = array();

        foreach ( $installed_themes as $slug => $theme ) {
            if ( ! isset( $s3_themes[ $slug ] ) ) {
                continue;
            }
            if ( version_compare( $s3_themes[ $slug ]['version'], $theme->get( 'Version' ), '>' ) ) {
                $themes_to_update[] = $slug;
            }
        }

        $results = array(
            'plugins_updated' => 0,
            'themes_updated'  => 0,
            'failures'        => array(),
        );

        if ( ! empty( $plugins_to_update ) ) {
            $skin     = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->bulk_upgrade( $plugins_to_update );

            if ( is_array( $result ) ) {
                foreach ( $result as $plugin_file => $outcome ) {
                    if ( ! empty( $outcome ) && ! is_wp_error( $outcome ) ) {
                        $results['plugins_updated']++;
                    } else {
                        $slug  = dirname( $plugin_file );
                        $error = is_wp_error( $outcome )
                            ? $outcome->get_error_message()
                            : $this->extract_skin_errors( $skin, $slug );
                        $results['failures'][] = sprintf( 'Plugin "%s": %s', $slug, $error );
                    }
                }
            }
        }

        if ( ! empty( $themes_to_update ) ) {
            $skin     = new Automatic_Upgrader_Skin();
            $upgrader = new Theme_Upgrader( $skin );
            $result   = $upgrader->bulk_upgrade( $themes_to_update );

            if ( is_array( $result ) ) {
                foreach ( $result as $theme_slug => $outcome ) {
                    if ( ! empty( $outcome ) && ! is_wp_error( $outcome ) ) {
                        $results['themes_updated']++;
                    } else {
                        $error = is_wp_error( $outcome )
                            ? $outcome->get_error_message()
                            : $this->extract_skin_errors( $skin, $theme_slug );
                        $results['failures'][] = sprintf( 'Theme "%s": %s', $theme_slug, $error );
                    }
                }
            }
        }

        // Log failures for debugging.
        if ( ! empty( $results['failures'] ) ) {
            foreach ( $results['failures'] as $failure ) {
                error_log( 'S3 Auto Updater: ' . $failure );
            }
        }

        // Store results for display and redirect back.
        set_transient( $this->cache_prefix . 'update_results', $results, 60 );

        wp_safe_redirect( admin_url( 'plugins.php?s3_updated=1' ) );
        exit;
    }

    /**
     * Action: admin_notices
     *
     * Displays the outcome of a bulk update after redirect.
     */
    public function show_update_results() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['s3_updated'] ) ) {
            return;
        }

        $results = get_transient( $this->cache_prefix . 'update_results' );
        delete_transient( $this->cache_prefix . 'update_results' );

        if ( ! $results ) {
            return;
        }

        $total_updated = $results['plugins_updated'] + $results['themes_updated'];
        $failures      = $results['failures'];

        if ( $total_updated === 0 && empty( $failures ) ) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>S3 Auto Updater:</strong> Everything is already up to date.</p></div>';
            return;
        }

        $messages = array();
        if ( $results['plugins_updated'] > 0 ) {
            $messages[] = sprintf( '%d plugin(s) updated', $results['plugins_updated'] );
        }
        if ( $results['themes_updated'] > 0 ) {
            $messages[] = sprintf( '%d theme(s) updated', $results['themes_updated'] );
        }

        $output = '<div class="notice notice-' . ( empty( $failures ) ? 'success' : 'warning' ) . ' is-dismissible">';
        $output .= '<p><strong>S3 Auto Updater:</strong> ';

        if ( ! empty( $messages ) ) {
            $output .= esc_html( implode( ', ', $messages ) ) . '.';
        }

        if ( ! empty( $failures ) ) {
            $output .= '</p><p><strong>Failed:</strong></p><ul style="list-style:disc;margin-left:20px;">';
            foreach ( $failures as $failure ) {
                $output .= '<li>' . esc_html( $failure ) . '</li>';
            }
            $output .= '</ul>';
        } else {
            $output .= '</p>';
        }

        $output .= '</div>';
        echo $output;
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Extract error messages from the upgrader skin's feedback.
     *
     * When bulk_upgrade returns false for an item, the actual error
     * detail is often only available in the skin's message log.
     *
     * @param  Automatic_Upgrader_Skin $skin
     * @param  string                  $slug  The slug to filter messages for.
     * @return string                         Error description.
     */
    private function extract_skin_errors( $skin, $slug ) {
        $messages = $skin->get_upgrade_messages();

        if ( empty( $messages ) ) {
            return 'Unknown error (no details available from the upgrader).';
        }

        // Filter for messages that look like errors or mention this slug.
        $relevant = array();
        foreach ( $messages as $msg ) {
            $msg_lower = strtolower( $msg );
            if (
                strpos( $msg_lower, 'error' ) !== false ||
                strpos( $msg_lower, 'failed' ) !== false ||
                strpos( $msg_lower, 'unable' ) !== false ||
                strpos( $msg_lower, 'could not' ) !== false ||
                strpos( $msg_lower, $slug ) !== false
            ) {
                $relevant[] = wp_strip_all_tags( $msg );
            }
        }

        if ( ! empty( $relevant ) ) {
            return implode( ' | ', $relevant );
        }

        // Fallback: return all skin messages so the user has something to work with.
        return implode( ' | ', array_map( 'wp_strip_all_tags', $messages ) );
    }

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
