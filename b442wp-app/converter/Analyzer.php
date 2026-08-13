<?php
/**
 * Analyzer — Stage 2 of the B442WP converter pipeline.
 *
 * Inspects parsed source data to detect:
 *   - Site archetype (landing / multi-page / ecommerce)
 *   - WooCommerce signals
 *   - Design tokens (colors, fonts, CSS custom properties)
 *   - Page structure and nav items
 *   - Homepage sections (parsed from Home.jsx imports, not name-guessing)
 *   - Component dependency tree
 *   - Shared components (Header, Footer, ProductCard etc.)
 *   - Tailwind utility classes
 */

declare(strict_types=1);

class Analyzer
{
    private ?array $live_scan = null;

    // ─── WooCommerce signal patterns ─────────────────────────────────────────

    private const WOOCOMMERCE_COMPONENT_NAMES = [
        'product', 'productdetail', 'productcard', 'productlist',
        'shop', 'cart', 'checkout', 'woocart', 'storefront',
        'ordersummary', 'cartitem', 'minicart',
    ];

    private const WOOCOMMERCE_FIELD_NAMES = [
        'price', 'regular_price', 'sale_price', 'stock_quantity',
        'sku', 'stock_status', 'product_type', 'add_to_cart',
    ];

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * @param  array       $parsed    Output from Parser::parse()
     * @param  array|null  $live_scan Output from LiveScanner::scan(), or null
     */
    public function analyze(array $parsed, ?array $live_scan = null): array
    {
        $this->live_scan = $live_scan;

        $theme_name       = $this->deriveThemeName($parsed);
        $has_woocommerce  = $this->detectWooCommerce($parsed);
        $pages            = $this->extractPages($parsed);
        $archetype        = $this->detectArchetype($parsed, $has_woocommerce, $pages);
        $css_vars         = $this->extractCssVars($parsed['globals_css'] ?? '');
        $colors           = $this->extractColors($parsed, $css_vars);
        $fonts            = $this->extractFonts($parsed);
        $google_font_urls = $this->extractGoogleFontUrls($parsed['globals_css'] ?? '');
        $nav_items        = $this->extractNavItems($parsed, $pages);
        $home_sections    = $this->detectHomeSections($parsed);
        $component_tree   = $this->buildComponentTree($parsed);
        $shared_components = $this->identifySharedComponents($parsed);
        $tailwind_classes = $this->extractTailwindClasses($parsed);
        $custom_classes   = $this->extractCustomClasses($parsed['globals_css'] ?? '');
        $entities         = $has_woocommerce ? $this->extractEntities($parsed) : [];
        $estimated_files  = $this->estimateFileCount($archetype, $has_woocommerce, $pages);

        return [
            'theme_name'        => $theme_name,
            'archetype'         => $archetype,
            'has_woocommerce'   => $has_woocommerce,
            'pages'             => $pages,
            'home_sections'     => $home_sections,
            'component_tree'    => $component_tree,
            'shared_components' => $shared_components,
            'custom_classes'    => $custom_classes,
            'fonts'             => $fonts,
            'colors'            => $colors,
            'css_vars'          => $css_vars,
            'google_font_urls'  => $google_font_urls,
            'nav_items'         => $nav_items,
            'price_cents'       => $has_woocommerce ? 1900 : 900,
            'entities'          => $entities,
            'tailwind_classes'  => $tailwind_classes,
            'estimated_files'   => $estimated_files,
            // Playwright live scan data (null if scan skipped or failed)
            'live_scan'         => $live_scan,
            // Preserve old key for backwards compat
            'sections'          => array_column($home_sections, 'name'),
        ];
    }

    // ─── Theme name ───────────────────────────────────────────────────────────

