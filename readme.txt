=== Milieus by Therum ===
Contributors: therumstudios
Tags: roles, capabilities, user management, woocommerce, pricing
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom WordPress roles built from capability bundles, with optional WooCommerce role-based pricing.

== Description ==

**Milieus** is a custom-role builder for WordPress. Mix preset **capability bundles** (Read, Write, Publish, Edit any, Settings, Shop customer, Shop manager) with fine-grained individual caps to assemble any role you need. If WooCommerce is active, each role can also carry a percentage **discount** that's automatically applied to that user's cart at checkout.

Built and maintained by **Therum Creative Studios**.

= What it does =

* Adds a **Users → Roles** admin page.
* Build roles from named capability bundles plus any individual WP caps you want to mix in.
* Filter caps by name to find the one you're looking for.
* Set the default role for new registrations from the same screen.
* Restores any missing core administrator capabilities on every admin page load — protects against security plugins or role editors that strip caps and break admin pages.
* **WooCommerce role pricing (optional).** Each custom role can carry a percentage discount that's automatically deducted from the cart subtotal as a negative fee at checkout. Users with multiple discount roles get the largest one. Shown on the My Account dashboard as an informational notice.
* Safe deletion. Removing a custom role reassigns its users to the default role first, so no one is left without a role.
* No external dependencies. WooCommerce is optional — without it, the role builder works the same minus the discount field.

= Built-in roles are never touched =

Milieus refuses to overwrite or delete any of `administrator`, `editor`, `author`, `contributor`, `subscriber`, `customer`, or `shop_manager`. Custom roles you create through Milieus live alongside the built-ins.

= Storage =

Custom role metadata is stored in a single `wp_options` row named `milieus_custom_roles` — one record per role with display name, applied bundles, resolved caps, discount %, and last-updated timestamp. The roles themselves are registered via WordPress's standard `add_role()` so they appear everywhere WP roles do (user editor, REST, etc.).

= Filters =

* `milieus_capability_bundles` — modify or extend the built-in bundles.
* `milieus_reserved_roles` — change the list of roles Milieus refuses to overwrite/delete.

= Disabling the admin-cap self-heal =

If you'd rather Milieus didn't touch the administrator role, define `MILIEUS_DISABLE_CAPS_HEAL` as `true` in `wp-config.php` before WordPress loads plugins.

== Installation ==

1. Upload `milieus-by-therum.zip` via **Plugins → Add New → Upload Plugin**.
2. Activate.
3. Find **Users → Roles** in the wp-admin sidebar.

== Frequently Asked Questions ==

= Will activating this overwrite my existing roles? =

No. Milieus never modifies built-in WordPress or WooCommerce roles. It only creates and manages roles you build through its own UI.

= What happens to users in a custom role if I uninstall? =

`uninstall.php` removes each custom role created through Milieus and reassigns its users to your site's current default role (typically `subscriber`). Built-in roles are left untouched.

= Does the WooCommerce discount stack with other coupons? =

It's applied as a negative cart fee on `woocommerce_cart_calculate_fees`, so it sits alongside coupons rather than combining with them. The discount is calculated from the cart subtotal — same as a standard percentage coupon.

= Why does my administrator role suddenly have caps back after I removed them with another plugin? =

Milieus restores core administrator caps on every admin page load. It's there to prevent third-party tools from accidentally locking you out of wp-admin. To turn it off, set `MILIEUS_DISABLE_CAPS_HEAL` to `true` in `wp-config.php`.

== Changelog ==

= 1.2.0 =
* Approval inbox, CSV import/export UI, sign-up counts, color tags, search/filter, duplicate-group, dashboard widget.
* WooCommerce auto-group on purchase — attach a group to any product; buying it grants the role with optional per-product duration override.
* Email notifications — branded welcomes, admin sign-up alerts, expiry reminders, approval requests. Settings under Milieus → Settings.
* Branded login link at `/login/{slug}` mirroring the registration design.
* Shortcodes: `[milieus_register]`, `[milieus_login]`, `[milieus_member_status]`, `[milieus_member_count]`.
* REST API at `/wp-json/milieus/v1/` for groups + members CRUD.
* Outbound webhooks with HMAC signing for Zapier / Make / n8n.
* Audit log — append-only history with filters and CSV export.
* Updates page now also covers GitHub release fetching, ZIP upload, and rollback (introduced last release; mentioned here for completeness).

= 1.1.0 =
* Reframed as **Member Groups** — same WordPress roles under the hood, but the UI matches how people actually think about them.
* **Group lifetime + member duration**: each group can be permanent or auto-expire on a date. Each new member gets a default duration; daily cron sweep enforces both.
* **Custom registration links**: each group can expose `/register/{slug}` with a fully customizable sign-up card (logo, brand mark, heading, message, color, button text, extra fields, page background). Live preview while editing.
* **Members tab**: per-group typeahead search, member table with joined date / expiry / source, per-row Remove, bulk extend / reset / revoke, CSV import.
* New files: `includes/expiry.php`, `includes/members.php`, `includes/registration.php`.
* Backward-compatible with v1.0.0 stored data.

= 1.0.0 =
* Initial release. Extracted from the Therum OS `therum-auth` roles slice.
