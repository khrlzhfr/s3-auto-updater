<?php
/**
 * Admin page for uploading plugin/theme zips to the S3 bucket
 * and managing existing packages.
 *
 * Accessible under Tools > S3 Updater.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Auto_Updater_Upload_Page {

    /** @var S3_Auto_Updater_Client */
    private $client;

    /** @var string Filename delimiter. */
    private $delimiter = '---';

    /**
     * @param S3_Auto_Updater_Client $client
     */
    public function __construct( S3_Auto_Updater_Client $client ) {
        $this->client = $client;
    }

    /**
     * Register hooks.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
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
        $plugins = $this->get_bucket_items( 'plugins' );
        $themes  = $this->get_bucket_items( 'themes' );

        ?>
        <div class="wrap">
            <h1>S3 Auto Updater</h1>

            <?php $this->show_admin_notices(); ?>

            <h2>Upload package</h2>
            <p>
                Upload a plugin or theme zip file to your S3 bucket.<br>
                The filename must follow the convention: <code>{slug}---{version}.zip</code><br>
                Example: <code>jetengine---3.8.6.2.zip</code>
            </p>

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
                        <th scope="row"><label for="s3u_file">Zip file</label></th>
                        <td>
                            <input type="file" name="s3u_file" id="s3u_file" accept=".zip" required />
                            <p class="description">
                                The filename must use <code>---</code> (triple hyphen) to separate the slug from the version.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Upload to S3' ); ?>
            </form>

            <hr />

            <h2>Packages in bucket</h2>

            <h3>Plugins</h3>
            <?php $this->render_items_table( $plugins, 'plugins' ); ?>

            <h3>Themes</h3>
            <?php $this->render_items_table( $themes, 'themes' ); ?>
        </div>
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

        // Validate file upload.
        if ( empty( $_FILES['s3u_file'] ) || $_FILES['s3u_file']['error'] !== UPLOAD_ERR_OK ) {
            $error_code = isset( $_FILES['s3u_file']['error'] ) ? $_FILES['s3u_file']['error'] : 'unknown';
            wp_safe_redirect( add_query_arg( 's3u_error', 'upload_failed_' . $error_code, $redirect_url ) );
            exit;
        }

        $type     = isset( $_POST['s3u_type'] ) && $_POST['s3u_type'] === 'themes' ? 'themes' : 'plugins';
        $tmppath  = $_FILES['s3u_file']['tmp_name'];

        // Sanitise filename manually to preserve the '---' delimiter.
        // WordPress's sanitize_file_name() collapses consecutive hyphens.
        $filename = basename( $_FILES['s3u_file']['name'] );
        $filename = preg_replace( '/[^a-zA-Z0-9._\-]/', '', $filename );

        // Validate .zip extension.
        if ( strtolower( substr( $filename, -4 ) ) !== '.zip' ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'not_zip', $redirect_url ) );
            exit;
        }

        // Validate naming convention.
        $parsed = $this->parse_filename( $filename );
        if ( ! $parsed ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'bad_filename', $redirect_url ) );
            exit;
        }

        // Upload to S3.
        $key    = $type . '/' . $filename;
        $result = $this->client->upload( $key, $tmppath );

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
            's3u_slug'    => $parsed['slug'],
            's3u_version' => $parsed['version'],
        ), $redirect_url ) );
        exit;
    }

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

        if ( empty( $key ) ) {
            wp_safe_redirect( add_query_arg( 's3u_error', 'no_key', $redirect_url ) );
            exit;
        }

        // Validate the key starts with plugins/ or themes/.
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
            }
            if ( $msg ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            }
        }

        if ( isset( $_GET['s3u_error'] ) ) {
            $errors = array(
                'not_zip'      => 'The file must be a .zip archive.',
                'bad_filename' => 'The filename must follow the convention <code>{slug}---{version}.zip</code>.',
                's3_error'     => 'Failed to communicate with S3. Check the PHP error log for details.',
                'no_key'       => 'No package key specified.',
                'invalid_key'  => 'Invalid package key.',
            );
            $code = sanitize_text_field( $_GET['s3u_error'] );
            $msg  = isset( $errors[ $code ] ) ? $errors[ $code ] : 'Upload failed (error: ' . esc_html( $code ) . ').';
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

        // Sort by slug, then by version descending.
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
