# WooCommerce Template Overrides

Files here override core WooCommerce templates (`wc_locate_template()`
prefers theme files over the plugin's own `templates/`). Overriding is a
last resort: a `woocommerce_*` hook must be ruled out first, since an
override stops tracking upstream template changes silently.

**No overrides yet. The base profile must not add any.** Any future
override belongs to the commerce profile (see `deptrac.yaml`'s WooCommerce
symbol allow-locations) and must be logged below before it is added.

## Override log

| Overridden template | Reason | WC template version | Related tests | Hook-based alternative considered? |
| --- | --- | --- | --- | --- |
| _(none)_ | | | | |
