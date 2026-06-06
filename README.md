# Milieus by Therum

**Member groups for WordPress.** Bundle users into named groups — Friends & Family, VIP, Beta Testers, Trial — each with their own capabilities, optional WooCommerce discount, expiry, branded registration link, and members list.

[![CI](https://github.com/TherumCs/Milieus/actions/workflows/ci.yml/badge.svg)](https://github.com/TherumCs/Milieus/actions)
[![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-blue)](LICENSE)

---

## Features

- **Custom groups** — build roles from preset capability bundles (Read, Write, Publish, Edit any, Settings, Shop customer, Shop manager) plus any individual WP caps.
- **Group lifetime** — make a group permanent, or have it auto-delete on a date (members revert to your default group).
- **Member duration** — set a default expiry per group (e.g. trial = 14 days). Per-user expiry is tracked; a daily cron sweep enforces it.
- **Custom registration links** — `/register/{slug}` gives each group its own branded sign-up card: logo, brand mark, heading, message, brand color, button text, extra fields (Full name, Company, Phone, Referral, "How did you hear?"), page background (solid / gradient / image).
- **Branded login** — matching `/login/{slug}` with the same design.
- **Welcome screen** — optional one-screen "you're in" between sign-up and redirect, with benefit list.
- **Members tab** — per-group typeahead user search, member table with joined date / expiry / source (manual / link / csv / purchase / subscription / api), bulk extend / reset / remove, CSV import + export.
- **Approval gate** — optional admin approval before new sign-ups are added; inbox under Milieus → Approvals.
- **Email notifications** — branded welcome, admin signup alert, expiry reminders, approval requests. Settings under Milieus → Settings.
- **WooCommerce auto-group on purchase** — attach a group to any product; buying it auto-assigns the customer. Refunds revoke.
- **WooCommerce Subscriptions** — recurring memberships; member expiry pins to next-payment so the cron sweep won't yank paying members.
- **WC role-based pricing** — per-group % discount applied at checkout.
- **Shortcodes** — `[milieus_register]`, `[milieus_login]`, `[milieus_member_status]`, `[milieus_member_count]`.
- **Gutenberg block** — `Milieus Registration Form` with live editor preview.
- **REST API** — `/wp-json/milieus/v1/groups[…]` for programmatic member management. Standard WP auth (Application Passwords).
- **Outbound webhooks** — HMAC-SHA256-signed POSTs on member events. Zapier / Make / n8n compatible.
- **Audit log** — append-only history of every membership change with filters + CSV export.
- **Dashboard widget** — recent sign-ups, expiring this week, pending approvals.
- **Updates page** — read latest GitHub release, apply ZIP from disk, rollback to any prior version.
- **Anti-spam** — honeypot + per-IP rate limit on `/register/{slug}`.
- **HPOS-safe** — declares custom_order_tables + cart_checkout_blocks compatibility.

---

## Install

**From GitHub:**
1. Download [`milieus.zip`](https://github.com/TherumCs/Milieus/raw/main/milieus.zip).
2. In wp-admin → **Plugins → Add New → Upload Plugin** → choose the zip → Activate.

**Via wp-admin self-update (once installed):**
- **Milieus → Updates** → "Apply 1.x.x" when a newer release is published.

---

## Quick start

1. Activate → click "Create your first group →" in the activation notice (or **Milieus → Member Groups → + New group**).
2. Pick a starter pack (Friends & Family / VIP / Beta / Trial) from `?milieus_template=…`, or build from scratch.
3. Pick bundles + caps, set color, expiry, and optional WooCommerce discount.
4. Open **Custom registration link** → toggle on, pick a slug, customize the card.
5. Share `https://yoursite.com/register/{slug}` — visitors sign up and auto-land in the group.

---

## Shortcodes

```
[milieus_register group="friends"]              — inline signup form
[milieus_login group="friends"]                 — inline branded login
[milieus_member_status]                          — current user's groups + expiry
[milieus_member_count group="friends" format="pretty"]  — for social proof
```

---

## REST API

All endpoints require `manage_options`. Auth via [Application Passwords](https://wordpress.org/documentation/article/application-passwords/) is recommended.

```
GET    /wp-json/milieus/v1/groups
GET    /wp-json/milieus/v1/groups/{key}
GET    /wp-json/milieus/v1/groups/{key}/members?page=1&search=foo
POST   /wp-json/milieus/v1/groups/{key}/members        body: { user_id|email, source? }
DELETE /wp-json/milieus/v1/groups/{key}/members/{user_id}
POST   /wp-json/milieus/v1/groups/{key}/members/{user_id}/extend   body: { seconds }
```

Example:

```bash
curl -u admin:xxxx-xxxx-xxxx-xxxx \
  https://yoursite.com/wp-json/milieus/v1/groups/friends/members?search=ada
```

---

## Webhooks

Configure under **Milieus → Webhooks**. Each webhook fires for the events it subscribes to. Every request includes:

- `X-Milieus-Event` — event name
- `X-Milieus-Signature` — HMAC-SHA256 of the raw body using your secret
- `Content-Type: application/json`

Events: `member.assigned`, `member.revoked`, `member.pending`, `member.expiring_soon`, `purchase.confirmed`.

Payload shape:

```json
{
  "event":     "member.assigned",
  "timestamp": 1717612345,
  "site":      "https://example.com",
  "data":      { "user_id": 42, "group": "friends", "source": "link", "is_new": true }
}
```

Verify with Node, Python, PHP — same shape as Stripe/GitHub, so existing libraries work.

---

## Filters & actions

```php
apply_filters( 'milieus_capability_bundles',   $bundles );  // modify/extend preset bundles
apply_filters( 'milieus_reserved_roles',       $roles );    // roles Milieus refuses to overwrite
apply_filters( 'milieus_skip_spam_check',      false );     // bypass honeypot + rate limit
apply_filters( 'milieus_signup_rate_per_hour', 5 );         // tune per-IP cap
apply_filters( 'milieus_expiry_reminder_days', 3 );         // days before expiry to remind

do_action( 'milieus_member_assigned',      $uid, $key, $source, $is_new );
do_action( 'milieus_member_revoked',       $uid, $key );
do_action( 'milieus_pending_created',      $uid, $key );
do_action( 'milieus_member_expiring_soon', $uid, $key, $expires );
do_action( 'milieus_member_purchased',     $uid, $key, $order_id, $product_id );
do_action( 'milieus_subscription_active',  $uid, $key, $sub_id );
do_action( 'milieus_subscription_ended',   $uid, $key, $sub_id );
```

---

## Development

```bash
composer install
composer test    # PHPUnit smoke tests for the pure data layer
composer lint    # PHPCS with WordPress-Extra + WP i18n + PHP 8 compat
composer fix     # PHPCBF auto-fix
```

A ready-to-use CI workflow lives at [`docs/ci.yml.example`](docs/ci.yml.example) — copy it to `.github/workflows/ci.yml` (requires the `workflow` OAuth scope when committed via API) to run PHPCS + PHPUnit on every push across PHP 8.0–8.3.

### Architecture

```
milieus.php                  — plugin bootstrap, requires, activation/deactivation
includes/
  bundles.php                — preset capability bundles (filterable)
  roles-engine.php           — group data shapes, cap resolver
  expiry.php                 — group + member expiry sweep (daily cron)
  members.php                — member list, search, bulk actions, CSV
  registration.php           — /register/{slug} + /login/{slug}
  welcome.php                — /welcome/{slug} post-signup confirmation
  approvals.php              — pending-user inbox
  ajax.php                   — admin AJAX handlers (save/delete/default group)
  admin-page.php             — Member Groups admin UI
  all-members.php            — All Members directory (cross-group user view)
  admin-caps-heal.php        — restores admin caps on every admin load
  dashboard-widget.php       — wp-admin dashboard summary
  notifications.php          — email senders + Milieus → Settings page
  shortcodes.php             — inline shortcodes
  onboarding.php             — first-activation notice + starter packs + help helper
  block.php                  — Gutenberg block registration
  updates.php                — GitHub release fetch + ZIP upload + rollback
  audit.php                  — append-only history (custom table)
  webhooks.php               — outbound HMAC-signed POSTs
  rest.php                   — /wp-json/milieus/v1/
  wc-pricing.php             — role-based cart discount
  wc-auto-group.php          — auto-assign group on order complete
  wc-subscriptions.php       — recurring memberships via WC Subscriptions
  wc-compat.php              — HPOS + cart-block declaration
  spam.php                   — honeypot + per-IP rate limit
assets/
  admin.css                  — admin chrome (stone palette, blue accent)
  admin.js                   — group editor, members tab, toasts
  block.js                   — Gutenberg block editor script
languages/milieus.pot        — translation template
tests/                       — PHPUnit smoke tests
```

---

## Built-in roles are never touched

Milieus refuses to overwrite or delete any of `administrator`, `editor`, `author`, `contributor`, `subscriber`, `customer`, or `shop_manager`. Custom groups live alongside them.

## Disabling the admin caps self-heal

Set `MILIEUS_DISABLE_CAPS_HEAL` to `true` in `wp-config.php` before plugins load.

---

## License

GPL-2.0-or-later. Built and maintained by [Therum Creative Studios](https://therum.studio).
