<?php
/**
 * Plugin Name: S3 Auto Updater
 * Plugin URI:  https://github.com/khrlzhfr/s3-auto-updater
 * Description: Automatically updates custom plugins and themes from an Amazon S3 bucket.
 * Version:     1.3.1
 * Author:      Khairil Zhafri
 * Requires PHP: 7.4
 * Requires at least: 5.5
 * License:     GPL-2.0-or-later
 *
 * Credentials can be set in either wp-config.php (takes priority) or
 * in Tools > S3 Updater in the WordPress admin.
 *
 * wp-config.php constants:
 *
 *   define('S3_UPDATER_BUCKET', 'your-bucket-name');
 *   define('S3_UPDATER_REGION', 'ap-southeast-1');
 *   define('S3_UPDATER_KEY',    'AKIA...');
 *   define('S3_UPDATER_SECRET', 'your-secret-key');
 *
 * Upload your plugin and theme zips to the bucket:
 *
 *   plugins/my-plugin---1.2.0.zip
 *   themes/my-theme---3.1.0.zip
 *
 * The slug (before ---) must match the installed plugin/theme folder name.
 * The zip must contain a top-level directory matching the slug.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-s3-client.php';
require_once __DIR__ . '/includes/class-updater.php';
require_once __DIR__ . '/includes/class-upload-page.php';

$s3_updater_settings = new S3_Auto_Updater_Settings();

/**
 * Always register the Tools page so credentials can be entered
 * even when they are not yet configured.
 */
$s3_updater_client = null;

if ( $s3_updater_settings->is_configured() ) {

    $s3_updater_client = new S3_Auto_Updater_Client(
        $s3_updater_settings->get( 'bucket' ),
        $s3_updater_settings->get( 'region' ),
        $s3_updater_settings->get( 'key' ),
        $s3_updater_settings->get( 'secret' )
    );

    $s3_updater = new S3_Auto_Updater_Updater(
        $s3_updater_client,
        $s3_updater_settings->get( 'bucket' ),
        $s3_updater_settings->get( 'region' )
    );
    $s3_updater->init();

} else {

    add_action( 'admin_notices', function () {
        $settings_url = admin_url( 'tools.php?page=s3-auto-updater' );
        printf(
            '<div class="notice notice-warning"><p><strong>S3 Auto Updater:</strong> Credentials are not fully configured. '
            . 'Set them in <code>wp-config.php</code> or in <a href="%s">Tools &gt; S3 Updater</a>.</p></div>',
            esc_url( $settings_url )
        );
    });

}

$s3_upload_page = new S3_Auto_Updater_Upload_Page( $s3_updater_settings, $s3_updater_client );
$s3_upload_page->init();
