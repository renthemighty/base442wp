<?php
/**
 * Layout prompt — generates header.php and footer.php from actual
 * Header.jsx, Footer.jsx, AnnouncementBar.jsx, and Layout.jsx source.
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

function prompt_layout_system(): string
{
    return prompt_system();
}

/**
 * Build the user prompt for header.php + footer.php generation.
 *
 * @param array  $data            Merged parsed + analyzed data
 * @param array  $layout_sources  Map of component name => source code
 */
function prompt_layout_user(array $data, array $layout_sources = []): string
{
    $theme_name  = $data['theme_name'] ?? 'My Theme';
    $prefix      = $data['prefix']     ?? 'theme';
    $nav_items   = $data['nav_items']  ?? [];
    $layout_jsx  = $data['layout_jsx'] ?? null;
    $globals_css = $data['globals_css'] ?? null;

    // Nav items
    $nav_list = '';
    foreach ($nav_items as $item) {
        $nav_list .= "  - {$item['label']} → {$item['href']}\n";
    }
    if ($nav_list === '') {
        $nav_list = "  (no explicit nav items detected — use pages from get_pages())\n";
    }

    // Colors
    $colors_summary = '';
    foreach ($data['colors'] as $key => $val) {
        if ($key === '_all_vars') continue;
        $colors_summary .= "  --color-{$key}: {$val}\n";
    }
    if (!empty($data['colors']['_all_vars'])) {
        $colors_summary .= "\n  /* Original project CSS vars */\n";
        foreach ($data['colors']['_all_vars'] as $var => $val) {
            $colors_summary .= "  {$var}: {$val}\n";
        }
    }

    // CSS vars
    $css_vars_summary = '';
    foreach ($data['css_vars'] as $var => $val) {
        $css_vars_summary .= "  {$var}: {$val}\n";
    }

    // Custom classes
    $custom_classes_ref = '';
    if (!empty($data['custom_classes'])) {
        $custom_classes_ref = "\n### Custom CSS classes from source\n";
        foreach ($data['custom_classes'] as $class => $body) {
            $custom_classes_ref .= "{$class} { {$body} }\n";
        }
    }

    // Source files — include ALL layout-related components
    $source_block = '';

    // Layout.jsx
    if ($layout_jsx !== null) {
        $source_block .= "=== Layout.jsx ===\n```jsx\n{$layout_jsx}\n```\n\n";
    }

    // Shared components (Header, Footer, AnnouncementBar, Logo, etc.)
    foreach ($layout_sources as $name => $source) {
        if (!empty($source) && !str_starts_with($source, '/*')) {
            $source_block .= "=== {$name} ===\n```jsx\n{$source}\n```\n\n";
        }
    }

    // globals.css
    if ($globals_css !== null) {
        $truncated = strlen($globals_css) > 12000 ? substr($globals_css, 0, 12000) . "\n/* ... truncated */" : $globals_css;
        $source_block .= "=== globals.css ===\n```css\n{$truncated}\n```\n\n";
    }

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix: {$prefix}

### Design tokens
Colors:
{$colors_summary}
Fonts:
  Primary: {$data['fonts']['primary']}
  Secondary: {$data['fonts']['secondary']}
{$custom_classes_ref}

### Detected navigation items
{$nav_list}

---

## Source files

{$source_block}

---

## Your task

Generate complete, production-ready WordPress `header.php` and `footer.php` that **faithfully replicate** the visual design from the React source above.

### header.php requirements
- Full `<!DOCTYPE html>` opening with `language_attributes()`, `<head>` with `wp_head()`, charset, viewport
- **Announcement bar** if present in source (the bar above the main header — replicate its content and style)
- Sticky header matching source design:
  - Site logo via `get_custom_logo()` with text fallback
  - Primary navigation via `wp_nav_menu()` with theme location 'primary'
  - Cart icon link (if WooCommerce detected): link to `wc_get_cart_url()` with dynamic count
  - Accessible mobile hamburger (aria-expanded, aria-controls)
  - Mobile drawer that slides in/out matching the source design
- BEM class names: `{$prefix}-header`, `{$prefix}-nav__link`, `{$prefix}-announcement`, etc.
- Replicate the EXACT colors, spacing, font sizes from the source components
- Sticky header JS: add `.is-sticky` when scrollY > threshold (match source threshold)

### footer.php requirements
- **Newsletter signup section** if present in source (replicate the layout and copy)
- Footer columns matching source: site info, nav links organized by category
- Use `wp_nav_menu()` for footer menus where appropriate, or hardcode the structure matching source
- Copyright line with `date('Y')` and `bloginfo('name')`
- Social links if detected
- Disclaimer text if present in source
- `wp_footer()` before `</body>`
- BEM class names with `{$prefix}` prefix

### General rules
- NO inline styles — all CSS in assets/css/theme.css
- All strings: `__('...', '{$prefix}')` for translation
- Home link: `<?php echo esc_url(home_url('/')); ?>`
- Assets: `<?php echo esc_url(get_stylesheet_directory_uri()); ?>`
- Preserve image URLs from source as placeholders
- Mobile menu: keyboard-accessible, Escape closes it

### Output format

=== header.php ===
[complete header.php content]

=== footer.php ===
[complete footer.php content]
PROMPT;
}
