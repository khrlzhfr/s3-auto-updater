<?php
/**
 * Admin page under Tools > S3 Updater.
 *
 * Handles:
 * - S3 credential configuration
 * - Uploading plugin/theme zips to S3
 * - Listing and deleting existing packages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Auto_Updater_Upload_Page {

    /** @var S3_Auto_Updater_Settings */
    private $settings;

    /** @var S3_Auto_Updater_Client|null */
    private $client;

    /** @var string Filename delimiter. */
    private $delimiter = '---';

    /**
     * @param S3_Auto_Updater_Settings    $settings
     * @param S3_Auto_Updater_Client|null $client  Null if credentials are not yet configured.
     */
    public function __construct( S3_Auto_Updater_Settings $settings, $client = null ) {
        $this->settings = $settings;
        $this->client   = $client;
    }

    /**
     * Register hooks.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_post_s3_updater_save_settings', array( $this, 'handle_save_settings' ) );
        add_action( 'admin_post_s3_updater_upload', array( $this, 'handle_upload' ) );
        add_action( 'admin_post_s3_updater_delete', array( $this, 'handle_delete' ) );
    }

    /**
     * Add the page under Tools.
     */
    public function add_menu_page() {
        add_management_page(
            'S3 Updater',
            'S3 Updater',
            'update_plugins',
            's3-auto-updater',
            array( $this, 'render_page' )
        );
    }

    /**
     * Render the admin page.
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>S3 Auto Updater</h1>

            <?php $this->show_admin_notices(); ?>

            <?php if ( $this->client ) : ?>

                <h2>Packages in bucket</h2>

                <?php
                $plugins = $this->get_bucket_items( 'plugins' );
                $themes  = $this->get_bucket_items( 'themes' );
                ?>

                <h3>Plugins</h3>
                <?php $this->render_items_table( $plugins, 'plugins' ); ?>

                <h3>Themes</h3>
                <?php $this->render_items_table( $themes, 'themes' ); ?>

                <hr />

                <?php $this->render_upload_form(); ?>

                <hr />

            <?php else : ?>
                <p>Enter your S3 credentials below and save to enable uploads and update checks.</p>
                <hr />
            <?php endif; ?>

            <?php $this->render_credentials_form(); ?>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Credentials
     * ----------------------------------------------------------------*/

    /**
     * Render the credentials form.
     */
    private function render_credentials_form() {
        $fields = $this->settings->get_fields();
        ?>
        <h2>S3 Credentials</h2>
        <p>Fields defined in <code>wp-config.php</code> take priority and cannot be edited here.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 's3_updater_save_settings' ); ?>
            <input type="hidden" name="action" value="s3_updater_save_settings" />

            <table class="form-table">
                <?php foreach ( $fields as $key => $field ) :
                    $is_const = $this->settings->is_constant( $key );
                    $value    = $this->settings->get( $key );
                ?>
                <tr>
                    <th scope="row"><label for="s3u_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                    <td>
                        <?php if ( $is_const ) : ?>
                            <input type="text"
                                   value="<?php echo esc_attr( $this->settings->mask_value( $value, $key ) ); ?>"
                                   class="regular-text"
                                   disabled="disabled" />
                            <span class="description">Defined in <code>wp-config.php</code></span>
                        <?php else : ?>
                            <input type="<?php echo esc_attr( $field['type'] ); ?>"
                                   name="s3u_<?php echo esc_attr( $key ); ?>"
                                   id="s3u_<?php echo esc_attr( $key ); ?>"
                                   value="<?php echo esc_attr( $value ); ?>"
                                   class="regular-text"
                                   autocomplete="off" />
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button( 'Save credentials' ); ?>
        </form>
        <?php
    }

    /**
     * Handle saving credentials.
     */
    public function handle_save_settings() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 's3_updater_save_settings' );

        $data = array();
        foreach ( array( 'bucket', 'region', 'key', 'secret' ) as $key ) {
            if ( isset( $_POST[ 's3u_' . $key ] ) ) {
                $data[ $key ] = $_POST[ 's3u_' . $key ];
            }
        }

        $this->settings->save( $data );

        wp_safe_redirect( admin_url( 'tools.php?page=s3-auto-updater&s3u_success=settings_saved' ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Upload
     * ----------------------------------------------------------------*/

    /**
     * Render the upload form.
     */
    private function render_upload_form() {
        ?>
        <h2>Upload package</h2>
        <p>Upload a plugin or theme zip file to your S3 bucket.</p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 's3_updater_upload' ); ?>
            <input type="hidden" name="action" value="s3_updater_upload" />

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="s3u_type">Type</label></th>
                    <td>
                        <select name="s3u_type" id="s3u_type">
                            <option value="plugins">Plugin</option>
                            <option value="themes">Theme</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="s3u_slug">Slug</label></th>
                    <td>
                        <input type="text" name="s3u_slug" id="s3u_slug" class="regular-text" required placeholder="e.g. jetengine" />
                        <p class="description">Must match the plugin or theme folder name exactly.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="s3u_version">Version</label></th>
                    <td>
                        <input type="text" name="s3u_version" id="s3u_version" class="regular-text" required placeholder="e.g. 3.8.6.2" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="s3u_file">Zip file</label></th>
                    <td>
                        <input type="file" name="s3u_file" id="s3u_file" accept=".zip" required />
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Upload to S3' ); ?>
        </form>
        <?php
    }

    /**
     * Handle file upload.
     */
    public function handle_upload() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        check_admin_referer( 's3_updater_upload' );

        $redirect_url = admin_url( 'tools.php?page=s3-auto-updater' );

        if ( ! $this->client ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'not_configured', $redirect_url ) );
            exit;
        }

        // Validate file upload.
        if ( empty( $_FILES['s3u_file'] ) || $_FILES['s3u_file']['error'] !== UPLOAD_ERR_OK ) {
            $error_code = isset( $_FILES['s3u_file']['error'] ) ? $_FILES['s3u_file']['error'] : 'unknown';
            wp_safe_redirect( add_query_arg( 's3u_error', 'upload_failed_' . $error_code, $redirect_url ) );
            exit;
        }

        $type    = isset( $_POST['s3u_type'] ) && $_POST['s3u_type'] === 'themes' ? 'themes' : 'plugins';
        $slug    = isset( $_POST['s3u_slug'] ) ? sanitize_title( $_POST['s3u_slug'] ) : '';
        $version = isset( $_POST['s3u_version'] ) ? preg_replace( '/[^0-9.]/', '', $_POST['s3u_version'] ) : '';
        $tmppath = $_FILES['s3u_file']['tmp_name'];

        if ( empty( $slug ) ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'missing_slug', $redirect_url ) );
            exit;
        }

        if ( empty( $version ) ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'missing_version', $redirect_url ) );
            exit;
        }

        // Validate .zip extension.
        $original_name = basename( $_FILES['s3u_file']['name'] );
        if ( strtolower( substr( $original_name, -4 ) ) !== '.zip' ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'not_zip', $redirect_url ) );
            exit;
        }

        // Build the S3 key: e.g. plugins/jetengine---3.8.6.2.zip
        $filename = $slug . $this->delimiter . $version . '.zip';
        $key      = $type . '/' . $filename;
        $result   = $this->client->upload( $key, $tmppath );

        if ( is_wp_error( $result ) ) {
            error_log( 'S3 Auto Updater upload error: ' . $result->get_error_message() );
            wp_safe_redirect( add_query_arg( 's3u_error', 's3_error', $redirect_url ) );
            exit;
        }

        // Clear the cached S3 listing so the new package is picked up.
        delete_transient( 's3_auto_updater_plugins' );
        delete_transient( 's3_auto_updater_themes' );

        wp_safe_redirect( add_query_arg( array(
            's3u_success' => 'uploaded',
            's3u_slug'    => $slug,
            's3u_version' => $version,
        ), $redirect_url ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Delete
     * ----------------------------------------------------------------*/

    /**
     * Handle package deletion.
     */
    public function handle_delete() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $key = isset( $_GET['s3u_key'] ) ? urldecode( $_GET['s3u_key'] ) : '';

        check_admin_referer( 's3_updater_delete_' . $key );

        $redirect_url = admin_url( 'tools.php?page=s3-auto-updater' );

        if ( ! $this->client ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'not_configured', $redirect_url ) );
            exit;
        }

        if ( empty( $key ) ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'no_key', $redirect_url ) );
            exit;
        }

        if ( 0 !== strpos( $key, 'plugins/' ) && 0 !== strpos( $key, 'themes/' ) ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'invalid_key', $redirect_url ) );
            exit;
        }

        $result = $this->client->delete( $key );

        if ( is_wp_error( $result ) ) {
            error_log( 'S3 Auto Updater delete error: ' . $result->get_error_message() );
            wp_safe_redirect( add_query_arg( 's3u_error', 's3_error', $redirect_url ) );
            exit;
        }

        delete_transient( 's3_auto_updater_plugins' );
        delete_transient( 's3_auto_updater_themes' );

        wp_safe_redirect( add_query_arg( 's3u_success', 'deleted', $redirect_url ) );
        exit;
    }

    /* ------------------------------------------------------------------
     * Rendering helpers
     * ----------------------------------------------------------------*/

    /**
     * Display admin notices based on query params.
     */
    private function show_admin_notices() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['s3u_success'] ) ) {
            $msg = '';
            if ( 'uploaded' === $_GET['s3u_success'] ) {
                $slug    = isset( $_GET['s3u_slug'] ) ? sanitize_text_field( $_GET['s3u_slug'] ) : '';
                $version = isset( $_GET['s3u_version'] ) ? sanitize_text_field( $_GET['s3u_version'] ) : '';
                $msg     = sprintf( 'Uploaded <strong>%s</strong> version <strong>%s</strong> to S3.', esc_html( $slug ), esc_html( $version ) );
            } elseif ( 'deleted' === $_GET['s3u_success'] ) {
                $msg = 'Package deleted from S3.';
            } elseif ( 'settings_saved' === $_GET['s3u_success'] ) {
                $msg = 'Credentials saved. Reload the page to connect to S3.';
            }
            if ( $msg ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            }
        }

        if ( isset( $_GET['s3u_error'] ) ) {
            $errors = array(
                'not_zip'         => 'The file must be a .zip archive.',
                'missing_slug'    => 'Please enter a slug.',
                'missing_version' => 'Please enter a version number.',
                'not_configured'  => 'S3 credentials are not configured.',
                's3_error'        => 'Failed to communicate with S3. Check the PHP error log for details.',
                'no_key'          => 'No package key specified.',
                'invalid_key'     => 'Invalid package key.',
            );
            $code = sanitize_text_field( $_GET['s3u_error'] );
            $msg  = isset( $errors[ $code ] ) ? $errors[ $code ] : 'Operation failed (error: ' . esc_html( $code ) . ').';
            echo '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>';
        }
        // phpcs:enable
    }

    /**
     * Render a table of packages.
     *
     * @param array  $items Array of [ 'key', 'slug', 'version' ].
     * @param string $type  'plugins' or 'themes'.
     */
    private function render_items_table( $items, $type ) {
        if ( empty( $items ) ) {
            echo '<p>No ' . esc_html( $type ) . ' found in the bucket.</p>';
            return;
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Slug</th><th>Version</th><th>Filename</th><th style="width:100px;">Actions</th></tr></thead>';
        echo '<tbody>';

        foreach ( $items as $item ) {
            $delete_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=s3_updater_delete&s3u_key=' . urlencode( $item['key'] ) ),
                's3_updater_delete_' . $item['key']
            );

            printf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td><code>%s</code></td>'
                . '<td><a href="%s" class="button button-small" onclick="return confirm(\'Delete %s from S3?\');">Delete</a></td></tr>',
                esc_html( $item['slug'] ),
                esc_html( $item['version'] ),
                esc_html( basename( $item['key'] ) ),
                esc_url( $delete_url ),
                esc_js( basename( $item['key'] ) )
            );
        }

        echo '</tbody></table>';
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------*/

    /**
     * Get parsed items from the S3 bucket.
     *
     * @param  string $type 'plugins' or 'themes'.
     * @return array        Array of [ 'key', 'slug', 'version' ].
     */
    private function get_bucket_items( $type ) {
        if ( ! $this->client ) {
            return array();
        }

        $prefix  = $type . '/';
        $objects = $this->client->list_objects( $prefix );

        if ( is_wp_error( $objects ) ) {
            return array();
        }

        $items = array();
        foreach ( $objects as $key ) {
            $filename = basename( $key );
            if ( strtolower( substr( $filename, -4 ) ) !== '.zip' ) {
                continue;
            }
            $parsed = $this->parse_filename( $filename );
            if ( ! $parsed ) {
                continue;
            }
            $items[] = array(
                'key'     => $key,
                'slug'    => $parsed['slug'],
                'version' => $parsed['version'],
            );
        }

        usort( $items, function ( $a, $b ) {
            $slug_cmp = strcmp( $a['slug'], $b['slug'] );
            if ( 0 !== $slug_cmp ) {
                return $slug_cmp;
            }
            return version_compare( $b['version'], $a['version'] );
        } );

        return $items;
    }

    /**
     * Parse a filename like 'my-plugin---1.2.0.zip' into slug and version.
     *
     * @param  string     $filename
     * @return array|null [ 'slug' => '...', 'version' => '...' ] or null.
     */
    private function parse_filename( $filename ) {
        $name  = substr( $filename, 0, -4 );
        $parts = explode( $this->delimiter, $name, 2 );

        if ( count( $parts ) !== 2 || '' === $parts[0] || '' === $parts[1] ) {
            return null;
        }

        return array(
            'slug'    => $parts[0],
            'version' => $parts[1],
        );
    }
}
