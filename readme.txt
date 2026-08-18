=== Shop Stock Controls ===
Contributors: islandboy
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.8
Stable tag: 0.2.0
License: GPLv2 or later

Reorder alerts on the admin dashboard plus per-order purchase limits for
WooCommerce products.

== Description ==

Two inventory guardrails in one small plugin:

**1. "Reorder needed" dashboard widget**

Lists every stock-managed product whose quantity is at or below its reorder
point, with SKU, current stock and threshold, sorted lowest first, with a
count badge in the widget title. Out-of-stock items are highlighted in red.

The reorder point is WooCommerce's own low-stock threshold, so it is already
editable in both places you'd expect:

* Per product: Product data → Inventory → "Low stock threshold"
* Store-wide default: WooCommerce → Settings → Products → Inventory →
  "Low stock threshold"

The list is computed with two plain SQL queries against the product lookup
table (no product objects) and cached for 15 minutes; any stock change or
product edit clears the cache immediately.

**2. Per-order purchase limit**

Caps how many units of any one product a customer can buy in a single order.

* Store-wide default: WooCommerce → Settings → Products → Inventory →
  "Max quantity per order" (blank = no limit)
* Per product override: Product data → Inventory → "Max quantity per order"
  (blank = use store default, 0 = no limit for this product)

Enforced server-side when adding to cart (including ?add-to-cart= URLs and the
Store API), when changing quantities in the cart, and again at cart/checkout
as a safety net. The quantity selectors on product and cart pages are capped
to match. For variable products the limit applies to all variations combined.

Self-updates from GitHub Releases.

== Configuration ==

1. WooCommerce → Settings → Products → Inventory → set "Max quantity per
   order" (and adjust "Low stock threshold" if needed).
2. Optionally override either value on individual products under
   Product data → Inventory.

== Changelog ==

= 0.1.0 =
* Initial release: "Reorder needed" dashboard widget + per-order purchase
  limits with store-wide default and per-product override.
