<?php
/**
 * Homepage generation prompt — generates ALL homepage sections + front-page.php
 * in a single Claude call for full cross-section visual consistency.
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

/**
 * Build the user prompt for complete homepage generation.
 *
 * @param array  $data           Merged parsed + analyzed data
 * @param array  $home_sections  From Analyzer::detectHomeSections()
 */
function prompt_homepage_user(array $data, array $home_sections): string
{
    $theme_name = $data['theme_name'] ?? 'My Theme';
    $prefix     = $data['prefix']     ?? 'theme';
    $archetype  = $data['archetype']  ?? 'landing';

    // Colors
    $colors_ref = '';
    foreach ($data['colors'] as $key => $val) {
        if ($key === '_all_vars') continue;
        $colors_ref .= "  --color-{$key}: {$val}\n";
    }
    // Include project-specific CSS vars
    if (!empty($data['colors']['_all_vars'])) {
        $colors_ref .= "\n  /* Original project CSS vars — preserve these */\n";
        foreach ($data['colors']['_all_vars'] as $var => $val) {
            $colors_ref .= "  {$var}: {$val}\n";
        }
    }

    // Custom CSS classes from globals.css
    $custom_classes_ref = '';
    if (!empty($data['custom_classes'])) {
        $custom_classes_ref = "\n### Custom CSS classes from source (replicate in theme.css)\n";
        foreach ($data['custom_classes'] as $class => $body) {
            $custom_classes_ref .= "{$class} { {$body} }\n";
        }
    }

    // Pages for internal links
    $pages_ref = '';
    foreach ($data['pages'] as $page) {
        if ($page['slug'] !== 'home' && $page['slug'] !== '') {
            $pages_ref .= "  - {$page['name']}: <?php echo esc_url( home_url('/{$page['slug']}') ); ?>\n";
        }
    }
    if ($pages_ref === '') {
        $pages_ref = "  (single page / landing)\n";
    }

    // Build section source blocks — this is the key improvement:
    // ALL section sources are included so Claude has complete context
    $section_sources = '';
    $section_list = '';
    foreach ($home_sections as $i => $section) {
        $name = $section['component_name'] ?? $section['name'];
        $source = $section['source_code'] ?? '';

        $section_list .= ($i + 1) . ". `{$name}` → template-parts/{$section['name']}.php\n";

        if (!empty($source) && !str_starts_with($source, '/*')) {
            $section_sources .= "=== {$name} ===\n```jsx\n{$source}\n```\n\n";
        }
    }

    // Include globals.css for style reference
    $globals_block = '';
    if (!empty($data['globals_css'])) {
        $globals_block = "=== globals.css ===\n```css\n{$data['globals_css']}\n```\n\n";
    }

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix: {$prefix}
## Archetype: {$archetype}

### Design tokens
Colors:
{$colors_ref}
Fonts:
  Primary: {$data['fonts']['primary']}
  Secondary: {$data['fonts']['secondary']}
{$custom_classes_ref}

### Internal page links
{$pages_ref}

### Homepage sections (in render order)
{$section_list}

---

## Source files

{$globals_block}{$section_sources}

---

## Your task

Generate a complete WordPress homepage as individual template-part files plus `front-page.php`.

### What to produce

For each section listed above, produce a `template-parts/{section-name}.php` file.
Then produce `front-page.php` that calls `get_template_part()` for each section in order.

### Requirements for EVERY template-part

1. **Valid PHP + HTML5.** No JSX, no React, no Node imports, no JavaScript frameworks.
2. **BEM class names** using `{$prefix}` prefix: `.{$prefix}-hero__title`, `.{$prefix}-featured-products__grid`.
3. **All user-facing text** wrapped in `esc_html__('...', '{$prefix}')` or `esc_html_e('...', '{$prefix}')`.
4. **All URLs** wrapped in `esc_url()`. All attributes escaped with `esc_attr()`.
5. **Replace ALL Framer Motion** (`motion.div`, `animate`, `variants`, `initial`, `whileInView`) with `.theme-animate` CSS class (Rule 8 in system prompt).
6. **Preserve the EXACT visual layout, spacing, colors, and content** from the React source. Convert Tailwind utilities to BEM classes but maintain identical visual output.
7. **Inline SVG icons** are fine. Reference external images via `get_template_directory_uri() . '/assets/images/...'` or use placeholder image URLs from the source.
8. **No inline `<style>` or `<script>` blocks.** All CSS → theme.css, all JS → theme.js.
9. **Configurable content** via `get_theme_mod()` for headlines, button text, and key copy — extract sensible defaults from the source.
10. **Self-contained**: each section must work via `get_template_part('template-parts/{name}')`.
11. **Preserve image URLs from source** (Supabase URLs etc.) as defaults in `get_theme_mod()` calls — the user can change them in the Customizer later.

### WooCommerce sections (if applicable)
For sections that display products (e.g. FeaturedProducts), use WooCommerce queries:
```php
\$args = ['post_type' => 'product', 'posts_per_page' => 4, 'meta_key' => '_featured', 'meta_value' => 'yes'];
\$products = new WP_Query(\$args);
```
Use `wc_get_product()` to access price, image, etc.

### front-page.php structure
```php
<?php get_header(); ?>
<main id="{$prefix}-main" class="{$prefix}-main {$prefix}-main--home" role="main">
<?php
    get_template_part('template-parts/hero');
    get_template_part('template-parts/trust-bar');
    // ... etc, one per section in order
?>
</main>
<?php get_footer(); ?>
```

### Output format

Output ALL files separated by delimiters:

=== front-page.php ===
[content]

=== template-parts/hero.php ===
[content]

=== template-parts/trust-bar.php ===
[content]

(... one per section, using the slugified names from the section list above)
PROMPT;
}
