<?php
/**
 * Analyzer — Stage 2 of the B442WP converter pipeline.
 *
 * Inspects parsed source data to detect:
 *   - Site archetype (landing / multi-page / ecommerce)
 *   - WooCommerce signals
 *   - Design tokens (colors, fonts, CSS custom properties)
 *   - Page structure and nav items
 *   - Homepage sections
 *   - Tailwind utility classes
 */

declare(strict_types=1);

class Analyzer
{
    // ─── WooCommerce signal patterns ─────────────────────────────────────────

    /** Component / file names that signal e-commerce. */
    private const WOOCOMMERCE_COMPONENT_NAMES = [
        'product', 'productdetail', 'productcard', 'productlist',
        'shop', 'cart', 'checkout', 'woocart', 'storefront',
        'ordersummary', 'cartitem', 'minicart',
    ];

    /** Field names in entity definitions that signal e-commerce. */
    private const WOOCOMMERCE_FIELD_NAMES = [
        'price', 'regular_price', 'sale_price', 'stock_quantity',
        'sku', 'stock_status', 'product_type', 'add_to_cart',
    ];

    /** Homepage sections we detect by scanning component names / IDs. */
    private const SECTION_PATTERNS = [
        'hero'         => ['hero', 'herosecion', 'herobanner', 'banner', 'jumbotron'],
        'features'     => ['features', 'featuresection', 'benefits', 'benefitssection', 'whyus'],
        'how_it_works' => ['howitworks', 'howitworkssection', 'steps', 'process', 'howto'],
        'pricing'      => ['pricing', 'pricingsection', 'plans', 'planssection', 'packages'],
        'testimonials' => ['testimonials', 'testimonialssection', 'reviews', 'reviewssection', 'social-proof'],
        'cta'          => ['cta', 'calltoaction', 'ctasection', 'download', 'getstarted', 'signup'],
        'footer'       => ['footer', 'footersection', 'footercontent'],
    ];

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Analyse parsed source data and return a rich context array.
     *
     * @param  array<string, mixed> $parsed  Output of Parser::parse().
     * @return array<string, mixed>
     */
    public function analyze(array $parsed): array
    {
        $theme_name      = $this->deriveThemeName($parsed);
        $has_woocommerce = $this->detectWooCommerce($parsed);
        $pages           = $this->extractPages($parsed);
        $archetype       = $this->detectArchetype($parsed, $has_woocommerce, $pages);
        $css_vars        = $this->extractCssVars($parsed['globals_css'] ?? '');
        $colors          = $this->extractColors($parsed, $css_vars);
        $fonts           = $this->extractFonts($parsed);
        $google_font_urls = $this->extractGoogleFontUrls($parsed['globals_css'] ?? '');
        $nav_items       = $this->extractNavItems($parsed, $pages);
        $sections        = $this->detectSections($parsed);
        $tailwind_classes = $this->extractTailwindClasses($parsed);
        $entities        = $has_woocommerce ? $this->extractEntities($parsed) : [];
        $estimated_files = $this->estimateFileCount($archetype, $has_woocommerce, $pages);

        return [
            'theme_name'       => $theme_name,
            'archetype'        => $archetype,
            'has_woocommerce'  => $has_woocommerce,
            'pages'            => $pages,
            'sections'         => $sections,
            'fonts'            => $fonts,
            'colors'           => $colors,
            'css_vars'         => $css_vars,
            'google_font_urls' => $google_font_urls,
            'nav_items'        => $nav_items,
            'price_cents'      => $has_woocommerce ? 1900 : 900,
            'entities'         => $entities,
            'tailwind_classes' => $tailwind_classes,
            'estimated_files'  => $estimated_files,
        ];
    }

    // ─── Theme name ───────────────────────────────────────────────────────────

