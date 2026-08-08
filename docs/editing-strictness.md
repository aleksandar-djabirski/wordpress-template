# Editing strictness

How locked-down customer editing is, and how to tighten it per project. The
starter ships a deliberately *loose* default; the dials below let a project
tighten it. All of them are per-project decisions — none is active out of
the box. Which dial a project picks is a launch gate: choose and record it
before go-live (see `ops/launch-checklist.md`).

## The default editing model

Customers hold `client_editor` (or `client_shop_manager`) and use the approved
visual Site Editor surfaces:

- Customers compose pages **and edit templates, template parts, navigation and
  Global Styles** in the Site Editor.
- The insertable block set is derived from the registered blocks filtered to
  `core/`, `agency/` and `woocommerce/`; `core/html`, `core/shortcode` and
  `core/freeform` are permanently denied. `canLockBlocks = true`,
  `codeEditingEnabled = false`.
- The same policy is enforced **server side on save** through
  `rest_pre_insert_*`, which also rejects registered shortcode tags anywhere in
  the content and per-block custom CSS. Saves are rejected with a REST error,
  never silently stripped.

What the default does **not** do is validate the *block tree* a customer
assembles server-side — a customer can still arrange the allowed blocks into
layouts you might not have intended. That is deliberate: **spec §14 defers
server-side block-tree validation — which blocks may nest inside which — until
real customer behavior proves it is needed**, rather than building a validator
nobody has shown is needed. The dials below are the sanctioned way to tighten
things when a specific project does need more.

## Dial 1 — trim the block set

For a project that needs a smaller insertable set, use the
`agency_platform_allowed_block_namespaces`, `agency_platform_allowed_blocks`
and `agency_platform_disallowed_blocks` filters. For example:

```php
add_filter(
	'agency_platform_disallowed_blocks',
	array( SiteCore\Editor\BlockDials::class, 'deny_layout_blocks' )
);
```

The callback must be a named method; `HookOwnershipTest` forbids closures in
production code. `BlockPolicy::ALWAYS_DENIED` cannot be re-opened by any
filter.

## Dial 2 — lock page composition per post type

To stop customers restructuring a page at all — not just restrict which
blocks they can insert — register a fixed `template` and a `template_lock`
for the post type via the `register_post_type_args` filter. With
`'template_lock' => 'contentOnly'` (WordPress 6.0+), the customer can edit
the *content* of each block in the template but cannot add, move, or remove
blocks.

**Per-project example code — not active in the starter.** Place it in a
project layer (e.g. `site-core`), using a named callback (the repo forbids
closures in hooks):

```php
add_filter(
	'register_post_type_args',
	array( \MyProject\Editing\PageComposition::class, 'lock_pages' ),
	10,
	2
);

/**
 * @param array<string, mixed> $args
 * @param string               $post_type
 * @return array<string, mixed>
 */
public static function lock_pages( array $args, string $post_type ): array {
	if ( 'page' !== $post_type ) {
		return $args;
	}

	// Each entry is [ blockName, attributes, innerBlocks ].
	$args['template'] = array(
		array( 'core/heading', array( 'level' => 1 ) ),
		array( 'agency/reference-callout' ),
		array( 'core/paragraph' ),
	);
	$args['template_lock'] = 'contentOnly';

	return $args;
}
```

The `register_post_type_args` filter fires for built-in types (`page`,
`post`) because core registers them through the same code path, so gating on
`$post_type` is how you target one. Use `'all'` instead of `'contentOnly'`
to forbid even content edits inside the locked blocks.

## Dial 3 — drop page capabilities for post-only sites

On a site where customers should manage posts but never pages, stop granting
the page capabilities. `client_editor`'s capability set is computed in
`web/app/mu-plugins/agency-platform/src/Roles/RolesProvider.php` as core's
`editor` role minus everything in `RolesProvider::NEVER_GRANT`; add
`'edit_pages'` and `'publish_pages'` (and their siblings
`'edit_published_pages'`, `'delete_pages'`, `'edit_others_pages'` if you want
a clean sweep) to `NEVER_GRANT`, and they are stripped on the next `init`
re-sync — including from `client_shop_manager`, which builds on the same
baseline.

## Dial 4 — tighten the admin-screen boundary

Add or remove screens only through `AdminScreenPolicy::DENIED_SCREENS`. The
theme-side screens deliberately left open are `site-editor.php` and
`font-library.php`; the latter is the Site Editor's own font surface.

## Commerce role dial

Only relevant with the commerce profile active.
`client_shop_manager` — registered by
`web/app/mu-plugins/agency-platform/src/Roles/ShopRole.php` only when
WooCommerce is present — is `client_editor` plus a workflow-complete set of
WooCommerce catalogue/order/coupon capabilities (the full product lifecycle
including editing/deleting published products, product-term assignment, orders,
and coupons). Because it builds on the `client_editor` baseline
(`ShopRole::capabilities()` starts from
`RolesProvider::client_editor_capabilities()`), it inherits `edit_theme_options`
and with it the same Site Editor access — templates, template parts,
navigation and Global Styles — bounded by the same block policy and
admin-screen boundary. Dropping the Site Editor for shop managers is a
per-project dial with a named cost: `edit_theme_options` is granted in
`RolesProvider::ALWAYS_GRANT`, which both roles share, so removing it strips
the Site Editor from that role wholesale (there is no per-role lever today),
and both roles are re-synced from their computed sets on every `init`. One of
them is `manage_woocommerce`, which (matching core's own
`shop_manager`) grants access to **WooCommerce → Settings** and **Status**; a
shop manager reaching Settings is pinned live by
`tests/commerce/e2e/shop-manager-admin.spec.ts`. To lock Settings down for a
project, drop `'manage_woocommerce'` from `ShopRole::WOOCOMMERCE_CAPABILITIES`
(and update the mirror list in `ShopManagerCapabilitiesTest`, which asserts the
exact capability set). The tradeoff is deliberately narrow: you lose the
WooCommerce **Settings**, **Status**, and **Status → Tools** screens (all
`manage_woocommerce`-gated) — but NOT reporting. The role also holds
`view_woocommerce_reports`, which gates the **Analytics** dashboard on its own
top-level menu, so insights stay available; and product, order, and coupon
management ride on their own caps (`edit_products`, `edit_shop_orders`,
`edit_shop_coupons`), so day-to-day catalogue and order work is unaffected.
Going the other way, a project that wants a *tighter* catalogue can trim the
destructive/taxonomy caps (`delete_products`, `delete_published_products`,
`manage_product_terms`, `edit_product_terms`) from the same list — leaving a
shop manager who edits products and prices but cannot delete catalogue entries
or restructure categories. Record the choice on the launch checklist
(`ops/launch-checklist.md`).

## Why the default is looser

One line, restated: the block policy is enforced in the inserter and on REST
saves. **Spec §14 defers server-side block-tree validation** — which blocks may
nest inside which — until real customer behavior proves it is needed. The
starter ships the policy and lock flags, and leaves the dials above for the
projects that need them.
