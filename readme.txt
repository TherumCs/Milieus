=== Milieus by Therum ===
Contributors: therumstudios
Tags: roles, capabilities, user management, woocommerce, pricing
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.4.0
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

= 1.4.0 =
* **All Members directory** — new page at Milieus → All Members showing every user on the site with their group memberships as color-coded tags. Search by name/email, filter by group, sort by name/joined/email, paginated at 50/page. Shows avatar, group tags, joined date, expiry status (color-coded urgent/soon), source, and WP role.

= 1.3.2 =
* Security: whitelist gradient direction values in registration page background to prevent CSS injection.
* Security: sanitize solid/gradient color values with strict character allowlist.
* Security: add `esc_attr()` on audit filter user input in HTML attribute context.
* Performance: cache `count_users()` per request — fixes N+1 full-table scan in REST API and shortcodes.
* Performance: cap CSV member export to 10,000 rows to prevent unbounded memory usage.

= 1.3.1 =
* Fix: welcome screen no longer shows for approval-gated signups (user isn't logged in yet, so "you're in!" would be incorrect).
* Fix: audit log source attribution uses `doing_action()` instead of `did_action()` — admin-initiated revokes after a cron run in the same request are no longer misattributed as 'cron'.
* Fix: approval badge count query used wrong WP_User_Query parameter (`compare` → `meta_query`), causing incorrect badge numbers.
* Fix: audit log CSV export form was nested inside the filter form (invalid HTML), breaking the export button in most browsers.
* Fix: Brain Monkey test bootstrap no longer leaks setUp state across tests.

= 1.3.0 =
* Welcome screen between sign-up and redirect (branded "you're in" page with benefit list).
* Gutenberg block: Milieus Registration Form, with live editor preview.
* Starter packs — Friends & Family / VIP / Beta Testers / 14-Day Trial preset templates.
* First-activation onboarding notice with deep-link to the F&F starter.
* Plugin row meta: Settings, Groups, Docs, Support.
* Inline help (?) tooltips on key fields.
* Toast notifications (replaces inline save/error text).
* PHPUnit smoke tests + composer config + GitHub Actions CI (PHP 8.0–8.3).
* PHPCS ruleset (WordPress-Extra + WP i18n + PHP 8 compatibility).
* Proper README with features, install, REST/webhook docs, architecture map.

= 1.2.1 =
* HPOS + cart_checkout_blocks compatibility declaration.
* Spam protection on /register/{slug} — honeypot + per-IP rate limit (5/hour, filterable).
* Activation safety — audit-table install failure surfaces a dismissible admin notice instead of crashing.
* Recurring memberships via WooCommerce Subscriptions — group binding follows subscription lifecycle; expiry pins to next-payment.
* Translation .pot template + load_plugin_textdomain.

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
