=== S3 Auto Updater ===

Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
License: GPL-2.0-or-later

Automatically updates custom plugins and themes from an Amazon S3 bucket.


== Description ==

S3 Auto Updater allows you to host your custom plugin and theme zip files
in an Amazon S3 bucket and have WordPress automatically detect and install
new versions – just like plugins from WordPress.org.

No manifest file to maintain. Simply upload a zip using the naming
convention below and your WordPress sites will pick up the update.


== Setup ==

1. Create an S3 bucket for your update packages.

2. Create an IAM user with a policy restricted to your bucket:

   {
       "Version": "2012-10-17",
       "Statement": [
           {
               "Effect": "Allow",
               "Action": [
                   "s3:ListBucket"
               ],
               "Resource": "arn:aws:s3:::your-bucket-name"
           },
           {
               "Effect": "Allow",
               "Action": [
                   "s3:GetObject"
               ],
               "Resource": "arn:aws:s3:::your-bucket-name/*"
           }
       ]
   }

3. Add the following constants to wp-config.php:

   define('S3_UPDATER_BUCKET', 'your-bucket-name');
   define('S3_UPDATER_REGION', 'ap-southeast-1');
   define('S3_UPDATER_KEY',    'AKIA...');
   define('S3_UPDATER_SECRET', 'your-secret-key');

4. Install and activate the S3 Auto Updater plugin.


== Naming Convention ==

Upload zip files to the bucket using triple-hyphen (---) to separate
the slug from the version number:

   plugins/{slug}---{version}.zip
   themes/{slug}---{version}.zip

Examples:

   plugins/jetengine---3.8.6.2.zip
   plugins/jetwoo-widgets-for-elementor---2.1.0.zip
   themes/bricks---2.3.1.zip

Rules:
- The slug (before ---) MUST match the installed plugin/theme directory
  name in wp-content/plugins/ or wp-content/themes/.
- The version MUST follow a format compatible with PHP's version_compare().
- The zip MUST contain a single top-level directory matching the slug.
  For example, jetengine---3.8.6.2.zip must extract to jetengine/.
- If multiple versions of the same slug exist in the bucket, only the
  highest version is offered as an update.


== How It Works ==

Every 12 hours (matching WordPress's default update check schedule),
the plugin lists objects in your S3 bucket under the plugins/ and themes/
prefixes. It parses the filenames, compares versions against what is
installed, and injects update entries into the WordPress update system.

Updates then appear in Dashboard > Updates and in the Plugins/Themes
screens just like any other update. Auto-updates work normally if enabled.

To force a check, go to Dashboard > Updates and click "Check again".
This also clears the S3 Auto Updater cache.


== Troubleshooting ==

- If updates are not appearing, check the PHP error log for messages
  prefixed with "S3 Auto Updater:".
- Confirm that the zip filename slug matches the installed directory name
  exactly (case-sensitive).
- Confirm that the IAM user has both s3:ListBucket and s3:GetObject
  permissions on the correct bucket.
- Confirm that the S3_UPDATER_REGION matches your bucket's actual region.


== Changelog ==

= 1.0.0 =
* Initial release.
