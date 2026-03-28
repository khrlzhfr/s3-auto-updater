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
               "Action": "s3:ListBucket",
               "Resource": "arn:aws:s3:::your-bucket-name"
           },
           {
               "Effect": "Allow",
               "Action": [
                   "s3:GetObject",
                   "s3:PutObject",
                   "s3:DeleteObject"
               ],
               "Resource": "arn:aws:s3:::your-bucket-name/*"
           }
       ]
   }

3. Add your credentials via one of two methods:

   Option A – wp-config.php (recommended for security):

   define('S3_UPDATER_BUCKET', 'your-bucket-name');
   define('S3_UPDATER_REGION', 'ap-southeast-1');
   define('S3_UPDATER_KEY',    'AKIA...');
   define('S3_UPDATER_SECRET', 'your-secret-key');

   Option B – Settings > General in the WordPress admin:

   Scroll down to the "S3 Auto Updater" section and fill in the fields.

   Constants in wp-config.php always take priority. When a constant is
   defined, the corresponding field in Settings is shown as disabled.

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

You can also click the "Update all now" link on the S3 Auto Updater
row in the Plugins page to force-update everything in one go.


== Uploading Packages ==

Go to Tools > S3 Updater to upload zip files directly to your S3
bucket from the WordPress admin. The page also lists all packages
currently in the bucket, with the option to delete old versions.

Alternatively, you can upload files to the bucket directly via the
AWS Console, AWS CLI, or any S3-compatible tool.


== Troubleshooting ==

- If updates are not appearing, check the PHP error log for messages
  prefixed with "S3 Auto Updater:".
- Confirm that the zip filename slug matches the installed directory name
  exactly (case-sensitive).
- Confirm that the IAM user has s3:ListBucket, s3:GetObject, s3:PutObject,
  and s3:DeleteObject permissions on the correct bucket.
- Confirm that the S3_UPDATER_REGION matches your bucket's actual region.


== Changelog ==

= 1.2.0 =
* Added upload page under Tools > S3 Updater for uploading and deleting
  packages directly from the WordPress admin.
* S3 client now supports PutObject and DeleteObject operations.
* IAM policy updated to include s3:PutObject and s3:DeleteObject.

= 1.1.0 =
* Added settings page under Settings > General for entering S3 credentials.
* wp-config.php constants take priority; fields are disabled when defined.
* Added "Update all now" action link on the plugin row.
* Suppressed false update notices from third-party updaters (e.g. Crocoblock)
  for plugins/themes managed by this updater.
* Improved error reporting with detailed failure messages.

= 1.0.0 =
* Initial release.