    private function deriveThemeName(array $parsed): string
    {
        // 1. Check index.html <title> first (most likely to have the real name)
        foreach ($parsed['files'] as $path => $content) {
            if (basename($path) === 'index.html') {
                if (preg_match('/<title>([^<]+)<\/title>/i', $content, $m)) {
                    $title = trim($m[1]);
                    $skip = ['vite app', 'react app', 'base44 app', 'base44-app', 'app'];
                    if ($title && !in_array(strtolower($title), $skip, true)) {
                        return $title;
                    }
                }
                // Also check meta description for a brand name
                if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $content, $m)) {
                    $desc = $m[1];
                    // Extract first capitalized word/phrase as brand name
                    if (preg_match('/^([A-Z][A-Za-z]+(?:\s+[A-Z][A-Za-z]+)*)/', $desc, $brand)) {
                        return $brand[1];
                    }
                }
            }
        }

        // 2. Check package.json (skip generic names)
        foreach ($parsed['files'] as $path => $content) {
            if (basename($path) === 'package.json') {
                $pkg = json_decode($content, true);
                if (is_array($pkg) && !empty($pkg['name'])) {
                    $name = (string) $pkg['name'];
                    $skip = ['base44-app', 'app', 'my-app', 'vite-project', 'react-app'];
                    if (!in_array(strtolower($name), $skip, true)) {
                        return $this->slugToTitle($name);
                    }
                }
            }
        }

        // 3. Check for a Logo component that might contain the brand name
        foreach ($parsed['components'] ?? [] as $path => $content) {
            $base = strtolower(pathinfo((string) $path, PATHINFO_FILENAME));
            if (str_contains($base, 'logo') && is_string($content)) {
                // Look for alt text or text content
                if (preg_match('/alt\s*=\s*["\']([A-Z][^"\']+)["\']/', $content, $m)) {
                    $alt = trim($m[1]);
                    if (strlen($alt) > 1 && strlen($alt) < 40) {
                        return $alt;
                    }
                }
            }
        }

        // 4. Derive from zip filename (stored in file paths)
        foreach (array_keys($parsed['files']) as $path) {
            $parts = explode('/', str_replace('\\', '/', $path));
            if (count($parts) > 1 && $parts[0] !== '' && $parts[0] !== 'src') {
                $candidate = $this->slugToTitle($parts[0]);
                $skip = ['Src', 'Public', 'App', 'Base44 App'];
                if (!in_array($candidate, $skip, true)) {
                    return $candidate;
                }
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

        foreach (self::WOOCOMMERCE_COMPONENT_NAMES as $name) {
            if (stripos($all_paths, $name) !== false) {
                return true;
            }
        }

        foreach (self::WOOCOMMERCE_FIELD_NAMES as $field) {
            if (preg_match('/[\'"]' . preg_quote($field, '/') . '[\'"]|(?<![a-z_])' . preg_quote($field, '/') . '\s*[:=]/i', $all_content)) {
                return true;
            }
        }

        if (preg_match('/import[^;]+(?:Cart|Checkout|AddToCart|WooCart)/i', $all_content)) {
            return true;
        }

        if (preg_match('/(?:\/products?|getProducts?|fetchProduct)/i', $all_content)) {
            return true;
        }

        return false;
    }

    // ─── Archetype detection ──────────────────────────────────────────────────

    private function detectArchetype(array $parsed, bool $has_woocommerce, array $pages): string
    {
        if ($has_woocommerce) {
            return 'ecommerce';
        }

        foreach ($pages as $page) {
            if (in_array(strtolower($page['slug']), ['shop', 'store', 'products', 'product'], true)) {
                return 'ecommerce';
            }
        }

        if (count($pages) <= 1) {
            return 'landing';
        }

        return 'multi-page';
    }

    // ─── Page extraction (Base44 PAGES = {} format) ──────────────────────────

    private function extractPages(array $parsed): array
    {
        $pages = [];

        // 1. Parse Base44's pages.config.js format: PAGES = { "About": About, ... }
        if ($parsed['pages_config'] !== null) {
            $pages = $this->parseBase44PagesConfig($parsed['pages_config']);
        }

        // 2. Fall back to standard path-based routes
        if (empty($pages) && $parsed['pages_config'] !== null) {
            $pages = $this->parseRoutesConfig($parsed['pages_config']);
        }

        // 3. Fall back to pages/ directory files
        if (empty($pages) && !empty($parsed['pages'])) {
            foreach ($parsed['pages'] as $path => $content) {
                $filename = pathinfo($path, PATHINFO_FILENAME);
                if (strtolower($filename) === 'index') {
                    $slug = 'home';
                    $name = 'Home';
                } else {
                    $slug = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $filename) ?? $filename);
                    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug);
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

        // 4. Always ensure Home page exists
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
     * Parse Base44's pages.config.js format.
     *
     * Looks for: export const PAGES = { "About": About, "Shop": Shop, ... }
     * And: mainPage: "Home"
     */
    private function parseBase44PagesConfig(string $config): array
    {
        $pages = [];

        // Strip comment block first (Base44 pages.config has large example comments)
        $clean = preg_replace('#/\*[\s\S]*?\*/#', '', $config) ?? $config;

        // Extract PAGES object keys: "About": About, "Shop": Shop
        if (preg_match('/(?:export\s+)?(?:const|let|var)\s+PAGES\s*=\s*\{([^}]+)\}/s', $clean, $m)) {
            $block = $m[1];
            // Match: "PageName": PageName  or  'PageName': PageName
            preg_match_all('/[\'"]([^"\']+)[\'"]\s*:\s*(\w+)/', $block, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $page_name = $match[1];
                // Convert CamelCase to slug: ProductDetail -> product-detail
                $slug = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $page_name) ?? $page_name);
                $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $slug));
                $slug = trim($slug, '-');

                // Find the actual source file for this page
                $file = 'src/pages/' . $page_name . '.jsx';

                $pages[] = [
                    'name' => preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $page_name) ?? $page_name,
                    'slug' => $slug,
                    'file' => $file,
                ];
            }
        }

        // Identify mainPage and mark it as home
        if (preg_match('/mainPage\s*:\s*[\'"]([^\'"]+)[\'"]/', $config, $m)) {
            $main_page = $m[1];
            foreach ($pages as &$page) {
                $raw_name = str_replace(' ', '', $page['name']);
                if ($raw_name === $main_page || $page['name'] === $main_page) {
                    $page['slug'] = 'home';
                    $page['name'] = 'Home';
                    break;
                }
            }
            unset($page);
        }

        return $pages;
    }

    /**
     * Parse standard path-based routes config (fallback).
     */
    private function parseRoutesConfig(string $config): array
    {
        $pages = [];

        preg_match_all('/path\s*:\s*[\'"]([^\'"]+)[\'"]/', $config, $path_matches);
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
                $name = ucwords(preg_replace('/[^a-z0-9]+/i', ' ', $slug) ?? $slug);
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug);
            }

            $file = $component ? 'pages/' . $component . '.jsx' : '';

            $pages[] = [
                'name' => $name,
                'slug' => $slug,
                'file' => $file,
            ];
        }

        return $pages;
    }

    // ─── Homepage section detection from imports ─────────────────────────────

    /**
     * Detect homepage sections by parsing Home.jsx/Home.tsx imports.
     *
     * Instead of guessing from pattern names, we read the actual imports
     * and JSX render order from the Home page component.
     *
     * @return list<array{name: string, component_name: string, source_file: string, source_code: string}>
     */
    private function detectHomeSections(array $parsed): array
    {
        $sections = [];

        // Find the Home page source
        $home_source = $this->findFileByName($parsed, ['Home', 'HomePage', 'Index', 'Landing']);
        if ($home_source === null) {
            return $this->detectSectionsFallback($parsed);
        }

        // Parse imports from Home.jsx to find section components
        // Match: import FooBar from "../components/home/FooBar";
        //   or:  import FooBar from "./components/FooBar";
        preg_match_all(
            '/import\s+(\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/',
            $home_source,
            $imports,
            PREG_SET_ORDER
        );

        // Filter to component imports (skip React, hooks, API clients, utils)
        $skip_imports = [
            'react', 'base44', 'usequeryc', 'usequery', 'usemutation',
            'link', 'usenavigate', 'uselocation', 'createpageurl',
        ];

        $component_imports = [];
        foreach ($imports as $imp) {
            $name = $imp[1];
            $path = $imp[2];

            // Skip utility/library imports
            if (in_array(strtolower($name), $skip_imports, true)) {
                continue;
            }
            // Skip @/api, @/utils, @/hooks, @/lib imports
            if (preg_match('#^@/(?:api|utils|hooks|lib|components/ui)#', $path)) {
                continue;
            }
            // Skip named/destructured imports (already filtered by regex)
            // Must be from a relative or component path
            if (str_contains($path, 'component') || str_starts_with($path, '.') || str_starts_with($path, '@/')) {
                $component_imports[] = [
                    'name'        => $name,
                    'import_path' => $path,
                ];
            }
        }

        // Now find the render order from the JSX return statement
        // Extract the order components appear in the return() block
        $render_order = [];
        if (preg_match('/return\s*\(\s*([\s\S]+)\s*\)\s*;?\s*\}/', $home_source, $jsx_match)) {
            $jsx_block = $jsx_match[1];
            // Find component tags: <FeaturedProducts ... /> or <FeaturedProducts>...</FeaturedProducts>
            preg_match_all('/<(\w+)[\s\/>]/', $jsx_block, $tags);
            $render_order = $tags[1] ?? [];
        }

        // Build sections list in render order
        $seen = [];
        $ordered_components = !empty($render_order) ? $render_order : array_column($component_imports, 'name');

        foreach ($ordered_components as $comp_name) {
            // Skip HTML elements and already-seen components
            if (ctype_lower($comp_name[0] ?? '') || isset($seen[$comp_name])) {
                continue;
            }
            $seen[$comp_name] = true;

            // Find matching import
            $import_info = null;
            foreach ($component_imports as $ci) {
                if ($ci['name'] === $comp_name) {
                    $import_info = $ci;
                    break;
                }
            }

            if ($import_info === null) {
                continue;
            }

            // Find the actual source code for this component
            $source_code = $this->findComponentSource($parsed, $comp_name, $import_info['import_path']);

            // Convert CamelCase to slug: FeaturedProducts -> featured-products
            $section_slug = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $comp_name) ?? $comp_name);
            // Remove -section suffix for cleaner names
            $section_slug = preg_replace('/-section$/', '', $section_slug);

            $sections[] = [
                'name'           => $section_slug,
                'component_name' => $comp_name,
                'source_file'    => $import_info['import_path'],
                'source_code'    => $source_code,
            ];
        }

        return $sections;
    }

    /**
     * Find source code for a component by name and import path.
     */
    private function findComponentSource(array $parsed, string $component_name, string $import_path): string
    {
        $all_files = array_merge(
            $parsed['components'] ?? [],
            $parsed['pages'] ?? [],
            $parsed['files'] ?? [],
        );

        // Try exact match on import path (resolve relative segments)
        $import_basename = basename($import_path);
        $import_basename = preg_replace('/\.[jt]sx?$/', '', $import_basename);

        foreach ($all_files as $path => $content) {
            $file_basename = pathinfo((string) $path, PATHINFO_FILENAME);
            if (strcasecmp($file_basename, $import_basename ?? '') === 0
                || strcasecmp($file_basename, $component_name) === 0
            ) {
                return is_string($content) ? $content : '';
            }
        }

        return "/* Source not found for {$component_name} */";
    }

    /**
     * Fallback section detection when Home.jsx can't be parsed.
     */
    private function detectSectionsFallback(array $parsed): array
    {
        $section_patterns = [
            'hero'         => ['hero', 'herosection', 'herobanner'],
            'features'     => ['features', 'featuresection', 'benefits', 'whyus', 'whyoria'],
            'how-it-works' => ['howitworks', 'steps', 'process'],
            'pricing'      => ['pricing', 'plans', 'packages'],
            'testimonials' => ['testimonials', 'reviews', 'socialproof'],
            'cta'          => ['cta', 'calltoaction', 'download', 'getstarted'],
        ];

        $detected = [];
        $all_paths = array_keys($parsed['components'] ?? []);

        foreach ($section_patterns as $section => $patterns) {
            foreach ($all_paths as $path) {
                $base = strtolower(pathinfo((string) $path, PATHINFO_FILENAME));
                foreach ($patterns as $pattern) {
                    if (str_contains($base, $pattern)) {
                        $source = $parsed['components'][$path] ?? '';
                        $detected[] = [
                            'name'           => $section,
                            'component_name' => pathinfo((string) $path, PATHINFO_FILENAME),
                            'source_file'    => $path,
                            'source_code'    => is_string($source) ? $source : '',
                        ];
                        break 2;
                    }
                }
            }
        }

        return $detected;
    }

    // ─── Component dependency tree ───────────────────────────────────────────

    /**
     * Build a map of component -> [imported components] for the entire project.
     *
     * @return array<string, list<string>>
     */
    private function buildComponentTree(array $parsed): array
    {
        $tree = [];
        $all_files = array_merge($parsed['pages'] ?? [], $parsed['components'] ?? []);

        foreach ($all_files as $path => $content) {
            if (!is_string($content)) continue;
            $filename = pathinfo((string) $path, PATHINFO_FILENAME);

            preg_match_all('/import\s+(\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/', $content, $imports, PREG_SET_ORDER);

            $deps = [];
            foreach ($imports as $imp) {
                $name = $imp[1];
                $from = $imp[2];
                // Only track local component imports (not libraries)
                if ((str_starts_with($from, '.') || str_starts_with($from, '@/'))
                    && !preg_match('#(?:api|utils|hooks|lib)/#', $from)
                    && ctype_upper($name[0] ?? '')
                ) {
                    $deps[] = $name;
                }
            }

            if (!empty($deps)) {
                $tree[$filename] = $deps;
            }
        }

        return $tree;
    }

    // ─── Shared components identification ────────────────────────────────────

    /**
     * Identify components used across multiple pages/components.
     *
     * @return array<string, array{file: string, source: string, used_by: list<string>}>
     */
    private function identifySharedComponents(array $parsed): array
    {
        $usage_count = [];

        $all_files = array_merge($parsed['pages'] ?? [], $parsed['components'] ?? []);

        // Count how many files import each component
        foreach ($all_files as $path => $content) {
            if (!is_string($content)) continue;

            preg_match_all('/import\s+(\w+)\s+from\s+[\'"]([^\'"]+)[\'"]/', $content, $imports, PREG_SET_ORDER);

            foreach ($imports as $imp) {
                $name = $imp[1];
                $from = $imp[2];
                if ((str_starts_with($from, '.') || str_starts_with($from, '@/'))
                    && ctype_upper($name[0] ?? '')
                    && !str_contains($from, '/ui/')
                ) {
                    if (!isset($usage_count[$name])) {
                        $usage_count[$name] = ['count' => 0, 'used_by' => []];
                    }
                    $usage_count[$name]['count']++;
                    $usage_count[$name]['used_by'][] = pathinfo((string) $path, PATHINFO_FILENAME);
                }
            }
        }

        // Components used 2+ times are "shared"
        $shared = [];
        foreach ($usage_count as $name => $info) {
            if ($info['count'] >= 2) {
                // Find source
                $source = '';
                $file = '';
                foreach ($all_files as $path => $content) {
                    if (pathinfo((string) $path, PATHINFO_FILENAME) === $name) {
                        $source = is_string($content) ? $content : '';
                        $file = $path;
                        break;
                    }
                }
                $shared[$name] = [
                    'file'    => $file,
                    'source'  => $source,
                    'used_by' => $info['used_by'],
                ];
            }
        }

        return $shared;
    }

    // ─── CSS vars ─────────────────────────────────────────────────────────────

    private function extractCssVars(string $css): array
    {
        // Live scan computed vars are authoritative — they reflect what the browser actually renders.
        if (!empty($this->live_scan['tokens']['vars'])) {
            return $this->live_scan['tokens']['vars'];
        }

        $vars = [];

        // Find ALL :root blocks (there may be multiple, or nested in @layer)
        preg_match_all('/:root\s*\{([^}]+)\}/s', $css, $root_matches);

        foreach ($root_matches[1] as $block) {
            preg_match_all('/(-{1,2}[\w-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $prop  = trim($match[1]);
                $value = trim($match[2]);

                if (preg_match('/^\d+(?:\.\d+)?\s+\d+(?:\.\d+)?%\s+\d+(?:\.\d+)?%$/', $value)) {
                    $value = 'hsl(' . preg_replace('/\s+/', ', ', $value) . ')';
                }

                $vars[$prop] = $value;
            }
        }

        return $vars;
    }

    // ─── Custom CSS classes ──────────────────────────────────────────────────

    /**
     * Extract custom (non-Tailwind) CSS classes from globals.css.
     * These are project-specific classes like .oria-heading, .oria-label etc.
     *
     * @return array<string, string>  class name => full CSS rule
     */
    private function extractCustomClasses(string $css): array
    {
        $classes = [];

        // Match class definitions that are NOT @tailwind directives
        // Pattern: .class-name { ... }
        preg_match_all('/(\.[a-zA-Z][\w-]+)\s*\{([^}]+)\}/s', $css, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $class_name = $match[1];
            $body       = trim($match[2]);

            // Skip pseudo-classes and element selectors
            if (str_contains($class_name, ':')) continue;

            $classes[$class_name] = $body;
        }

        return $classes;
    }

    // ─── Color extraction ─────────────────────────────────────────────────────

    private function extractColors(array $parsed, array $css_vars): array
    {
        // When live scan computed styles are available, derive colors from what
        // the browser actually rendered — not source declarations.
        if (!empty($this->live_scan['tokens']['computed'])) {
            $c  = $this->live_scan['tokens']['computed'];
            $lc = [];

            // Primary: button background (most reliable brand color signal)
            if (!empty($c['btn']['background-color'])) {
                $lc['primary'] = $c['btn']['background-color'];
            }

            // Background: body background-color
            if (!empty($c['body']['background-color'])) {
                $lc['bg'] = $c['body']['background-color'];
            }

            // Text: body color
            if (!empty($c['body']['color'])) {
                $lc['text'] = $c['body']['color'];
            }

            // Accent: h1 color if different from body text
            if (!empty($c['h1']['color']) && ($c['h1']['color'] !== ($lc['text'] ?? ''))) {
                $lc['accent'] = $c['h1']['color'];
            }

            // Nav link color as secondary signal
            if (!empty($c['nav_a']['color'])) {
                $lc['nav'] = $c['nav_a']['color'];
            }

            // Merge with CSS var extraction as fallback for any missing roles
            $zip_colors = $this->extractColorsFromSource($parsed, $css_vars);
            foreach (['primary', 'accent', 'bg', 'text', 'border', 'muted'] as $role) {
                if (empty($lc[$role]) && !empty($zip_colors[$role])) {
                    $lc[$role] = $zip_colors[$role];
                }
            }
            if (!empty($zip_colors['_all_vars'])) {
                $lc['_all_vars'] = $zip_colors['_all_vars'];
            }

            // Defaults for anything still missing
            if (empty($lc['primary'])) $lc['primary'] = '#2563eb';
            if (empty($lc['bg']))      $lc['bg']      = '#ffffff';
            if (empty($lc['text']))    $lc['text']    = '#111827';

            return $lc;
        }

        return $this->extractColorsFromSource($parsed, $css_vars);
    }

    private function extractColorsFromSource(array $parsed, array $css_vars): array
    {
        $colors = [];

        // 1. Standard CSS var name mappings
        $standard_mappings = [
            '--primary'    => 'primary', '--primary-color' => 'primary',
            '--accent'     => 'accent',  '--accent-color'  => 'accent',
            '--background' => 'bg',      '--bg'            => 'bg',
            '--foreground' => 'text',    '--text'          => 'text',
            '--secondary'  => 'secondary', '--muted'       => 'muted',
            '--border'     => 'border',
        ];

        foreach ($standard_mappings as $var => $key) {
            if (isset($css_vars[$var])) {
                $colors[$key] = $css_vars[$var];
            }
        }

        // 2. Project-prefixed CSS vars (e.g. --oria-teal, --oria-cream)
        // Detect the prefix from var names that appear 3+ times
        $prefixes = [];
        foreach (array_keys($css_vars) as $var) {
            if (preg_match('/^--([a-z][\w]*)-/', $var, $m)) {
                $prefixes[$m[1]] = ($prefixes[$m[1]] ?? 0) + 1;
            }
        }
        arsort($prefixes);
        $dominant_prefix = array_key_first($prefixes);

        if ($dominant_prefix !== null) {
            // Map prefixed vars to semantic roles
            // Match CSS var suffixes to semantic roles.
            // Order matters: first match wins per role.
            // Suffixes with modifiers (-light, -dark) are handled specially.
            $role_hints = [
                'primary' => ['primary', 'brand', 'main'],
                'accent'  => ['accent', 'secondary', 'highlight', 'gold', 'orange', 'amber'],
                'bg'      => ['cream', 'bg', 'background', 'surface', 'warm-gray'],
                'text'    => ['text(?!-light)'],
                'border'  => ['border', 'divider', 'line', 'separator'],
                'muted'   => ['muted', 'gray', 'subtle', 'text-light'],
            ];

            // For colors like --oria-teal (the main brand color), detect the
            // base color name without modifiers (-light, -dark)
            $base_colors = [];
            foreach ($css_vars as $var => $value) {
                if (!str_starts_with($var, "--{$dominant_prefix}-")) continue;
                $suffix = substr($var, strlen("--{$dominant_prefix}-"));
                // Skip modifier variants
                if (preg_match('/-(light|dark|hover)$/', $suffix)) continue;
                // This is a base color — check if it has variants
                $has_light = isset($css_vars["--{$dominant_prefix}-{$suffix}-light"]);
                $has_dark  = isset($css_vars["--{$dominant_prefix}-{$suffix}-dark"]);
                if ($has_light || $has_dark) {
                    $base_colors[$suffix] = $value;
                }
            }

            // If we found a base color with variants, it's likely the primary
            if (!empty($base_colors) && !isset($colors['primary'])) {
                $colors['primary'] = reset($base_colors);
            }

            foreach ($css_vars as $var => $value) {
                if (!str_starts_with($var, "--{$dominant_prefix}-")) continue;
                $suffix = substr($var, strlen("--{$dominant_prefix}-"));

                foreach ($role_hints as $role => $hints) {
                    if (isset($colors[$role])) continue;
                    foreach ($hints as $hint) {
                        // Support regex in hints (e.g. 'text(?!-light)')
                        if (str_contains($hint, '(') || str_contains($hint, '?')) {
                            if (preg_match('/^' . $hint . '$/', $suffix) || preg_match('/' . $hint . '/', $suffix)) {
                                $colors[$role] = $value;
                                break 2;
                            }
                        } elseif ($suffix === $hint || str_contains($suffix, $hint)) {
                            $colors[$role] = $value;
                            break 2;
                        }
                    }
                }
            }

            // Store all prefixed vars as-is too (used in CSS generation)
            $colors['_all_vars'] = [];
            foreach ($css_vars as $var => $value) {
                if (str_starts_with($var, "--{$dominant_prefix}-")) {
                    $colors['_all_vars'][$var] = $value;
                }
            }
        }

        // 3. Extract Tailwind arbitrary hex colors from all source files
        $all_content = implode("\n", $parsed['files']);
        preg_match_all('/(?:bg|text|border|ring|fill|stroke)-\[#([0-9a-fA-F]{3,8})\]/', $all_content, $m);

        if (!empty($m[1])) {
            $unique = array_unique($m[1]);
            $auto_keys = ['primary', 'accent', 'bg', 'secondary'];
            $idx = 0;
            foreach ($unique as $hex) {
                $color_val = '#' . strtolower($hex);
                if (!in_array($color_val, $colors, true) && isset($auto_keys[$idx])) {
                    if (!isset($colors[$auto_keys[$idx]])) {
                        $colors[$auto_keys[$idx]] = $color_val;
                    }
                    $idx++;
                }
            }
        }

        // Defaults
        if (empty($colors['primary'])) $colors['primary'] = '#2563eb';
        if (empty($colors['bg']))      $colors['bg']      = '#ffffff';
        if (empty($colors['text']))    $colors['text']    = '#111827';

        return $colors;
    }

    // ─── Font detection ───────────────────────────────────────────────────────

    private function extractFonts(array $parsed): array
    {
        $system_sans  = 'ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"';
        $system_serif = 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif';

        // Live scan computed font-family is the most reliable source — it reflects
        // what the browser actually loaded, not what the source declared.
        if (!empty($this->live_scan['tokens']['computed'])) {
            $c = $this->live_scan['tokens']['computed'];

            $live_body = isset($c['body']['font-family']) ? trim($c['body']['font-family'], " \t\"'") : null;
            $live_h1   = isset($c['h1']['font-family'])   ? trim($c['h1']['font-family'],   " \t\"'") : null;

            // Filter out system stacks — we want named fonts only
            $is_system = static function (?string $f): bool {
                if ($f === null) return true;
                return str_contains(strtolower($f), 'system-ui')
                    || str_contains(strtolower($f), 'ui-sans')
                    || str_contains(strtolower($f), 'sans-serif')
                    || str_contains(strtolower($f), '-apple-system');
            };

            $primary_family   = !$is_system($live_body) ? explode(',', $live_body ?? '')[0] : null;
            $secondary_family = (!$is_system($live_h1) && $live_h1 !== $live_body) ? explode(',', $live_h1 ?? '')[0] : null;

            // Also check fontLinks from live scan for Google Fonts names
            $font_links = $this->live_scan['fontLinks'] ?? [];
            foreach ($font_links as $fl) {
                if (!$primary_family && preg_match('/family=([^&:+]+)/', $fl, $m)) {
                    $primary_family = str_replace('+', ' ', urldecode($m[1]));
                }
            }

            if ($primary_family || $secondary_family) {
                $primary_stack   = $primary_family   ? '"' . trim($primary_family,   " \"'") . '", ' . $system_sans  : $system_sans;
                $secondary_stack = $secondary_family ? '"' . trim($secondary_family, " \"'") . '", ' . $system_serif : $system_sans;

                return [
                    'primary'          => $primary_stack,
                    'secondary'        => $secondary_stack,
                    'primary_family'   => trim($primary_family   ?? 'System Sans', " \"'"),
                    'secondary_family' => trim($secondary_family ?? 'System Sans', " \"'"),
                ];
            }
        }

        // Fall back to zip source extraction
        $css = $parsed['globals_css'] ?? '';

        $primary_family   = null;
        $secondary_family = null;

        // 1. Google Fonts @import
        if (preg_match_all('/@import\s+url\([\'"]?([^\'")]+)[\'"]?\)/', $css, $m)) {
            foreach ($m[1] as $url) {
                if (str_contains($url, 'fonts.googleapis.com')) {
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

        // 2. body font-family
        if (!$primary_family) {
            if (preg_match('/(?::root|body)\s*\{[^}]*font-family\s*:\s*([^;}]+)/s', $css, $m)) {
                $family = trim($m[1], " \t\n\r'\"");
                if (!str_contains(strtolower($family), 'system-ui') && !str_contains(strtolower($family), 'ui-sans')) {
                    $primary_family = explode(',', $family)[0];
                    $primary_family = trim($primary_family, "'\" ");
                }
            }
        }

        // 3. fontFamily in JS
        if (!$primary_family) {
            foreach ($parsed['files'] as $content) {
                if (preg_match('/fontFamily\s*:\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                    $primary_family = $m[1];
                    break;
                }
            }
        }

        $primary_stack = $primary_family
            ? '"' . $primary_family . '", ' . $system_sans
            : $system_sans;

        $secondary_stack = $secondary_family
            ? '"' . $secondary_family . '", ' . $system_serif
            : $system_sans;

        return [
            'primary'          => $primary_stack,
            'secondary'        => $secondary_stack,
            'primary_family'   => $primary_family ?? 'System Sans',
            'secondary_family' => $secondary_family ?? 'System Sans',
        ];
    }

    private function extractGoogleFontUrls(string $css): array
    {
        $urls = [];
        if (preg_match_all('/@import\s+url\([\'"]?(https?:\/\/fonts\.googleapis\.com[^\'")]+)[\'"]?\)/', $css, $m)) {
            $urls = $m[1];
        }
        return array_values(array_unique($urls));
    }

    // ─── Nav item extraction ──────────────────────────────────────────────────

    private function extractNavItems(array $parsed, array $pages): array
    {
        $nav_items = [];

        // 1. Look in Header component first (most reliable for Base44)
        $header_source = $this->findFileByName($parsed, ['Header']);
        if ($header_source !== null) {
            $nav_items = $this->extractNavFromSource($header_source);
        }

        // 2. Fall back to Layout.jsx
        if (empty($nav_items) && $parsed['layout_jsx'] !== null) {
            $nav_items = $this->extractNavFromSource($parsed['layout_jsx']);
        }

        // 3. Fall back: build from pages list
        if (empty($nav_items)) {
            foreach ($pages as $page) {
                if (in_array($page['slug'], ['home', '', 'cart', 'checkout', 'product-detail', 'category-page', 'article-detail'], true)) {
                    continue;
                }
                $nav_items[] = [
                    'label' => $page['name'],
                    'href'  => '/' . $page['slug'],
                ];
            }
        }

        return $nav_items;
    }

    private function extractNavFromSource(string $source): array
    {
        $nav_items = [];

        // Look for navLinks/navItems array: const navLinks = [ { label: "Shop", page: "Shop" }, ...]
        $array_patterns = [
            '/(?:navLinks|navItems|menuItems|navigation|links)\s*=\s*\[([\s\S]*?)\];/s',
        ];

        foreach ($array_patterns as $pattern) {
            if (preg_match($pattern, $source, $block_m)) {
                $block = $block_m[1];

                // Match objects with label/page or label/href
                preg_match_all('/\{[^}]*(?:label|name|title)\s*:\s*[\'"]([^\'"]+)[\'"][^}]*(?:page|href|to)\s*:\s*[\'"]([^\'"]+)[\'"][^}]*\}/s', $block, $item_m, PREG_SET_ORDER);

                if (empty($item_m)) {
                    // Try reversed order: { page: "X", label: "Y" }
                    preg_match_all('/\{[^}]*(?:page|href|to)\s*:\s*[\'"]([^\'"]+)[\'"][^}]*(?:label|name|title)\s*:\s*[\'"]([^\'"]+)[\'"][^}]*\}/s', $block, $item_m2, PREG_SET_ORDER);
                    foreach ($item_m2 as $item) {
                        $page  = $item[1];
                        $label = $item[2];
                        $slug  = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $page) ?? $page);
                        $nav_items[] = [
                            'label' => $label,
                            'href'  => '/' . $slug,
                        ];
                    }
                } else {
                    foreach ($item_m as $item) {
                        $label = $item[1];
                        $page  = $item[2];
                        $slug  = strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '-', $page) ?? $page);
                        if (str_starts_with($page, '/') || str_starts_with($page, 'http')) {
                            $nav_items[] = ['label' => $label, 'href' => $page];
                        } else {
                            $nav_items[] = ['label' => $label, 'href' => '/' . $slug];
                        }
                    }
                }

                if (!empty($nav_items)) {
                    return $nav_items;
                }
            }
        }

        return $nav_items;
    }

    // ─── Tailwind class extraction ────────────────────────────────────────────

    private function extractTailwindClasses(array $parsed): array
    {
        if (!$parsed['has_tailwind']) {
            return [];
        }

        $classes = [];
        $all_content = implode("\n", array_values($parsed['files']));

        preg_match_all('/(?:className|class)\s*=\s*[\'"]([^\'"]+)[\'"]/', $all_content, $m);
        foreach ($m[1] as $class_str) {
            foreach (explode(' ', $class_str) as $cls) {
                $cls = trim($cls);
                if ($cls !== '') $classes[] = $cls;
            }
        }

        preg_match_all('/className=\{`([^`]+)`\}/', $all_content, $m2);
        foreach ($m2[1] as $class_str) {
            foreach (preg_split('/\s+/', $class_str) as $cls) {
                $cls = trim($cls);
                if ($cls !== '' && !str_starts_with($cls, '$')) $classes[] = $cls;
            }
        }

        preg_match_all('/(?:cn|clsx|classnames)\s*\(([^)]+)\)/s', $all_content, $m3);
        foreach ($m3[1] as $args) {
            preg_match_all('/[\'"]([a-z][\w\-:\[\]#%.\/]+)[\'"]/', $args, $m4);
            foreach ($m4[1] as $cls) $classes[] = $cls;
        }

        return array_values(array_unique($classes));
    }

    // ─── Entity extraction ────────────────────────────────────────────────────

    private function extractEntities(array $parsed): array
    {
        $entities = [];
        $all_content = implode("\n", $parsed['files']);

        preg_match_all(
            '/(?:createEntity|defineEntity)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\{([^}]+(?:\{[^}]*\}[^}]*)*)\}/s',
            $all_content, $m, PREG_SET_ORDER
        );

        foreach ($m as $match) {
            $entity_name = $match[1];
            $body        = $match[2];

            preg_match_all('/[\'"]?(\w+)[\'"]?\s*:\s*(?:[\'"]|{)/', $body, $field_m);
            $fields = array_unique(array_filter($field_m[1], fn($f) => strlen($f) > 1));

            $entities[] = [
                'name'   => $entity_name,
                'fields' => array_values($fields),
            ];
        }

        return $entities;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Find a source file by component name across all parsed files.
     *
     * @param  string[] $names  Possible file/component names to match
     * @return string|null      Source code, or null if not found
     */
    private function findFileByName(array $parsed, array $names): ?string
    {
        $all_files = array_merge(
            $parsed['pages'] ?? [],
            $parsed['components'] ?? [],
            $parsed['files'] ?? [],
        );

        foreach ($names as $name) {
            foreach ($all_files as $path => $content) {
                $base = pathinfo((string) $path, PATHINFO_FILENAME);
                if (strcasecmp($base, $name) === 0) {
                    return is_string($content) ? $content : null;
                }
            }
        }

        return null;
    }

    private function estimateFileCount(string $archetype, bool $has_woocommerce, array $pages): int
    {
        $base = 8;
        $non_home = array_filter($pages, fn($p) => !in_array($p['slug'], ['home', ''], true));
        $base += count($non_home) * 2;

        if ($archetype === 'landing') $base += 5;
        if ($has_woocommerce) $base += 6;
        $base += 2;

        return $base;
    }
}
