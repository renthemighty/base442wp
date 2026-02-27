<?php
/**
 * Functions.php generation prompt.
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

function prompt_functions_system(): string
{
    return prompt_system();
}

/**
 * Build the user prompt for functions.php generation.
 *
 * @param array{
 *   theme_name: string,
 *   prefix: string,
 *   colors: array<string, string>,
 *   fonts: array{primary: string, secondary: string, primary_family: string, secondary_family: string},
 *   pages: list<array{name: string, slug: string, file: string}>,
 *   has_woocommerce: bool,
 *   google_font_urls: list<string>,
 *   archetype: string,
 *   sections: list<string>,
 * } $data
 */
function prompt_functions_user(array $data): string
{
    $theme_name      = $data['theme_name']      ?? 'My Theme';
    $prefix          = $data['prefix']          ?? 'theme';
    $has_woocommerce = $data['has_woocommerce']  ?? false;
    $google_font_urls = $data['google_font_urls'] ?? [];
    $archetype       = $data['archetype']        ?? 'landing';

    // Colors
    $colors_list = '';
    foreach ($data['colors'] as $key => $val) {
        $colors_list .= "  {$key}: {$val}\n";
    }

    // Pages (non-home)
    $pages_list = '';
    foreach ($data['pages'] as $page) {
        if ($page['slug'] !== 'home') {
            $pages_list .= "  - {$page['name']} (/{$page['slug']})\n";
        }
    }
    if ($pages_list === '') {
        $pages_list = "  Home only (landing page)\n";
    }

    // Google Font URLs
    $font_urls_list = '';
    foreach ($google_font_urls as $url) {
        $font_urls_list .= "  - {$url}\n";
    }
    if ($font_urls_list === '') {
        $font_urls_list = "  (none detected — use Customizer font selection only)\n";
    }

    $woo_section = $has_woocommerce ? <<<WOO

### WooCommerce support required
- Declare WooCommerce support: `add_theme_support('woocommerce')`
- Add `add_theme_support('wc-product-gallery-zoom')`
- Add `add_theme_support('wc-product-gallery-lightbox')`
- Add `add_theme_support('wc-product-gallery-slider')`
- Remove default WooCommerce styles if theme provides its own:
  ```php
  add_filter('woocommerce_enqueue_styles', '__return_empty_array');
  ```
- Enqueue `assets/css/woocommerce.css` after theme.css for WooCommerce pages
- Register a 'shop' nav menu location for WooCommerce navigation
WOO
    : '';

    $menu_locations = "primary — Main Navigation";
    if ($has_woocommerce) {
        $menu_locations .= "\n  footer — Footer Navigation\n  shop — Shop Navigation";
    } else {
        $menu_locations .= "\n  footer — Footer Navigation";
    }

    // Pre-compute color defaults (can't use ?? inside heredoc interpolation)
    $default_primary    = $data['colors']['primary'] ?? '#2563eb';
    $default_accent     = $data['colors']['accent']  ?? '#7c3aed';
    $default_bg         = $data['colors']['bg']      ?? '#ffffff';
    $default_text_color = $data['colors']['text']    ?? '#111827';

    // Build Google Font family examples for Customizer
    $detected_fonts = [];
    if ($data['fonts']['primary_family'] !== 'System Sans') {
        $detected_fonts[] = $data['fonts']['primary_family'];
    }
    if ($data['fonts']['secondary_family'] !== 'System Sans' && $data['fonts']['secondary_family'] !== $data['fonts']['primary_family']) {
        $detected_fonts[] = $data['fonts']['secondary_family'];
    }
    $detected_fonts_str = !empty($detected_fonts) ? implode(', ', $detected_fonts) : 'none detected';

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix / text-domain: {$prefix}
## Archetype: {$archetype}

### Extracted colors
{$colors_list}

### Pages to create for demo content
{$pages_list}

### Detected Google Fonts
{$font_urls_list}
Detected font families: {$detected_fonts_str}
Primary font stack: {$data['fonts']['primary']}
{$woo_section}

---

## Your task

Generate a complete, production-ready `functions.php` for the **{$theme_name}** WordPress theme.

### Required: Theme Setup (add_theme_support)
```php
add_action('after_setup_theme', '{$prefix}_theme_setup');
function {$prefix}_theme_setup() {
    // Must include ALL of:
    add_theme_support('title-tag');
    add_theme_support('custom-logo', [...]);
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption','style','script']);
    add_theme_support('customize-selective-refresh-widgets');
    // Register nav menus:
    register_nav_menus([{$menu_locations}]);
}
```

### Required: Google Fonts via wp_head (Rule 2 — NEVER wp_enqueue_style)
- Detected source font URLs: {$font_urls_list}
- Hook Google Font `<link>` tags via `add_action('wp_head', ..., 5)`
- Fonts must be driven by Customizer settings (heading_font, body_font)
- When Customizer font = 'system' or empty, output nothing (use CSS system stack fallback)
- When Customizer font is set to a Google Font name, construct the URL:
  `https://fonts.googleapis.com/css2?family=ENCODED_FAMILY:wght@300;400;500;600;700&display=swap`
- Always output preconnect hints before the font link tag

### Required: Script/Style Enqueueing
```php
add_action('wp_enqueue_scripts', '{$prefix}_enqueue_assets');
function {$prefix}_enqueue_assets() {
    // Theme CSS — version from theme header
    wp_enqueue_style('{$prefix}-theme', get_stylesheet_directory_uri() . '/assets/css/theme.css', [], wp_get_theme()->get('Version'));
    // Theme JS — defer
    wp_enqueue_script('{$prefix}-theme', get_stylesheet_directory_uri() . '/assets/js/theme.js', [], wp_get_theme()->get('Version'), ['strategy' => 'defer']);
    // Inline CSS custom properties driven by Customizer
    wp_add_inline_style('{$prefix}-theme', {$prefix}_get_dynamic_css());
}
```

### Required: Dynamic CSS (Customizer → inline CSS)
Generate `{$prefix}_get_dynamic_css(): string` that outputs:
```css
:root {
    --color-primary:    [get_theme_mod primary_color];
    --color-accent:     [get_theme_mod accent_color];
    --color-background: [get_theme_mod background_color];
    --color-text:       [get_theme_mod text_color];
    --font-heading:     [derived from heading_font Customizer value];
    --font-body:        [derived from body_font Customizer value];
    --font-weight-heading: [get_theme_mod heading_weight];
    --font-weight-body: [get_theme_mod body_weight];
    --font-size-hero:   [get_theme_mod hero_font_size];
    --font-size-section-heading: [get_theme_mod section_heading_size];
    --font-size-body:   [get_theme_mod body_font_size];
}
```
All values must be sanitized before output. Colors use `sanitize_hex_color()`. Font names use `sanitize_text_field()` and are CSS-escaped.

### Required: Customizer (Rule 5)

Implement `{$prefix}_customizer(WP_Customize_Manager $wp_customize)` hooked to `customize_register`.

**Panel 1: b442wp_typography — Typography**
Controls:
1. `heading_font` — WP_Customize_Control, type: select
   Choices must include "System Sans" and at least 25 Google Font families:
   Inter, Roboto, Open Sans, Lato, Poppins, Montserrat, Raleway, Nunito, Playfair Display, Merriweather, Source Sans Pro, Ubuntu, Oswald, PT Sans, Noto Sans, Work Sans, Fira Sans, Quicksand, Josefin Sans, Mulish, Rubik, DM Sans, Outfit, Plus Jakarta Sans, Sora
   Default: detected primary font or 'System Sans'

2. `body_font` — same choices, default: detected body font or 'System Sans'

3. `heading_weight` — select, choices: 300/Light, 400/Regular, 500/Medium, 600/Semi Bold, 700/Bold, 800/Extra Bold, 900/Black. Default: 700

4. `body_weight` — select, choices: 300/Light, 400/Regular, 500/Medium. Default: 400

5. `hero_font_size` — WP_Customize_Control type: range (or text), sanitize: absint/floatval. Default: 3.75 (rem). Range: 2–8.

6. `section_heading_size` — range, default: 2.25 (rem). Range: 1.5–4.

7. `body_font_size` — range, default: 1 (rem). Range: 0.75–1.5.

**Panel 2: b442wp_colors — Colors**
Controls:
1. `primary_color` — WP_Customize_Color_Control. Default: extracted primary color ({$default_primary})
2. `accent_color` — WP_Customize_Color_Control. Default: extracted accent color ({$default_accent})
3. `background_color` — WP_Customize_Color_Control. Default: ({$default_bg})
4. `text_color` — WP_Customize_Color_Control. Default: ({$default_text_color})

All settings must have transport: 'postMessage' where JS live preview is meaningful.
Include `{$prefix}_customizer_live_preview()` function that enqueues an inline JS snippet regenerating the CSS vars on the fly.

### Required: Demo Content Importer (Rule 6)
```php
require_once get_template_directory() . '/inc/demo-content.php';
```
This file is included separately but the hook must be registered in functions.php:
```php
add_action('admin_menu', '{$prefix}_demo_import_menu');
function {$prefix}_demo_import_menu() {
    add_management_page(
        '{$theme_name} Demo Import',
        '{$theme_name} Demo Import',
        'manage_options',
        '{$prefix}-demo-import',
        '{$prefix}_demo_import_page'
    );
}
```

### Required: Helper functions
- `{$prefix}_get_font_stack(string \$font_name): string` — returns full CSS font-family stack for a given Google Font name, with system fallback
- `{$prefix}_custom_logo(): void` — outputs logo markup, falls back to site name
- `{$prefix}_excerpt_length(int \$length): int` — returns 25
- `{$prefix}_body_classes(array \$classes): array` — adds archetype class (`archetype-{$archetype}`)

### Output format

Output exactly one file:

=== functions.php ===
[complete functions.php content — no stubs, no TODOs]
PROMPT;
}
