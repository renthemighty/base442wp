<?php
/**
 * CSS generation prompt — generates assets/css/theme.css.
 *
 * Enhanced: receives generated PHP template files so CSS matches the actual
 * BEM classes used in the theme output, not just the source Tailwind classes.
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

function prompt_css_system(): string
{
    return prompt_system();
}

/**
 * Build the user prompt for theme.css generation.
 *
 * @param array         $data              Merged parsed + analyzed data
 * @param list<string>  $tailwind_classes   Unique Tailwind utility classes found in source
 * @param array         $generated_files    Map of filename => PHP content already generated
 */
function prompt_css_user(array $data, array $tailwind_classes, array $generated_files = []): string
{
    $theme_name      = $data['theme_name']     ?? 'My Theme';
    $prefix          = $data['prefix']         ?? 'theme';
    $archetype       = $data['archetype']      ?? 'landing';
    $has_woocommerce = $data['has_woocommerce'] ?? false;
    $sections        = $data['sections']       ?? ['hero', 'footer'];

    // Colors
    $colors_list = '';
    foreach ($data['colors'] as $key => $val) {
        if ($key === '_all_vars') continue;
        $colors_list .= "  --color-{$key}: {$val}\n";
    }

    // Original project CSS vars
    $project_vars = '';
    if (!empty($data['colors']['_all_vars'])) {
        $project_vars = "\n### Original project CSS custom properties (preserve these)\n";
        foreach ($data['colors']['_all_vars'] as $var => $val) {
            $project_vars .= "  {$var}: {$val}\n";
        }
    }

    // Source CSS vars
    $css_vars_list = '';
    foreach ($data['css_vars'] as $var => $val) {
        $css_vars_list .= "  {$var}: {$val}\n";
    }

    // Custom CSS classes from globals.css
    $custom_classes_block = '';
    if (!empty($data['custom_classes'])) {
        $custom_classes_block = "\n### Custom CSS classes from globals.css — MUST be replicated in theme.css\n```css\n";
        foreach ($data['custom_classes'] as $class => $body) {
            $custom_classes_block .= "{$class} {\n  {$body}\n}\n";
        }
        $custom_classes_block .= "```\n";
    }

    // Sections list
    $sections_list = implode(', ', $sections);

    // Tailwind classes
    $tw_classes_display = '';
    if (!empty($tailwind_classes)) {
        $sample = array_slice($tailwind_classes, 0, 200);
        $tw_classes_display = implode(' ', $sample);
        if (count($tailwind_classes) > 200) {
            $tw_classes_display .= ' [... ' . (count($tailwind_classes) - 200) . ' more]';
        }
    }

    // Globals CSS
    $globals_block = '';
    if (!empty($data['globals_css'])) {
        $globals_block = "=== globals.css (Base44 source) ===\n```css\n{$data['globals_css']}\n```\n\n";
    }

    // Generated PHP files — extract BEM classes so CSS matches exactly
    $generated_classes_block = '';
    if (!empty($generated_files)) {
        $all_classes = [];
        foreach ($generated_files as $filename => $content) {
            // Extract class="..." patterns from generated PHP
            preg_match_all('/class\s*=\s*["\']([^"\']+)["\']/', $content, $m);
            foreach ($m[1] as $class_str) {
                foreach (explode(' ', $class_str) as $cls) {
                    $cls = trim($cls);
                    // Only include BEM classes with the theme prefix
                    if (str_starts_with($cls, $prefix . '-') || str_starts_with($cls, 'theme-animate')) {
                        $all_classes[$cls] = true;
                    }
                }
            }
        }

        if (!empty($all_classes)) {
            $generated_classes_block = "\n### BEM classes used in generated PHP templates (CSS MUST define all of these)\n";
            $generated_classes_block .= "```\n" . implode("\n", array_keys($all_classes)) . "\n```\n";
        }
    }

    // Source component excerpts for visual reference
    $source_excerpts = '';
    $source_budget = 15000;
    $source_used = 0;
    foreach (array_merge($data['components'] ?? [], $data['pages'] ?? []) as $path => $content) {
        if (!is_string($content) || $source_used >= $source_budget) break;
        $chunk = strlen($content) > 3000 ? substr($content, 0, 3000) . "\n// [truncated]" : $content;
        $source_excerpts .= "=== " . basename((string) $path) . " ===\n```jsx\n{$chunk}\n```\n\n";
        $source_used += strlen($chunk);
    }

    $woo_note = $has_woocommerce
        ? "\n### WooCommerce note\nDo NOT include WooCommerce CSS here — it goes in a separate `assets/css/woocommerce.css` file.\n"
        : '';

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix: {$prefix}
## Archetype: {$archetype}
## Sections: {$sections_list}

### Color tokens
{$colors_list}{$project_vars}

### Source CSS custom properties
{$css_vars_list}

### Fonts
Primary: {$data['fonts']['primary']}
Secondary: {$data['fonts']['secondary']}

### Tailwind utility classes found in source
{$tw_classes_display}
{$custom_classes_block}{$generated_classes_block}{$woo_note}

---

## Source reference

{$globals_block}
{$source_excerpts}

---

## Your task

Generate the complete `assets/css/theme.css` for the **{$theme_name}** theme.

### Architecture (Rule 9 — required order)

```
/* =========================================================
   {$theme_name} Theme — assets/css/theme.css
   Generated by Base44 to WordPress (base44towordpress.com)
   ========================================================= */

/* 1. CSS Custom Properties (variables)        */
/* 2. HTML Preflight / Reset (Rule 10)         */
/* 3. Typography + Custom Classes              */
/* 4. Layout Utilities                         */
/* 5. Header & Navigation + Announcement Bar   */
/* 6. Section components (one per section)     */
/* 7. Footer                                   */
/* 8. Inner page styles                        */
/* 9. Buttons & Form elements                  */
/* 10. Utility classes (.theme-animate etc.)   */
/* 11. Responsive breakpoints                  */
```

### Section 1: CSS Custom Properties
MUST include:
- All color tokens extracted from the source
- ALL original project CSS custom properties (the --{$prefix}-* vars)
- Font family, weight, and size variables
- Spacing, transition, and radius variables

### Section 3: Typography + Custom Classes
- `h1-h6 { font-family: var(--font-heading); }` (NO blanket weight — Rule 4)
- **Replicate ALL custom CSS classes** from globals.css (e.g. .oria-heading, .oria-label, .oria-body, .oria-display, etc.)
- These are critical — the PHP templates use these classes

### Sections 5-7: Component CSS
- Generate COMPLETE CSS for every section, header, and footer
- **Convert Tailwind utilities to exact CSS values** using the mapping table
- Match the visual design exactly: gradients, shadows, spacing, hover effects
- Card hover effects, image zoom transitions, badge styles
- For each breakpoint: map both font-size AND line-height (Rule 3)

### Section 11: Responsive breakpoints (mobile-first)
```css
@media (min-width: 640px) { /* sm */ }
@media (min-width: 768px) { /* md */ }
@media (min-width: 1024px) { /* lg */ }
@media (min-width: 1280px) { /* xl */ }
```

### Output format

=== assets/css/theme.css ===
[complete CSS — no placeholders, no TODOs, production-ready]
PROMPT;
}
