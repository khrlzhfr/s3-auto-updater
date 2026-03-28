<?php
/**
 * Plugin Name: S3 Auto Updater
 * Plugin URI:  https://github.com/khrlzhfr/s3-auto-updater
 * Description: Automatically updates custom plugins and themes from an Amazon S3 bucket.
 * Version:     1.0.0
 * Author:      Khairil Zhafri
 * Requires PHP: 7.4
 * Requires at least: 5.5
 * License:     GPL-2.0-or-later
 *
 * Usage:
 * Add the following constants to your wp-config.php:
 *
 *   define('S3_UPDATER_BUCKET', 'your-bucket-name');
 *   define('S3_UPDATER_REGION', 'ap-southeast-1');
 *   define('S3_UPDATER_KEY',    'AKIA...');
 *   define('S3_UPDATER_SECRET', 'your-secret-key');
 *
 * Then upload your plugin and theme zips to the bucket:
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

/**
 * Check that all required constants are defined.
 */
$s3_updater_required = array(
    'S3_UPDATER_BUCKET',
    'S3_UPDATER_REGION',
    'S3_UPDATER_KEY',
    'S3_UPDATER_SECRET',
);

foreach ( $s3_updater_required as $s3_updater_const ) {
    if ( ! defined( $s3_updater_const ) ) {
        add_action( 'admin_notices', function () use ( $s3_updater_const ) {
            printf(
                '<div class="notice notice-error"><p><strong>S3 Auto Updater:</strong> <code>%s</code> is not defined in wp-config.php.</p></div>',
                esc_html( $s3_updater_const )
            );
        });
        return;
    }
}

require_once __DIR__ . '/includes/class-s3-client.php';
require_once __DIR__ . '/includes/class-updater.php';

$s3_updater_client = new S3_Auto_Updater_Client(
    S3_UPDATER_BUCKET,
    S3_UPDATER_REGION,
    S3_UPDATER_KEY,
    S3_UPDATER_SECRET
);

$s3_updater = new S3_Auto_Updater_Updater( $s3_updater_client );
$s3_updater->init();