    private function deriveThemeName(array $parsed): string
    {
        // Check package.json for name field
        foreach ($parsed['files'] as $path => $content) {
            if (basename($path) === 'package.json') {
                $pkg = json_decode($content, true);
                if (is_array($pkg) && !empty($pkg['name'])) {
                    return $this->slugToTitle((string) $pkg['name']);
                }
            }
        }

        // Try to read from index.html title
        foreach ($parsed['files'] as $path => $content) {
            if (basename($path) === 'index.html') {
                if (preg_match('/<title>([^<]+)<\/title>/i', $content, $m)) {
                    $title = trim($m[1]);
                    if ($title && strtolower($title) !== 'vite app') {
                        return $title;
                    }
                }
            }
        }

        // Fallback: use the zip filename stored in files array keys' root dir
        foreach (array_keys($parsed['files']) as $path) {
            $parts = explode('/', str_replace('\\', '/', $path));
            if (count($parts) > 1 && $parts[0] !== '') {
                return $this->slugToTitle($parts[0]);
            }
        }

        return 'WordPress Theme';
    }

    private function slugToTitle(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', ' ', $slug) ?? $slug;
        return ucwords(trim($slug));
    }

    // ─── WooCommerce detection ────────────────────────────────────────────────

    private function detectWooCommerce(array $parsed): bool
    {
        $all_content = implode("\n", $parsed['files']);
        $all_paths   = implode("\n", array_keys($parsed['files']));

        // 1. Component names in file paths
        foreach (self::WOOCOMMERCE_COMPONENT_NAMES as $name) {
            if (stripos($all_paths, $name) !== false) {
                return true;
            }
        }

        // 2. Price / stock fields in entity definitions
        foreach (self::WOOCOMMERCE_FIELD_NAMES as $field) {
            // Look for field definitions like: price:, "price", price =
            if (preg_match('/[\'"]' . preg_quote($field, '/') . '[\'"]|(?<![a-z_])' . preg_quote($field, '/') . '\s*[:=]/i', $all_content)) {
                return true;
            }
        }

        // 3. createEntity / defineEntity with price fields
        if (preg_match('/(?:createEntity|defineEntity)[^{]*\{[^}]*(?:price|sku|stock)/si', $all_content)) {
            return true;
        }

        // 4. Cart / checkout imports
        if (preg_match('/import[^;]+(?:Cart|Checkout|AddToCart|WooCart)/i', $all_content)) {
            return true;
        }

        // 5. Product-related API patterns
        if (preg_match('/(?:\/products?|getProducts?|fetchProduct)/i', $all_content)) {
            return true;
        }

        return false;
    }

    // ─── Archetype detection ──────────────────────────────────────────────────

    /**
     * @param list<array{name: string, slug: string, file: string}> $pages
     */
    private function detectArchetype(array $parsed, bool $has_woocommerce, array $pages): string
    {
        if ($has_woocommerce) {
            return 'ecommerce';
        }

        // Check for shop/product pages
        foreach ($pages as $page) {
            if (in_array(strtolower($page['slug']), ['shop', 'store', 'products', 'product'], true)) {
                return 'ecommerce';
            }
        }

        // Single page / landing: only one page or no pages config
        if (count($pages) <= 1) {
            return 'landing';
        }

        // Check if pages config only has a root route
        if ($parsed['pages_config'] !== null) {
            $route_count = substr_count($parsed['pages_config'], 'path:')
                         + substr_count($parsed['pages_config'], "path':")
                         + substr_count($parsed['pages_config'], 'route:');
            if ($route_count <= 1) {
                return 'landing';
            }
        }

        return 'multi-page';
    }

    // ─── Page extraction ──────────────────────────────────────────────────────

