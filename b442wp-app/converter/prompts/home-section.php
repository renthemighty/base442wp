<?php
/**
 * Home section prompt — generates a single template-part for a homepage section.
 */

declare(strict_types=1);

require_once __DIR__ . '/system.php';

function prompt_home_section_system(): string
{
    return prompt_system();
}

/**
 * Build the user prompt for a single homepage section template-part.
 *
 * @param array{
 *   theme_name: string,
 *   prefix: string,
 *   colors: array<string, string>,
 *   fonts: array{primary: string, secondary: string},
 *   archetype: string,
 *   has_woocommerce: bool,
 *   nav_items: list<array{label: string, href: string}>,
 *   pages: list<array{name: string, slug: string, file: string}>,
 * } $data
 * @param string $section_name   Section identifier, e.g. 'hero', 'features', 'pricing'
 * @param string $section_source Raw React component source for this section
 */
function prompt_home_section_user(array $data, string $section_name, string $section_source): string
{
    $theme_name   = $data['theme_name'] ?? 'My Theme';
    $prefix       = $data['prefix']     ?? 'theme';
    $archetype    = $data['archetype']  ?? 'landing';

    // Truncate very large source to avoid token overflow
    $source_display = strlen($section_source) > 12000
        ? substr($section_source, 0, 12000) . "\n\n// ... source truncated ..."
        : $section_source;

    $section_title = ucwords(str_replace(['_', '-'], ' ', $section_name));

    // Build colors reference
    $colors_ref = '';
    foreach ($data['colors'] as $key => $val) {
        $colors_ref .= "  --color-{$key}: {$val}\n";
    }

    // Build pages reference for internal links
    $pages_ref = '';
    foreach ($data['pages'] as $page) {
        if ($page['slug'] !== 'home' && $page['slug'] !== '') {
            $pages_ref .= "  - {$page['name']}: <?php echo esc_url( home_url('/{$page['slug']}') ); ?>\n";
        }
    }
    if ($pages_ref === '') {
        $pages_ref = "  (single page / landing)\n";
    }

    // Section-specific instructions
    $section_instructions = match ($section_name) {
        'hero' => <<<INST
        - Full-width section with headline, sub-headline, and CTA button(s)
        - Headline uses `get_theme_mod('{$prefix}_hero_heading', 'Your Compelling Headline')` with a meaningful default extracted from the source
        - Sub-headline uses `get_theme_mod('{$prefix}_hero_subheading', '...')`
        - Primary CTA button label and URL use `get_theme_mod()` calls
        - Background: use extracted color or gradient from source
        - Add `id="hero"` on the section element for anchor navigation
        - Apply `.theme-animate` class to headline and sub-headline for scroll animation (Rule 8)
        INST,

        'features' => <<<INST
        - Grid of feature cards (typically 3 or 4 columns on desktop, 1 on mobile)
        - Extract feature items from source and hard-code them as a PHP array, each overridable via get_theme_mod() for the first 3 items
        - Each card: icon (SVG inline from source, or a Unicode fallback), title, description
        - Add `id="features"` on the section element
        - Apply `.theme-animate` class to each card for staggered scroll animation
        INST,

        'how_it_works' => <<<INST
        - Numbered steps layout (3–5 steps typically)
        - Extract step titles and descriptions from source, hard-code them as PHP array
        - Add `id="how-it-works"` on the section element
        - Apply `.theme-animate` class to each step
        INST,

        'pricing' => <<<INST
        - Pricing cards layout (typically 2–3 tiers)
        - Extract plan names, prices, and feature lists from source
        - Price values should use `get_theme_mod()` for flexibility
        - Highlight the "popular" or "recommended" plan if indicated in source
        - CTA buttons link to the contact/signup page
        - Add `id="pricing"` on the section element
        INST,

        'testimonials' => <<<INST
        - Testimonial cards or carousel
        - Extract testimonial content, author names and roles from source
        - Hard-code as PHP array (no custom post type needed for template-part)
        - Add `id="testimonials"` on the section element
        - Apply `.theme-animate` class to each testimonial card
        INST,

        'cta' => <<<INST
        - Full-width call-to-action banner
        - Headline and button text use `get_theme_mod()` calls
        - Button links to the primary conversion goal (signup / contact / download)
        - Add `id="cta"` on the section element
        - Strong visual contrast: use primary or accent color as background
        INST,

        default => <<<INST
        - Faithfully convert this section's layout and content from the React source
        - Extract any configurable text strings to `get_theme_mod()` calls
        - Add `id="{$section_name}"` on the section element
        - Apply `.theme-animate` class to animated elements (replacing Framer Motion, per Rule 8)
        INST,
    };

    return <<<PROMPT
## Theme: {$theme_name}
## CSS prefix: {$prefix}
## Archetype: {$archetype}
## Section: {$section_title}

### Design tokens (CSS custom properties)
{$colors_ref}
### Internal page links
{$pages_ref}

### React source for this section
```jsx
{$source_display}
```

---

## Your task

Convert the `{$section_title}` React component above into a WordPress PHP template-part.

### Output file
`template-parts/{$section_name}.php`

### Section-specific requirements
{$section_instructions}

### Universal requirements
1. Valid PHP + HTML5. No JSX, no React, no Node imports.
2. BEM class names with `{$prefix}` prefix — e.g. `{$prefix}-{$section_name}__title`.
3. All user-facing strings wrapped in `esc_html__('...', '{$prefix}')` or `esc_html_e('...', '{$prefix}')`.
4. All URLs wrapped in `esc_url()`.
5. All HTML attribute values escaped with `esc_attr()`.
6. Replace ALL Framer Motion animations (`motion.div`, `animate`, `variants`) with `.theme-animate` class (Rule 8). Do NOT reference Framer Motion in the output.
7. Inline SVG icons are acceptable; reference external images via `get_template_directory_uri()`.
8. Do NOT include `<style>` blocks — all CSS belongs in `assets/css/theme.css`.
9. Do NOT include `<script>` blocks — all JS belongs in `assets/js/theme.js`.
10. Preserve the visual hierarchy, layout, and content intent of the original React component.
11. The section must be fully self-contained and renderable via `get_template_part('template-parts/{$section_name}')`.

### Output format

Output exactly one file:

=== template-parts/{$section_name}.php ===
[complete PHP template content]
PROMPT;
}
