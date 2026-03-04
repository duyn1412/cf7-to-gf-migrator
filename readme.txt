=== CF7 to Gravity Forms Migrator ===
Contributors: duyn1412
Tags: contact form 7, gravity forms, migration, migrator, cf7
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate your Contact Form 7 forms to Gravity Forms — including fields, notifications, autoresponders, confirmations, and form entries (via CF7DB).

== Description ==

**CF7 to Gravity Forms Migrator** helps you seamlessly move from Contact Form 7 to Gravity Forms without losing your data.

= What gets migrated =

* **Form fields** — Text, email, phone, textarea, select, checkbox, radio, file upload, date, number, hidden, and more.
* **Admin Notifications** — Recipient, subject, reply-to (mapped to correct Gravity Forms merge tags).
* **Autoresponder (Mail 2)** — If active, migrated as a second GF notification.
* **Confirmations** — Custom message or page redirect (including redirects set by cf7-grid-layout plugin).
* **Form entries** (optional) — Requires the [CF7 to Database Extension](https://wordpress.org/plugins/contact-form-7-to-database-extension/) plugin.

= Features =

* Batch migration — migrate all forms at once with a single click.
* Safe re-migration — re-migrating an existing form updates it without creating duplicates.
* "Used On" column in the CF7 admin list — see which pages/posts use each form.
* Entry migration from CF7DB with skip-already-migrated support.

= Requirements =

* [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) (free) — must be active.
* [Gravity Forms](https://www.gravityforms.com/) (premium) — must be active.
* (Optional) [CF7 to Database Extension](https://wordpress.org/plugins/contact-form-7-to-database-extension/) — required only for entry migration.

== Installation ==

1. Upload the `cf7-to-gf-migrator` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Make sure both **Contact Form 7** and **Gravity Forms** are installed and active.
4. Go to **Forms → CF7 Migrator** in your WordPress admin.
5. Select the forms you want to migrate and click **Migrate**.

== Frequently Asked Questions ==

= Does this plugin work without Gravity Forms? =

No. Gravity Forms is a required dependency. The plugin will show an admin notice if Gravity Forms is not active.

= Will re-migrating overwrite my existing Gravity Forms? =

Yes. Re-migrating an already-migrated form will update the existing GF form (fields, notifications, confirmations). It will not create duplicate forms.

= Can I migrate form entries? =

Yes, if you have the **CF7 to Database Extension** plugin installed. The entry migrator will skip already-migrated entries by default.

= What happens to page redirects configured in cf7-grid-layout? =

Page redirects set via the `cf7-grid-layout` plugin (`_cf7sg_page_redirect` meta) are automatically detected and migrated as GF "page" confirmations.

= Are all CF7 field types supported? =

Most standard CF7 field types are supported: text, email, tel, textarea, select, checkbox, radio, file, url, number, date, hidden, acceptance, password. Custom/third-party CF7 field types will fall back to a text field.

== Screenshots ==

1. Main migration dashboard — list of all CF7 forms with migration status.
2. Batch migrate multiple forms at once.
3. Entry migration panel with CF7DB entry counts.

== Changelog ==

= 1.0.0 =
* Initial release.
* Migrate CF7 forms to Gravity Forms (fields, notifications, confirmations).
* Batch migration support.
* Entry migration from CF7DB.
* "Used On" column in CF7 admin list.
* Support for cf7-grid-layout page redirects.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
