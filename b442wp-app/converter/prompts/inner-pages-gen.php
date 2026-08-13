<?php
/**
 * Inner page templates generation prompt.
 *
 * Generates WordPress page templates for non-home, non-WooCommerce pages
 * (About, Quality, FAQ, Journal, Contact, Athletes, etc.)
 * from actual React source components.
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

/**
 * Build the user prompt for inner page template generation.
 *
 * @param array  $data          Merged parsed + analyzed data
 * @param array  $page_sources  Map of page slug => ['name' => ..., 'source' => ...]
 */
function prompt_inner_pages_user(array $data, array $page_sources): string
{
    $theme_name = $data['theme_name'] ?? 'My Theme';
    $prefix     = $data['prefix']     ?? 'theme';

    // Colors
    $colors_ref = '';
    foreach ($data['colors'] as $key => $val) {
        if ($key === '_all_vars') continue;
        $colors_ref .= "  --color-{$key}: {$val}\n";
    }

    // Build source blocks and page list
    $source_blocks = '';
    $pages_list = '';
    $expected_files = [];

    foreach ($page_sources as $slug => $info) {
        $name   = $info['name'];
        $source = $info['source'] ?? '';
        $file   = "page-{$slug}.php";

        $pages_list .= "- **{$name}** (slug: `{$slug}`) → `{$file}`\n";
        $expected_files[] = $file;

        if (!empty($source) && !str_starts_with($source, '/*')) {
            $source_blocks .= "=== {$name} ({$slug}) ===\n```jsx\n{$source}\n```\n\n";
        }
    }

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix: {$prefix}

### Design tokens
Colors:
{$colors_ref}
Fonts:
  Primary: {$data['fonts']['primary']}

---

## React source components

{$source_blocks}

---

## Your task

Generate WordPress page templates for these pages:

{$pages_list}

### Requirements for EVERY template

1. Begin with `<?php get_header(); ?>`
2. End with `<?php get_footer(); ?>`
3. Wrap content in `<main class="{$prefix}-page {$prefix}-page--{slug}">...</main>`
4. Include a page hero/header section with the page title and description
5. **Faithfully convert** the React source layout and content to PHP/HTML
6. BEM class names with `{$prefix}` prefix
7. All strings wrapped in `esc_html__('...', '{$prefix}')`
8. All URLs wrapped in `esc_url()`
9. No inline `<style>` or `<script>` blocks
10. Replace Framer Motion with `.theme-animate` class
11. Use `get_theme_mod()` for configurable headlines and key content
12. Contact forms: use a placeholder that works with Contact Form 7 or WPForms:
    `<?php echo do_shortcode('[contact-form-7 id="1" title="Contact"]'); ?>`
13. FAQ sections: use accordion markup with `.theme-animate` and data attributes for JS
14. Image galleries / grids: use `get_template_directory_uri()` for local images, preserve source URLs as defaults

### Output format

Output each file separated by delimiters:

=== page-SLUG.php ===
[complete template content]

(one per page)
PROMPT;
}
