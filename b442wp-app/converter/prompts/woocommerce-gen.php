<?php
/**
 * WooCommerce template generation prompt.
 *
 * Generates WooCommerce template overrides from actual React source components
 * (Shop.jsx, ProductDetail.jsx, ProductCard.jsx, Cart.jsx, Checkout.jsx).
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

/**
 * Build the user prompt for WooCommerce template generation.
 *
 * @param array  $data             Merged parsed + analyzed data
 * @param array  $woo_sources      Map of component name => source code
 */
function prompt_woocommerce_user(array $data, array $woo_sources): string
{
    $theme_name = $data['theme_name'] ?? 'My Theme';
    $prefix     = $data['prefix']     ?? 'theme';

    // Colors
    $colors_ref = '';
    foreach ($data['colors'] as $key => $val) {
        if ($key === '_all_vars') continue;
        $colors_ref .= "  --color-{$key}: {$val}\n";
    }

    // Custom classes
    $custom_classes_ref = '';
    if (!empty($data['custom_classes'])) {
        $custom_classes_ref = "\n### Custom CSS classes to preserve\n";
        foreach ($data['custom_classes'] as $class => $body) {
            $custom_classes_ref .= "{$class} { {$body} }\n";
        }
    }

    // Build source blocks
    $source_blocks = '';
    foreach ($woo_sources as $name => $source) {
        if (empty($source) || str_starts_with($source, '/*')) continue;
        $source_blocks .= "=== {$name} ===\n```jsx\n{$source}\n```\n\n";
    }

    // Entities
    $entities_ref = '';
    foreach ($data['entities'] ?? [] as $entity) {
        $fields = is_array($entity['fields'] ?? null) ? implode(', ', $entity['fields']) : '';
        $entities_ref .= "- {$entity['name']}: [{$fields}]\n";
    }

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix: {$prefix}

### Design tokens
Colors:
{$colors_ref}
Fonts:
  Primary: {$data['fonts']['primary']}
{$custom_classes_ref}

### Data entities from Base44
{$entities_ref}

---

## React source components

{$source_blocks}

---

## Your task

Generate WooCommerce template overrides that **faithfully replicate the visual design** from the React source components above.

### Files to generate

1. **`woocommerce/archive-product.php`** — Shop page (based on Shop.jsx)
   - Page header with title and description matching the source
   - Product grid: 2 cols mobile, 3 cols tablet, 4 cols desktop (matching source)
   - Include filter/sort UI if present in source (simplified server-side version)
   - Use `woocommerce_before_shop_loop`, `woocommerce_after_shop_loop` hooks

2. **`woocommerce/content-product.php`** — Product card in shop grid (based on ProductCard.jsx)
   - Replicate the EXACT card layout from ProductCard.jsx
   - Product image with aspect-square, hover effects
   - Category label, product name, subtitle/short description
   - Star rating display
   - Price with sale price support
   - Badge overlays (best seller, new, sale)
   - Quick-add-to-cart button on hover
   - Use `wc_get_product()`, `get_the_post_thumbnail()`, etc.

3. **`woocommerce/single-product.php`** — Single product page (based on ProductDetail.jsx)
   - Breadcrumb navigation
   - Two-column layout: image left, details right (on desktop)
   - Category label, product title, subtitle
   - Specs/details section
   - Price display with subscription support if applicable
   - Quantity selector + Add to Cart button matching source design
   - Accordion details (description, ingredients, suggested use, storage)
   - Related products section
   - Use WooCommerce hooks: `woocommerce_before_single_product`, `woocommerce_single_product_summary`, etc.

4. **`woocommerce/cart/cart.php`** — Cart page (based on Cart.jsx if available)
   - Cart items table with product image, name, quantity, subtotal
   - Quantity +/- buttons
   - Remove item button
   - Cart totals section
   - Proceed to checkout button
   - Continue shopping link

5. **`woocommerce/checkout/form-checkout.php`** — Checkout wrapper (based on Checkout.jsx if available)
   - Clean checkout form layout matching theme style
   - Use `do_action('woocommerce_checkout_...')` hooks for standard WC checkout
   - Style the form inputs to match theme design

6. **`assets/css/woocommerce.css`** — WooCommerce-specific CSS
   - All CSS for the above templates
   - Use theme CSS custom properties (--color-primary, etc.)
   - BEM naming with `{$prefix}` prefix
   - Responsive breakpoints (sm:640px, md:768px, lg:1024px, xl:1280px)
   - Product card hover effects, badge styles, grid layouts
   - Cart and checkout form styling

### Requirements

- All templates: `defined('ABSPATH') || exit;` at top
- Use standard WooCommerce functions: `wc_get_product()`, `wc_get_cart_url()`, `wc_get_checkout_url()`, `woocommerce_template_loop_*`, etc.
- BEM class names with `{$prefix}` prefix for all custom markup
- No inline styles — all CSS goes in woocommerce.css
- Preserve the visual design from the React source: colors, spacing, typography, layout
- `get_header()` / `get_footer()` in page-level templates (archive, single)
- Product images: use `get_the_post_thumbnail()` or `wp_get_attachment_image()`

### Output format

=== woocommerce/archive-product.php ===
[content]

=== woocommerce/content-product.php ===
[content]

=== woocommerce/single-product.php ===
[content]

=== woocommerce/cart/cart.php ===
[content]

=== woocommerce/checkout/form-checkout.php ===
[content]

=== assets/css/woocommerce.css ===
[content]
PROMPT;
}
