<?php
/**
 * Layout prompt — generates header.php and footer.php.
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
 * @param array{
 *   theme_name: string,
 *   prefix: string,
 *   colors: array<string, string>,
 *   fonts: array{primary: string, secondary: string, primary_family: string, secondary_family: string},
 *   css_vars: array<string, string>,
 *   nav_items: list<array{label: string, href: string}>,
 *   layout_jsx: string|null,
 *   globals_css: string|null,
 * } $data
 */
function prompt_layout_user(array $data): string
{
    $theme_name = $data['theme_name'] ?? 'My Theme';
    $prefix     = $data['prefix']     ?? 'theme';
    $nav_items  = $data['nav_items']  ?? [];
    $layout_jsx = $data['layout_jsx'] ?? null;
    $globals_css = $data['globals_css'] ?? null;

    // Build nav items list
    $nav_list = '';
    foreach ($nav_items as $item) {
        $nav_list .= "  - {$item['label']} → {$item['href']}\n";
    }
    if ($nav_list === '') {
        $nav_list = "  (no explicit nav items detected — use pages from get_pages())\n";
    }

    // Build colors summary
    $colors_summary = '';
    foreach ($data['colors'] as $key => $val) {
        $colors_summary .= "  --color-{$key}: {$val}\n";
    }

    // Build CSS vars summary
    $css_vars_summary = '';
    foreach ($data['css_vars'] as $var => $val) {
        $css_vars_summary .= "  {$var}: {$val}\n";
    }

    // Source files block
    $source_block = '';
    if ($layout_jsx !== null) {
        $source_block .= "=== Layout.jsx (Base44 source) ===\n```jsx\n{$layout_jsx}\n```\n\n";
    }
    if ($globals_css !== null) {
        $truncated = strlen($globals_css) > 8000 ? substr($globals_css, 0, 8000) . "\n/* ... truncated */" : $globals_css;
        $source_block .= "=== globals.css (Base44 source) ===\n```css\n{$truncated}\n```\n\n";
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

### Detected navigation items
{$nav_list}

### Source files
{$source_block}
---

## Your task

Generate complete, production-ready WordPress `header.php` and `footer.php` files for the **{$theme_name}** theme.

### header.php requirements
- Starts with `<?php get_header(); ?>` pattern — actually, header.php IS the header; begin with `<?php` then `get_header()` is not called here. Output the full `<!DOCTYPE html>` opening, `<head>`, and opening `<body>` + `<header>` markup.
- `<!DOCTYPE html>` with proper lang attribute using `language_attributes()`
- `<head>` must include: `wp_head()`, charset meta, viewport meta, `bloginfo('name')` title tag
- Sticky header with:
  - Site logo via `get_custom_logo()` with `bloginfo('name')` text fallback
  - Primary navigation menu rendered via `wp_nav_menu()` with theme location 'primary'
  - Accessible mobile hamburger button (aria-expanded, aria-controls)
  - Mobile menu drawer that slides in/out via JS class toggle
  - The header must become sticky on scroll (add `.is-sticky` class via JS when scrollTop > 80)
- BEM class names using `{$prefix}` prefix (e.g. `{$prefix}-header`, `{$prefix}-nav__link`)
- All interactive behaviour (mobile toggle, sticky) handled by inline `<script>` at bottom of the file

### footer.php requirements
- Complete `<footer>` markup closing the page
- Footer columns: site name/tagline | nav links | copyright notice with current year via `date('Y')`
- `wp_footer()` call before `</body>`
- Social links (if detected in source): use placeholder `#` hrefs
- Copyright line: `© <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. All rights reserved.`
- BEM class names with `{$prefix}` prefix

### General requirements
- NO inline styles — all styling comes from assets/css/theme.css
- All strings wrapped in `__( '...', '{$prefix}' )` for translation
- Use `<?php echo esc_url( home_url('/') ); ?>` for home link
- Use `<?php echo esc_url( get_stylesheet_directory_uri() ); ?>` for any asset references
- Mobile menu must be keyboard-accessible (focus trap not required, but Escape key closes it)
- The header `<nav>` must have `aria-label="Primary navigation"`

### Output format

Output exactly two files separated by the delimiter:

=== header.php ===
[complete header.php content]

=== footer.php ===
[complete footer.php content]
PROMPT;
}
