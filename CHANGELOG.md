# Milieus by Therum — Changelog

## [1.1.0] — 2026-06-04

### Reframed as Member Groups
- Renamed user-facing strings from "roles" to "groups" — same WordPress
  roles under the hood, but the UI now matches how people actually think
  about them (Friends & Family, VIP, beta testers).
- Updated admin page chrome to the stone palette + blue accent.

### Group lifetime + member duration
- Each custom group can be permanent or auto-expire on a date. On expiry,
  the group is deleted and members revert to the site default group.
- Each group has a default member duration. New members get that duration
  on joining; when it elapses, they're removed from the group.
- Daily WP-cron sweep `milieus_expire_sweep` enforces both timelines.

### Custom registration links
- Each group can expose a public URL at `/register/{slug}` that creates a
  WordPress account and assigns the new user to the group automatically.
- Per-group registration page customizer: logo, brand mark, heading,
  welcome message, brand color, button text, extra fields (full name,
  company, phone, referral code, "how did you hear?"), and page background
  (solid color / gradient / image with dim + blur).
- Optional admin approval gate, max-signups cap, post-signup redirect URL.
- Live preview in the editor — type and watch the registration card
  update in real time.

### Members tab
- Each group editor now has a Members section: typeahead search to add
  users, table of current members with joined date / expiry / source
  (manual / link / csv), per-row Remove, and bulk actions (extend +30
  days, reset expiry, remove from group).
- AJAX-driven; no full-page reloads.
- CSV import endpoint accepts a blob of emails and assigns existing users.

### Under the hood
- New: `includes/expiry.php`, `includes/members.php`, `includes/registration.php`
- New user meta keys: `_milieus_assigned_{role}`, `_milieus_expires_{role}`,
  `_milieus_source_{role}` (one per group membership)
- New option fields on each stored group: `expires_at`, `member_duration`,
  `reg` (full customizer config, see roles-engine.php for the shape)
- Rewrite rule for `/register/{slug}` registered on activation; flushed
  on save when the slug or enabled state changes.
- Backward-compatible with v1.0.0 stored data — option key unchanged,
  new fields default safely.

## [1.0.0] — 2026-05-22

Initial release.

### Roles
- Capability-bundle authoring — pick from preset bundles or compose from
  individual capabilities
- Admin page at **Users → Roles** with grid view of current roles

### WooCommerce role-based pricing
- Optional discount percentage per role, automatically deducted at checkout
