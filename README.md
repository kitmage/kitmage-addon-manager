# Kitmage Add-on Manager

Kitmage Add-on Manager lets a customer purchase a product restricted by
WooCommerce Memberships when their cart already contains an approved qualifying
product. It is intended for stores that sell membership products and separate
add-ons in the same order.

## How it works

Each rule connects three pieces of information:

1. A **product tag** identifying one or more restricted add-ons.
2. One or more **qualifying product IDs** that unlock those add-ons while they
   are in the cart.
3. An optional **restriction message** shown when the add-on is still locked.

An add-on becomes purchasable only when all of the following are true:

- WooCommerce Memberships reports that purchasing the product is restricted.
- The product, or the parent of a variation, has a tag used by a configured
  rule.
- At least one qualifying product from that rule is currently in the cart.

The plugin does not grant a membership or permanently change Memberships
permissions. It allows the restricted product to be purchased for the current
cart request. Existing Memberships access continues to work normally.

## Requirements

- A WordPress installation capable of running the installed WooCommerce version.
- [WooCommerce](https://woocommerce.com/products/woocommerce/) (required).
- [WooCommerce Memberships](https://woocommerce.com/products/woocommerce-memberships/)
  (required for purchase restrictions and the intended behavior).
- WooCommerce Subscriptions is optional; subscription product purchasability is
  supported when it is installed.
- Elementor is optional; the plugin includes a fallback for Elementor's
  WooCommerce product add-to-cart widget.

## Installation

1. Place this repository in a directory such as
   `wp-content/plugins/kitmage-addon-manager/`, or create a ZIP containing the
   plugin directory and upload it through **Plugins > Add New > Upload Plugin**.
2. Confirm that `kitmage-addon-manager.php` is directly inside the plugin
   directory.
3. Install and activate WooCommerce and WooCommerce Memberships.
4. Activate **Kitmage Add-on Manager** from the WordPress Plugins screen.
5. Configure the first rule under **WooCommerce > Add-on Manager**.

### Upgrading from Aspen Add-on Manager

The Kitmage release uses new option names, so an existing Aspen configuration
must be migrated. Back up the database, deactivate the Aspen plugin, and follow
[`migration-instructions.md`](migration-instructions.md) before activating this
plugin. The migration document includes the SQL statements, custom table-prefix
guidance, a verification query, and duplicate-key recovery guidance.

## Configuration

### 1. Prepare the restricted add-on

1. Create or edit the add-on product in WooCommerce.
2. Assign a product tag that identifies its add-on group. A variation inherits
   matching behavior from its variable parent, so the tag can be assigned to the
   parent product.
3. Configure a WooCommerce Memberships plan or restriction rule so purchasing
   the add-on is restricted. Kitmage Add-on Manager only overrides a restriction
   that Memberships actually reports.

### 2. Find the qualifying product IDs

Use the numeric WooCommerce product IDs for products that should unlock the
add-on. The ID is visible in **Products > All Products** when hovering over a
product. Enter multiple IDs separated by commas or whitespace.

For variations, the plugin checks both the cart item's parent product ID and its
variation ID. Use whichever ID represents the exact purchase that should
qualify; using the parent ID allows all of its variations to qualify.

### 3. Add a rule

Go to **WooCommerce > Add-on Manager**, then complete:

- **Restricted add-on product tag:** the tag assigned to the restricted add-on.
- **Qualifying product IDs:** one or more numeric product IDs, for example
  `123, 456`.
- **Restriction message HTML:** optional, sanitized HTML displayed on the
  restricted product page when no qualifying product is in the cart. If left
  empty, the plugin uses its default message.

Select **Add Rule** to configure another tag/product relationship, or **Remove**
to delete a row, and then select **Save Rules**. A rule without both a product
tag and at least one valid product ID is not saved.

### Example

Suppose product `100` is a membership, and every add-on tagged `member-addon`
should be available when that membership is in the cart:

| Setting | Value |
| --- | --- |
| Restricted add-on product tag | `member-addon` |
| Qualifying product IDs | `100` |
| Restriction message HTML | `Add the membership to your cart to buy this add-on.` |

When product `100` is in the cart, restricted products with the selected tag
become purchasable. Without product `100`, their Memberships restriction remains
in place and the configured message is displayed.

## Rule behavior

- A tag can be used in more than one rule.
- A rule can contain multiple qualifying IDs; any one matching cart item is
  sufficient.
- If multiple rules match an add-on, a qualifying product from any matching rule
  unlocks it.
- The first non-empty message among matching rules is displayed.
- Simple products and subscription products receive a native add-to-cart form
  fallback when a theme or page builder suppresses the normal form.
- Variable products use WooCommerce's normal variation add-to-cart template.
- Out-of-stock products are not made available by the fallback form.

## Debug logging

Enable **Debug logging to WooCommerce > Status > Logs** on the settings page,
reproduce the problem, then open **WooCommerce > Status > Logs** and select the
log whose source is `kitmage-addon-manager`.

Logs include product IDs and the result of restriction, rule, cart, and
purchasability checks. Disable logging after troubleshooting to avoid unnecessary
log volume. If the WooCommerce logger is unavailable and WordPress `WP_DEBUG` is
enabled, messages are sent to the standard PHP error log instead.

## Troubleshooting

### A restricted add-on does not become purchasable

Check that:

1. WooCommerce Memberships is active and identifies the add-on as
   purchase-restricted.
2. The selected product tag is assigned to the add-on or its variable parent.
3. The configured qualifying ID matches the product or variation currently in
   the cart.
4. The rule was saved and still appears under **WooCommerce > Add-on Manager**.
5. The add-on is in stock and otherwise purchasable under WooCommerce rules.

Enable debug logging and review the `restricted`, `has_rule`, `cart_matches`, and
`eligible` values in the eligibility entry to identify which condition failed.

### The add-to-cart button is missing

The plugin restores WooCommerce's normal add-to-cart template for eligible
products and includes a fallback for simple products, subscriptions, and the
Elementor WooCommerce add-to-cart widget. If a custom theme or builder still
hides the button, test with a standard WooCommerce-compatible theme and inspect
the debug log for fallback entries.

### Settings disappeared after upgrading from Aspen

Do not recreate the rules until checking the database. Follow
[`migration-instructions.md`](migration-instructions.md) to rename the two Aspen
options and verify the resulting Kitmage records.

### Logs do not appear

Confirm that debug logging is enabled and that a relevant product page has been
requested. In WooCommerce's log viewer, choose the file/source beginning with
`kitmage-addon-manager`. Also check that WooCommerce can write to its configured
log directory.

## Stored data and security

The plugin stores only these options in the WordPress options table:

- `kitmage_addon_manager_rules` — sanitized tag IDs, qualifying product IDs, and
  restriction-message HTML.
- `kitmage_addon_manager_debug` — `yes` or `no` for debug logging.

Settings can only be changed by a user with the `manage_woocommerce` capability.
The settings request is protected by a WordPress nonce. Product IDs are reduced
to positive integers, and restriction-message HTML is sanitized with the
WordPress allowed-post-HTML rules.

The plugin does not currently delete its options on deactivation or removal.
This preserves configuration for reinstalls; remove the two option rows manually
if permanent deletion is required.

## Developer notes

- Main plugin file: `kitmage-addon-manager.php`
- PHP class: `Kitmage_Addon_Manager`
- Text domain and WooCommerce log source: `kitmage-addon-manager`
- Admin page slug: `kitmage-addon-manager`
- Restriction message CSS class:
  `.kitmage-addon-manager-restriction-message`

The plugin integrates through WooCommerce purchasability filters, WooCommerce
Memberships capability filters, standard single-product actions, the WordPress
content filter, and Elementor's widget-render-content filter. No build step is
required.

For a basic syntax check during development, run:

```bash
php -l kitmage-addon-manager.php
```

## Support

Kitmage Add-on Manager is maintained by [Mike@KitMage](http://kitmage.com).
When reporting an issue, include the WordPress, WooCommerce, and Memberships
versions; the affected product type; sanitized rule configuration; and relevant
`kitmage-addon-manager` log entries.
