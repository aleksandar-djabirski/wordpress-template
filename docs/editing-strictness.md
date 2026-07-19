# Editing strictness

How locked-down customer editing is, and how to tighten it per project. The
starter ships a deliberately *loose* default; the dials below let a project
tighten it. All of them are per-project decisions — none is active out of
the box. Which dial a project picks is a launch gate: choose and record it
before go-live (see `ops/launch-checklist.md`).

## The default editing model

Customers hold `client_editor` (or `client_shop_manager`) and can edit
**content only**:

- They compose pages from an allow-list of blocks
  (`EditorRestrictions::ALLOWED_BLOCKS`); every other block, including
  `core/shortcode` and all `woocommerce/*` blocks, is hidden from them.
- Structure and styles are locked: `agency-platform` sets
  `canLockBlocks = false` and `codeEditingEnabled = false`, and the design
  system (colors, fonts, spacing) is fixed in `theme.json` with user-facing
  custom colors/font-sizes disabled.
- Templates, parts, and block/pattern *definitions* live in Git and are
  never editable from wp-admin; `wp agency check-overrides` fails if a
  `wp_template`/`wp_template_part` or customized `wp_global_styles` row
  appears in the database.

What the default does **not** do is validate the *block tree* a customer
assembles server-side — a customer can still arrange the allow-listed blocks
into layouts you might not have intended. That is deliberate: **spec §14
defers server-side block-tree validation until real customer behavior proves
the native editor restrictions insufficient**, rather than building a
validator nobody has shown is needed. The dials below are the sanctioned way
to tighten things when a specific project does need more.

## Dial 1 — trim layout-bearing blocks (content-only customers)

For customers who should place text and media but never touch layout, drop
the layout-bearing blocks from the allow-list in
`web/app/mu-plugins/agency-platform/src/Editor/EditorRestrictions.php`
(`EditorRestrictions::ALLOWED_BLOCKS`). Remove `core/group`, `core/columns`,
`core/column`, `core/buttons`, `core/button`, `core/spacer`, and
`core/separator`, leaving just the content primitives (`core/paragraph`,
`core/heading`, `core/list`/`core/list-item`, `core/image`, `core/gallery`,
`core/quote`, `core/table`) plus your own `agency/*` blocks. The constant is
unit-tested, so the change is caught by the suite if a downstream test still
expects a removed block.

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

## Why the default is looser

One line, restated: **spec §14 defers server-side block-tree validation
until real customer behavior proves the native editor restrictions
insufficient** — so the starter ships the native allow-list and lock flags,
and leaves the dials above for the projects that turn out to need them.
