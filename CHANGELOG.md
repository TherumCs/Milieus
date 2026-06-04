# Milieus by Therum — Changelog

## [1.0.0] — 2026-05-22

Initial release.

### Roles
- Capability-bundle authoring — pick from preset bundles (subscriber-plus, contributor-plus, editor-plus, shop-manager-plus) or compose from individual capabilities
- Per-role color tag, label override, and description
- Admin page at **Users → Milieus** with grid view of current roles

### WooCommerce role-based pricing
- Optional discount percentage per role, automatically deducted at checkout
- Applies to cart + checkout totals; honors WC tax + coupons
- Gated on WooCommerce being active