    /**
     * @return list<array{name: string, slug: string, file: string}>
     */
    private function extractPages(array $parsed): array
    {
        $pages = [];

        // 1. Parse pages.config.js/ts for route definitions
        if ($parsed['pages_config'] !== null) {
            $pages = $this->parseRoutesConfig($parsed['pages_config']);
        }

        // 2. Fall back to pages/ directory files
        if (empty($pages) && !empty($parsed['pages'])) {
            foreach ($parsed['pages'] as $path => $content) {
                $filename = pathinfo($path, PATHINFO_FILENAME);
                // Skip index files at root (they become the home page)
                if (strtolower($filename) === 'index') {
                    $slug = 'home';
                    $name = 'Home';
                } else {
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $filename) ?? $filename);
                    $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $filename) ?? $filename;
                    $name = ucfirst($name);
                }

                $pages[] = [
                    'name' => $name,
                    'slug' => $slug,
                    'file' => $path,
                ];
            }
        }

        // 3. Always ensure Home page exists
        $has_home = false;
        foreach ($pages as $page) {
            if (in_array($page['slug'], ['home', '', '/'], true)) {
                $has_home = true;
                break;
            }
        }

        if (!$has_home) {
            array_unshift($pages, ['name' => 'Home', 'slug' => 'home', 'file' => '']);
        }

        return $pages;
    }

    /**
     * Parse a pages.config.js/ts file and extract route definitions.
     *
     * @return list<array{name: string, slug: string, file: string}>
     */
    private function parseRoutesConfig(string $config): array
    {
        $pages = [];

        // Match path definitions: path: '/', path: '/about', etc.
        preg_match_all('/path\s*:\s*[\'"]([^\'"]+)[\'"]/', $config, $path_matches);
        // Match component/page references: component: HomePage, element: <AboutPage />
        preg_match_all('/(?:component|element|page)\s*:\s*[<\s]*([A-Z][a-zA-Z]+)/', $config, $comp_matches);

        $paths      = $path_matches[1] ?? [];
        $components = $comp_matches[1] ?? [];

        foreach ($paths as $i => $path) {
            $component = $components[$i] ?? '';
            $slug      = trim($path, '/');

            if ($slug === '' || $slug === '/') {
                $name = 'Home';
                $slug = 'home';
            } else {
                $name = preg_replace('/[^a-z0-9]+/i', ' ', $slug) ?? $slug;
                $name = ucwords($name);
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug);
            }

            // Derive component file from component name if given
            $file = '';
            if ($component) {
                // Convert ComponentName → pages/ComponentName.jsx (best guess)
                $file = 'pages/' . $component . '.jsx';
            }

            $pages[] = [
                'name' => $name,
                'slug' => $slug,
                'file' => $file,
            ];
        }

        return $pages;
    }

    // ─── CSS vars ─────────────────────────────────────────────────────────────

    /**
     * Parse CSS custom properties from a globals.css :root block.
     *
     * @return array<string, string>  e.g. ['--primary' => 'hsl(30, 78%, 57%)']
     */
    private function extractCssVars(string $css): array
    {
        $vars = [];

        // Find :root { ... } block (possibly spanning multiple lines)
        if (!preg_match('/:root\s*\{([^}]+)\}/s', $css, $m)) {
            return $vars;
        }

        $block = $m[1];

        preg_match_all('/(-{1,2}[\w-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $prop  = trim($match[1]);
            $value = trim($match[2]);

            // Normalise bare HSL shorthand: "30 78% 57%" → "hsl(30, 78%, 57%)"
            if (preg_match('/^\d+(?:\.\d+)?\s+\d+(?:\.\d+)?%\s+\d+(?:\.\d+)?%$/', $value)) {
                $value = 'hsl(' . preg_replace('/\s+/', ', ', $value) . ')';
            }

            $vars[$prop] = $value;
        }

        return $vars;
    }

    // ─── Color extraction ─────────────────────────────────────────────────────

    /**
     * @param array<string, string> $css_vars
     * @return array<string, string>
     */
    private function extractColors(array $parsed, array $css_vars): array
    {
        $colors = [];

        // 1. Promote well-known CSS var names to color map
        $mappings = [
            '--primary'    => 'primary',
            '--primary-color' => 'primary',
            '--accent'     => 'accent',
            '--accent-color' => 'accent',
            '--background' => 'bg',
            '--bg'         => 'bg',
            '--foreground' => 'text',
            '--text'       => 'text',
            '--secondary'  => 'secondary',
            '--muted'      => 'muted',
            '--border'     => 'border',
        ];

        foreach ($mappings as $var => $key) {
            if (isset($css_vars[$var])) {
                $colors[$key] = $css_vars[$var];
            }
        }

        // 2. Extract arbitrary Tailwind hex colors from all source files
        $all_content = implode("\n", $parsed['files']);
        preg_match_all('/(?:bg|text|border|ring|fill|stroke)-\[#([0-9a-fA-F]{3,8})\]/', $all_content, $m);

        if (!empty($m[1])) {
            $unique = array_unique($m[1]);
            // Assign the first few as primary/accent/bg if not already set
            $auto_keys = ['primary', 'accent', 'bg', 'secondary'];
            $idx = 0;
            foreach ($unique as $hex) {
                $color_val = '#' . strtolower($hex);
                // Check if already captured
                if (!in_array($color_val, $colors, true) && isset($auto_keys[$idx])) {
                    if (!isset($colors[$auto_keys[$idx]])) {
                        $colors[$auto_keys[$idx]] = $color_val;
                    }
                    $idx++;
                }
            }
        }

        // Defaults if nothing detected
        if (empty($colors['primary'])) {
            $colors['primary'] = '#2563eb';
        }
        if (empty($colors['bg'])) {
            $colors['bg'] = '#ffffff';
        }
        if (empty($colors['text'])) {
            $colors['text'] = '#111827';
        }

        return $colors;
    }

    // ─── Font detection ───────────────────────────────────────────────────────

    /**
     * @return array{primary: string, secondary: string, primary_family: string, secondary_family: string}
     */
    private function extractFonts(array $parsed): array
    {
        $css = $parsed['globals_css'] ?? '';

        $primary_family   = null;
        $secondary_family = null;

        // 1. Look for @import url() Google Fonts
        if (preg_match_all('/@import\s+url\([\'"]?([^\'")]+)[\'"]?\)/', $css, $m)) {
            foreach ($m[1] as $url) {
                if (str_contains($url, 'fonts.googleapis.com')) {
                    // Extract family names from URL
                    if (preg_match('/family=([^&]+)/', $url, $fm)) {
                        $families = explode('|', urldecode($fm[1]));
                        foreach ($families as $fam) {
                            $fam = trim(explode(':', $fam)[0]);
                            $fam = str_replace('+', ' ', $fam);
                            if (!$primary_family) {
                                $primary_family = $fam;
                            } elseif (!$secondary_family) {
                                $secondary_family = $fam;
                            }
                        }
                    }
                }
            }
        }

        // 2. Look for font-family in :root or body
        if (!$primary_family) {
            if (preg_match('/(?::root|body)\s*\{[^}]*font-family\s*:\s*([^;}]+)/s', $css, $m)) {
                $family = trim($m[1], " \t\n\r'\"");
                // Only use it if it looks like a named font, not a system stack
                if (!str_contains(strtolower($family), 'system-ui') && !str_contains(strtolower($family), 'ui-sans')) {
                    $primary_family = explode(',', $family)[0];
                    $primary_family = trim($primary_family, "'\" ");
                }
            }
        }

        // 3. Look for CSS var font references
        if (!$primary_family) {
            foreach ($parsed['files'] as $content) {
                if (preg_match('/fontFamily\s*:\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                    $primary_family = $m[1];
                    break;
                }
            }
        }

        // System stack fallbacks (per Rule 1 and Rule 11)
        $system_sans  = 'ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"';
        $system_serif = 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif';

        // Build CSS font-family stacks
        $primary_stack = $primary_family
            ? '"' . $primary_family . '", ' . $system_sans
            : $system_sans;

        $secondary_stack = $secondary_family
            ? '"' . $secondary_family . '", ' . $system_serif
            : $system_sans;

        return [
            'primary'         => $primary_stack,
            'secondary'       => $secondary_stack,
            'primary_family'  => $primary_family ?? 'System Sans',
            'secondary_family' => $secondary_family ?? 'System Sans',
        ];
    }

    // ─── Google Font URL extraction ───────────────────────────────────────────

    /** @return list<string> */
    private function extractGoogleFontUrls(string $css): array
    {
        $urls = [];

        if (preg_match_all('/@import\s+url\([\'"]?(https?:\/\/fonts\.googleapis\.com[^\'")]+)[\'"]?\)/', $css, $m)) {
            foreach ($m[1] as $url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    // ─── Nav item extraction ──────────────────────────────────────────────────

    /**
     * @param list<array{name: string, slug: string, file: string}> $pages
     * @return list<array{label: string, href: string}>
     */
    private function extractNavItems(array $parsed, array $pages): array
    {
        $nav_items = [];

        // 1. Look for nav links in Layout.jsx
        if ($parsed['layout_jsx'] !== null) {
            $nav_items = $this->extractNavFromSource($parsed['layout_jsx']);
        }

        // 2. Fall back: build from pages list
        if (empty($nav_items)) {
            foreach ($pages as $page) {
                if ($page['slug'] === 'home' || $page['slug'] === '') {
                    continue; // Skip home from nav
                }
                $nav_items[] = [
                    'label' => $page['name'],
                    'href'  => '/' . $page['slug'],
                ];
            }
        }

        return $nav_items;
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function extractNavFromSource(string $source): array
    {
        $nav_items = [];

        // Match href="/path" with surrounding label text or title prop
        // Pattern: <a href="/path">Label</a> or { href: '/path', label: 'Label' }
        preg_match_all('/href\s*[=:]\s*[\'"]([^\'"]+)[\'"]/', $source, $href_m);

        // Also look for navItems array definitions: { href: '/about', label: 'About' }
        if (preg_match('/(?:navItems|navLinks|menuItems|navigation)\s*=\s*\[([^\]]+)\]/s', $source, $block_m)) {
            preg_match_all('/\{[^}]*href\s*:\s*[\'"]([^\'"]+)[\'"][^}]*(?:label|name|title)\s*:\s*[\'"]([^\'"]+)[\'"][^}]*\}/s', $block_m[1], $item_m, PREG_SET_ORDER);
            foreach ($item_m as $item) {
                $nav_items[] = [
                    'label' => $item[2],
                    'href'  => $item[1],
                ];
            }

            if (!empty($nav_items)) {
                return $nav_items;
            }
        }

        // Simple href extraction — skip external links, anchors, js:void
        foreach ($href_m[1] ?? [] as $href) {
            if (
                str_starts_with($href, 'http')
                || str_starts_with($href, 'mailto')
                || str_starts_with($href, 'javascript')
                || $href === '/'
                || $href === '#'
            ) {
                continue;
            }

            // Derive label from slug
            $slug  = ltrim($href, '/#');
            $label = ucwords(str_replace(['-', '_', '/'], ' ', $slug));

            $nav_items[] = [
                'label' => $label,
                'href'  => str_starts_with($href, '/') ? $href : '/' . $href,
            ];
        }

        return array_values(array_unique($nav_items, SORT_REGULAR));
    }

    // ─── Section detection ────────────────────────────────────────────────────

    /** @return list<string> */
    private function detectSections(array $parsed): array
    {
        $detected = [];

        // Scan Layout.jsx and all pages/components for section names
        $sources_to_scan = [];
        if ($parsed['layout_jsx'] !== null) {
            $sources_to_scan[] = $parsed['layout_jsx'];
        }
        foreach ($parsed['pages'] as $content) {
            $sources_to_scan[] = $content;
        }
        // Home page specifically
        foreach ($parsed['files'] as $path => $content) {
            $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
            if (in_array($base, ['home', 'index', 'homepage', 'landing'], true)) {
                $sources_to_scan[] = $content;
            }
        }

        $combined = strtolower(implode("\n", $sources_to_scan));

        foreach (self::SECTION_PATTERNS as $section => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($combined, $pattern)) {
                    $detected[] = $section;
                    break;
                }
            }
        }

        // Always include hero and footer
        if (!in_array('hero', $detected, true)) {
            $detected[] = 'hero';
        }
        if (!in_array('footer', $detected, true)) {
            $detected[] = 'footer';
        }

        return array_values(array_unique($detected));
    }

    // ─── Tailwind class extraction ────────────────────────────────────────────

    /** @return list<string> */
    private function extractTailwindClasses(array $parsed): array
    {
        if (!$parsed['has_tailwind']) {
            return [];
        }

        $classes = [];
        $all_content = implode("\n", array_values($parsed['files']));

        // className="..." and class="..." strings
        preg_match_all('/(?:className|class)\s*=\s*[\'"]([^\'"]+)[\'"]/', $all_content, $m);
        foreach ($m[1] as $class_str) {
            foreach (explode(' ', $class_str) as $cls) {
                $cls = trim($cls);
                if ($cls !== '') {
                    $classes[] = $cls;
                }
            }
        }

        // Template literals: className={`... `}
        preg_match_all('/className=\{`([^`]+)`\}/', $all_content, $m2);
        foreach ($m2[1] as $class_str) {
            foreach (preg_split('/\s+/', $class_str) as $cls) {
                $cls = trim($cls);
                if ($cls !== '' && !str_starts_with($cls, '$')) {
                    $classes[] = $cls;
                }
            }
        }

        // cn() / clsx() / classnames() calls — best effort string extraction
        preg_match_all('/(?:cn|clsx|classnames)\s*\(([^)]+)\)/s', $all_content, $m3);
        foreach ($m3[1] as $args) {
            preg_match_all('/[\'"]([a-z][\w\-:\[\]#%.\/]+)[\'"]/', $args, $m4);
            foreach ($m4[1] as $cls) {
                $classes[] = $cls;
            }
        }

        return array_values(array_unique($classes));
    }

    // ─── Entity extraction ────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function extractEntities(array $parsed): array
    {
        $entities = [];

        $all_content = implode("\n", $parsed['files']);

        // Look for entity definitions: createEntity('Product', { fields: { ... } })
        preg_match_all(
            '/(?:createEntity|defineEntity)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s',
            $all_content,
            $m,
            PREG_SET_ORDER
        );

        foreach ($m as $match) {
            $entity_name = $match[1];
            $body        = $match[2];

            // Extract field names
            preg_match_all('/[\'"]?(\w+)[\'"]?\s*:\s*(?:[\'"]|{)/', $body, $field_m);
            $fields = array_unique(array_filter($field_m[1], fn($f) => strlen($f) > 1));

            $entities[] = [
                'name'   => $entity_name,
                'fields' => array_values($fields),
            ];
        }

        return $entities;
    }

    // ─── Estimated file count ─────────────────────────────────────────────────

    private function estimateFileCount(string $archetype, bool $has_woocommerce, array $pages): int
    {
        $base = 8; // functions.php, style.css, header.php, footer.php, front-page.php, theme.css, theme.js, index.php

        // Add one template per non-home page
        $non_home = array_filter($pages, fn($p) => !in_array($p['slug'], ['home', ''], true));
        $base += count($non_home) * 2; // template + template-part

        if ($archetype === 'landing') {
            $base += 5; // section template-parts
        }

        if ($has_woocommerce) {
            $base += 6; // woo templates
        }

        $base += 2; // demo-content.php + screenshot.png

        return $base;
    }
}
