<?php
/**
 * Converter — Stage 3 orchestrator for the B442WP pipeline.
 *
 * Rewritten to use fewer, larger Claude API calls with complete source context.
 * Each call gets the full React source for the components it's converting,
 * producing consistent, design-faithful WordPress theme output.
 *
 * Generation strategy (6 calls vs old 15+):
 *   1. functions.php (1 call, 16K tokens)
 *   2. header.php + footer.php (1 call, 16K tokens — full Header/Footer/Layout source)
 *   3. Homepage: front-page.php + ALL template-parts (1 call, 32K tokens — ALL section source)
 *   4. WooCommerce templates (1 call, 32K tokens — Shop/Product/Cart/Checkout source)
 *   5. Inner page templates (1 call, 16K tokens — About/FAQ/Contact etc. source)
 *   6. CSS: theme.css (1 call, 32K tokens — globals.css + generated PHP for class matching)
 *
 * @package B442WP
 */

declare(strict_types=1);

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/prompts/system.php';
require_once __DIR__ . '/prompts/layout.php';
require_once __DIR__ . '/prompts/homepage-gen.php';
require_once __DIR__ . '/prompts/functions-gen.php';
require_once __DIR__ . '/prompts/css-gen.php';
require_once __DIR__ . '/prompts/woocommerce-gen.php';
require_once __DIR__ . '/prompts/inner-pages-gen.php';

class Converter
{
    private ClaudeClient $claude;
    private array $conversion;
    private array $source;
    private string $prefix;
    private array $files = [];
    private string $live_scan_prefix = '';

    public function __construct(array $conversion, array $source_data)
    {
        $this->claude     = new ClaudeClient();
        $this->conversion = $conversion;
        $this->source     = $source_data;
        $this->prefix     = strtolower(
            trim(preg_replace('/[^a-z0-9]+/i', '-', $source_data['theme_name'] ?? 'theme'), '-')
        );
        if ($this->prefix === '') {
            $this->prefix = 'theme';
        }
        $this->source['prefix'] = $this->prefix;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    public function convert(): array
    {
        $this->update_status('converting');

        // Pre-compute live scan prefix once (empty string if no scan data).
        $this->live_scan_prefix = $this->build_live_scan_prefix();

        // ── 1. functions.php ────────────────────────────────────────────────
        $this->files['functions.php'] = $this->generate_functions_php();
        $this->increment_ai_calls();

        // ── 2. header.php + footer.php ──────────────────────────────────────
        $layout_files = $this->generate_header_footer();
        $this->files  = array_merge($this->files, $layout_files);
        $this->increment_ai_calls();

        // ── 3. Homepage (front-page.php + ALL template-parts) ───────────────
        $homepage_files = $this->generate_homepage();
        $this->files    = array_merge($this->files, $homepage_files);
        $this->increment_ai_calls();

        // ── 4. WooCommerce templates ────────────────────────────────────────
        if (!empty($this->source['has_woocommerce'])) {
            $woo_files   = $this->generate_woocommerce_templates();
            $this->files = array_merge($this->files, $woo_files);
            $this->increment_ai_calls();
        }

        // ── 5. Inner page templates ─────────────────────────────────────────
        $page_files  = $this->generate_inner_pages();
        $this->files = array_merge($this->files, $page_files);
        if (!empty($page_files)) {
            $this->increment_ai_calls();
        }

        // ── 6. CSS (fed with generated PHP for class matching) ──────────────
        $this->files['assets/css/theme.css'] = $this->generate_css();
        $this->increment_ai_calls();

        // ── 7. JS (no API call — template) ──────────────────────────────────
        $this->files['assets/js/theme.js'] = $this->generate_js();

        // ── 8. Demo content importer (no API call) ──────────────────────────
        $this->files['inc/demo-content.php'] = $this->generate_demo_content();

        // ── 9. WP skeleton files (no API call) ─────────────────────────────
        $this->files = array_merge($this->files, $this->load_skeleton_files());

        return $this->files;
    }

    // ─── Generation methods ──────────────────────────────────────────────────

    private function generate_functions_php(): string
    {
        $response = $this->claude->message(
            prompt_system(),
            $this->live_scan_prefix . prompt_functions_user($this->source),
            ['max_tokens' => 16384]
        );

        $code = $this->extract_code_block($response, 'php');
        $code = preg_replace('/^===\s*functions\.php\s*===\s*\n/i', '', $code) ?? $code;

        return $code;
    }

    /**
     * Generate header.php + footer.php with FULL source context.
     *
     * Feeds: Layout.jsx, Header.jsx, Footer.jsx, AnnouncementBar.jsx,
     * OriaLogo.jsx, and any other shared components used in layout.
     */
    private function generate_header_footer(): array
    {
        // Collect all layout-related source files
        $layout_sources = [];
        $layout_component_names = ['Header', 'Footer', 'AnnouncementBar', 'OriaLogo', 'Logo', 'Nav', 'Navbar', 'Navigation', 'TopBar'];

        $all_files = array_merge(
            $this->source['components'] ?? [],
            $this->source['files'] ?? [],
        );

        foreach ($all_files as $path => $content) {
            if (!is_string($content)) continue;
            $basename = pathinfo((string) $path, PATHINFO_FILENAME);

            foreach ($layout_component_names as $name) {
                if (strcasecmp($basename, $name) === 0) {
                    $layout_sources["{$basename}.jsx"] = $content;
                    break;
                }
            }
        }

        $response = $this->claude->message(
            prompt_system(),
            $this->live_scan_prefix . prompt_layout_user($this->source, $layout_sources),
            ['max_tokens' => 16384]
        );

        return $this->parse_delimited_files($response, ['header.php', 'footer.php']);
    }

    /**
     * Generate complete homepage in ONE call.
     *
     * Feeds ALL section component source code so Claude has full visual context
     * for cross-section consistency (typography scale, spacing rhythm, color use).
     */
    private function generate_homepage(): array
    {
        $home_sections = $this->source['home_sections'] ?? [];

        if (empty($home_sections)) {
            // Fallback: create a minimal front-page.php
            return [
                'front-page.php' => $this->build_minimal_front_page(),
            ];
        }

        $response = $this->claude->message(
            prompt_system(),
            $this->live_scan_prefix . prompt_homepage_user($this->source, $home_sections),
            ['max_tokens' => 32768]
        );

        // Build expected keys
        $expected = ['front-page.php'];
        foreach ($home_sections as $section) {
            $name = $section['name'] ?? 'section';
            if (strtolower($name) !== 'footer') {
                $expected[] = 'template-parts/' . $name . '.php';
            }
        }

        $files = $this->parse_delimited_files($response, $expected);

        // If front-page.php wasn't in the response, synthesise it
        if (empty($files['front-page.php'])) {
            $files['front-page.php'] = $this->build_front_page_assembler(
                array_filter(array_keys($files), fn($k) => str_starts_with($k, 'template-parts/'))
            );
        }

        return $files;
    }

    /**
     * Generate WooCommerce templates from actual React source.
     *
     * Feeds: Shop.jsx, ProductDetail.jsx, ProductCard.jsx, ShopFilters.jsx,
     * Cart.jsx, Checkout.jsx — the full source for each.
     */
    private function generate_woocommerce_templates(): array
    {
        $woo_component_names = [
            'Shop', 'ProductDetail', 'ProductCard', 'ShopFilters',
            'Cart', 'Checkout', 'CartItem', 'OrderSummary',
            'LabTestingSection',
        ];

        $woo_sources = [];
        $all_files = array_merge(
            $this->source['pages'] ?? [],
            $this->source['components'] ?? [],
            $this->source['files'] ?? [],
        );

        foreach ($all_files as $path => $content) {
            if (!is_string($content)) continue;
            $basename = pathinfo((string) $path, PATHINFO_FILENAME);

            foreach ($woo_component_names as $name) {
                if (strcasecmp($basename, $name) === 0) {
                    $woo_sources["{$basename}.jsx"] = $content;
                    break;
                }
            }
        }

        $response = $this->claude->message(
            prompt_system(),
            $this->live_scan_prefix . prompt_woocommerce_user($this->source, $woo_sources),
            ['max_tokens' => 32768]
        );

        $expected = [
            'woocommerce/archive-product.php',
            'woocommerce/single-product.php',
            'woocommerce/content-product.php',
            'woocommerce/cart/cart.php',
            'woocommerce/checkout/form-checkout.php',
            'assets/css/woocommerce.css',
        ];

        return $this->parse_delimited_files($response, $expected);
    }

    /**
     * Generate inner page templates with actual React source.
     *
     * Excludes: Home (already done), Shop/Product/Cart/Checkout (WooCommerce).
     */
    private function generate_inner_pages(): array
    {
        $pages = $this->source['pages'] ?? [];

        // Skip pages handled elsewhere
        $woo_slugs  = ['shop', 'product-detail', 'cart', 'checkout', 'category-page', 'article-detail'];
        $skip_slugs = array_merge(['home', '', '/'], $woo_slugs);

        $page_sources = [];
        foreach ($pages as $page) {
            $slug = strtolower($page['slug'] ?? '');
            if (in_array($slug, $skip_slugs, true)) {
                continue;
            }

            // Find actual source code for this page
            $source = $this->find_page_source($page);

            $page_sources[$slug] = [
                'name'   => $page['name'] ?? ucfirst($slug),
                'source' => $source,
            ];
        }

        if (empty($page_sources)) {
            return [];
        }

        $response = $this->claude->message(
            prompt_system(),
            $this->live_scan_prefix . prompt_inner_pages_user($this->source, $page_sources),
            ['max_tokens' => 16384]
        );

        $expected = [];
        foreach (array_keys($page_sources) as $slug) {
            $expected[] = 'page-' . $slug . '.php';
        }

        return $this->parse_delimited_files($response, $expected);
    }

    /**
     * Generate CSS with full context: globals.css + ALL generated PHP templates.
     */
    private function generate_css(): string
    {
        $tailwind_classes = $this->source['tailwind_classes'] ?? [];

        // Pass generated PHP files so CSS can match exact BEM classes
        $generated_for_css = [];
        foreach ($this->files as $filename => $content) {
            if (str_ends_with($filename, '.php') && $filename !== 'functions.php' && $filename !== 'inc/demo-content.php') {
                $generated_for_css[$filename] = $content;
            }
        }

        $response = $this->claude->message(
            prompt_system(),
            $this->live_scan_prefix . prompt_css_user($this->source, $tailwind_classes, $generated_for_css),
            ['max_tokens' => 32768]
        );

        $css = $this->extract_code_block($response, 'css');
        $css = preg_replace('/^===\s*assets\/css\/theme\.css\s*===\s*\n/i', '', $css) ?? $css;

        return $css;
    }

    /**
     * Generate theme.js (no API call — template).
     */
    private function generate_js(): string
    {
        $prefix     = $this->prefix;
        $theme_name = $this->source['theme_name'] ?? 'My Theme';

        return <<<JS
/**
 * {$theme_name} — assets/js/theme.js
 *
 * Vanilla JS theme behaviours. No dependencies.
 * Generated by Base44 to WordPress (base44towordpress.com)
 */

(function () {
    'use strict';

    /* ── Sticky header ───────────────────────────────────────────── */
    function initStickyHeader() {
        var header = document.querySelector('.{$prefix}-header');
        if (!header) return;

        var threshold = 60;
        function onScroll() {
            header.classList.toggle('is-sticky', window.scrollY > threshold);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    /* ── Mobile menu toggle ──────────────────────────────────────── */
    function initMobileMenu() {
        var toggle  = document.querySelector('.{$prefix}-nav__toggle');
        var drawer  = document.querySelector('.{$prefix}-nav__drawer');
        var overlay = document.querySelector('.{$prefix}-nav__overlay');
        if (!toggle || !drawer) return;

        function open() {
            drawer.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            drawer.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        toggle.addEventListener('click', function () {
            drawer.classList.contains('is-open') ? close() : open();
        });

        if (overlay) overlay.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
                close();
                toggle.focus();
            }
        });

        drawer.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', close);
        });
    }

    /* ── Scroll animations (IntersectionObserver) ────────────────── */
    function initScrollAnimations() {
        if (!('IntersectionObserver' in window)) {
            document.querySelectorAll('.theme-animate').forEach(function (el) {
                el.classList.add('theme-animate--visible');
            });
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('theme-animate--visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.theme-animate').forEach(function (el) {
            observer.observe(el);
        });
    }

    /* ── Smooth scroll for anchor links ──────────────────────────── */
    function initSmoothScroll() {
        document.addEventListener('click', function (e) {
            var anchor = e.target.closest('a[href^="#"]');
            if (!anchor) return;

            var hash = anchor.getAttribute('href');
            if (!hash || hash === '#' || hash === '#!') return;

            var target = document.querySelector(hash);
            if (!target) return;

            e.preventDefault();
            var header = document.querySelector('.{$prefix}-header');
            var offset = header ? header.offsetHeight : 0;
            var top = target.getBoundingClientRect().top + window.scrollY - offset - 16;

            window.scrollTo({ top: top, behavior: 'smooth' });
            if (history.pushState) history.pushState(null, '', hash);
        });
    }

    /* ── Accordion (FAQ, product details) ────────────────────────── */
    function initAccordions() {
        document.querySelectorAll('[data-accordion-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.getElementById(btn.getAttribute('aria-controls'));
                if (!target) return;

                var isOpen = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', String(!isOpen));
                target.style.display = isOpen ? 'none' : 'block';

                // Toggle chevron icon
                var icon = btn.querySelector('.accordion-icon');
                if (icon) icon.style.transform = isOpen ? '' : 'rotate(180deg)';
            });
        });
    }

    /* ── Active nav link ─────────────────────────────────────────── */
    function initActiveNavLink() {
        var path = window.location.pathname.replace(/\\/$/, '');
        document.querySelectorAll('.{$prefix}-nav a, .{$prefix}-header a').forEach(function (link) {
            var href = (link.getAttribute('href') || '').replace(/\\/$/, '');
            if (href && (href === path || href === window.location.href)) {
                link.classList.add('is-active');
            }
        });
    }

    /* ── Customizer live-preview ─────────────────────────────────── */
    function initCustomizerPreview() {
        if (typeof wp === 'undefined' || !wp.customize) return;

        var colorMap = {
            primary_color:    '--color-primary',
            accent_color:     '--color-accent',
            background_color: '--color-background',
            text_color:       '--color-text',
        };

        Object.keys(colorMap).forEach(function (setting) {
            wp.customize(setting, function (value) {
                value.bind(function (v) {
                    document.documentElement.style.setProperty(colorMap[setting], v);
                });
            });
        });

        ['hero_font_size', 'section_heading_size', 'body_font_size'].forEach(function (setting) {
            wp.customize(setting, function (value) {
                value.bind(function (v) {
                    var prop = '--font-size-' + setting.replace('_font_size', '').replace('_size', '-heading');
                    document.documentElement.style.setProperty(prop, v + 'rem');
                });
            });
        });
    }

    /* ── Quantity selectors (WooCommerce) ────────────────────────── */
    function initQuantitySelectors() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-qty-change]');
            if (!btn) return;

            var input = btn.closest('.qty-selector')?.querySelector('input[type="number"], .qty-value');
            if (!input) return;

            var current = parseInt(input.value || input.textContent, 10) || 1;
            var change  = parseInt(btn.getAttribute('data-qty-change'), 10);
            var newVal  = Math.max(1, current + change);

            if (input.tagName === 'INPUT') {
                input.value = newVal;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                input.textContent = newVal;
            }
        });
    }

    /* ── Boot ─────────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        initStickyHeader();
        initMobileMenu();
        initScrollAnimations();
        initSmoothScroll();
        initAccordions();
        initActiveNavLink();
        initCustomizerPreview();
        initQuantitySelectors();
    });

})();
JS;
    }

    /**
     * Generate demo content importer (no API call).
     */
    private function generate_demo_content(): string
    {
        $theme_name  = $this->source['theme_name'] ?? 'My Theme';
        $prefix      = $this->prefix;
        $text_domain = $prefix;
        $pages       = $this->source['pages'] ?? [];

        $page_entries = [];
        foreach ($pages as $page) {
            $name    = addslashes($page['name'] ?? 'Page');
            $slug    = addslashes($page['slug'] ?? 'page');
            $is_home = in_array(strtolower($slug), ['home', ''], true) ? 'true' : 'false';
            $page_entries[] = "\t\t[ 'name' => '{$name}', 'slug' => '{$slug}', 'is_home' => {$is_home} ]";
        }

        if (empty($page_entries)) {
            $page_entries = [
                "\t\t[ 'name' => 'Home',    'slug' => 'home',    'is_home' => true  ]",
                "\t\t[ 'name' => 'About',   'slug' => 'about',   'is_home' => false ]",
                "\t\t[ 'name' => 'Contact', 'slug' => 'contact', 'is_home' => false ]",
            ];
        }

        $pages_array = implode(",\n", $page_entries);

        return <<<PHP
<?php
/**
 * {$theme_name} — Demo Content Importer
 *
 * WP Admin → Tools → {$theme_name} Demo Import
 *
 * @package {$text_domain}
 */

defined( 'ABSPATH' ) || exit;

function {$prefix}_demo_import_page(): void {
    \$imported = false;
    \$errors   = [];

    if (
        isset( \$_POST['{$prefix}_demo_nonce'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( \$_POST['{$prefix}_demo_nonce'] ) ), '{$prefix}_run_demo_import' )
        && current_user_can( 'manage_options' )
    ) {
        \$result = {$prefix}_run_demo_import();
        if ( is_wp_error( \$result ) ) {
            \$errors = \$result->get_error_messages();
        } else {
            \$imported = true;
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( '{$theme_name} — Demo Import', '{$text_domain}' ); ?></h1>

        <?php if ( \$imported ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Demo content imported successfully!', '{$text_domain}' ); ?></p>
                <p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php esc_html_e( 'View your site →', '{$text_domain}' ); ?></a></p>
            </div>
        <?php endif; ?>

        <?php foreach ( \$errors as \$err ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( \$err ); ?></p></div>
        <?php endforeach; ?>

        <p><?php esc_html_e( 'Click below to create demo pages and configure navigation. Existing pages will not be duplicated.', '{$text_domain}' ); ?></p>

        <form method="post">
            <?php wp_nonce_field( '{$prefix}_run_demo_import', '{$prefix}_demo_nonce' ); ?>
            <p class="submit"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Import Demo Content', '{$text_domain}' ); ?></button></p>
        </form>
    </div>
    <?php
}

function {$prefix}_demo_get_pages(): array {
    return [
{$pages_array}
    ];
}

function {$prefix}_run_demo_import(): true|\WP_Error {
    \$pages        = {$prefix}_demo_get_pages();
    \$home_page_id = 0;
    \$menu_items   = [];

    foreach ( \$pages as \$page_data ) {
        \$slug = sanitize_title( \$page_data['slug'] );
        \$name = sanitize_text_field( \$page_data['name'] );

        \$existing = get_page_by_path( \$slug, OBJECT, 'page' );
        if ( \$existing instanceof \WP_Post ) {
            \$page_id = (int) \$existing->ID;
        } else {
            \$page_id = wp_insert_post( [ 'post_title' => \$name, 'post_name' => \$slug, 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '' ], true );
            if ( is_wp_error( \$page_id ) ) return \$page_id;
        }

        if ( ! empty( \$page_data['is_home'] ) ) {
            \$home_page_id = \$page_id;
        } else {
            \$menu_items[] = [ 'title' => \$name, 'page_id' => \$page_id ];
        }
    }

    if ( \$home_page_id > 0 ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', \$home_page_id );
    }

    \$menu_name = esc_html__( '{$theme_name} Primary Menu', '{$text_domain}' );
    \$existing_menu = wp_get_nav_menu_object( \$menu_name );
    \$menu_id = \$existing_menu ? (int) \$existing_menu->term_id : wp_create_nav_menu( \$menu_name );
    if ( is_wp_error( \$menu_id ) ) return \$menu_id;

    foreach ( \$menu_items as \$item ) {
        wp_update_nav_menu_item( \$menu_id, 0, [
            'menu-item-title'     => \$item['title'],
            'menu-item-object-id' => \$item['page_id'],
            'menu-item-object'    => 'page',
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
        ] );
    }

    \$locations = get_theme_mod( 'nav_menu_locations', [] );
    \$locations['primary'] = \$menu_id;
    set_theme_mod( 'nav_menu_locations', \$locations );

    return true;
}
PHP;
    }

    // ─── Skeleton files ──────────────────────────────────────────────────────

    private function load_skeleton_files(): array
    {
        $skeleton_dir = dirname(__DIR__) . '/wp-skeleton';
        $files        = [];

        if (!is_dir($skeleton_dir)) return $files;

        $has_woo     = !empty($this->source['has_woocommerce']);
        $description = 'Converted from Base44 by Base44 to WordPress (base44towordpress.com).'
            . ($has_woo ? ' Includes WooCommerce support.' : '');

        $replacements = [
            '{THEME_NAME}'   => $this->source['theme_name'] ?? 'My Theme',
            '{PREFIX}'       => $this->prefix,
            '{TEXT_DOMAIN}'  => $this->prefix,
            '{CONST_PREFIX}' => strtoupper($this->prefix),
            '{AUTHOR}'       => 'Base44 to WordPress',
            '{DESCRIPTION}'  => trim($description),
            '{VERSION}'      => '1.0.0',
        ];

        $tpl_files = glob($skeleton_dir . '/*.tpl') ?: [];

        foreach ($tpl_files as $tpl_path) {
            $wp_filename = preg_replace('/\.tpl$/', '', basename($tpl_path)) ?? basename($tpl_path);

            // Don't overwrite files already generated by Claude
            if (isset($this->files[$wp_filename])) continue;

            $content = file_get_contents($tpl_path);
            if ($content === false) continue;

            $content = str_replace(array_keys($replacements), array_values($replacements), $content);
            $files[$wp_filename] = $content;
        }

        return $files;
    }

    // ─── Live scan context ────────────────────────────────────────────────────

    /**
     * Build the LIVE SITE SCAN block prepended to every Claude user prompt.
     * Returns an empty string when no live scan data is available.
     */
    private function build_live_scan_prefix(): string
    {
        $scan = $this->source['live_scan'] ?? null;
        if (empty($scan)) {
            return '';
        }

        $lines = [];
        $lines[] = '## LIVE SITE SCAN (authoritative — use these values, not the zip source analysis)';
        $lines[] = '';

        // CSS custom properties from :root
        $vars = $scan['tokens']['vars'] ?? [];
        if (!empty($vars)) {
            $lines[] = '### CSS Custom Properties (:root, computed by browser)';
            foreach (array_slice($vars, 0, 40, true) as $k => $v) {
                $lines[] = "  {$k}: {$v}";
            }
            $lines[] = '';
        }

        // Computed styles for key elements
        $computed = $scan['tokens']['computed'] ?? [];
        if (!empty($computed)) {
            $lines[] = '### Computed Styles (getComputedStyle — what the browser actually renders)';
            foreach ($computed as $selector => $styles) {
                $non_empty = array_filter($styles, static fn($v) => $v !== '');
                if (empty($non_empty)) continue;
                $lines[] = "  **{$selector}**:";
                foreach ($non_empty as $prop => $val) {
                    $lines[] = "    {$prop}: {$val}";
                }
            }
            $lines[] = '';
        }

        // Google Fonts URLs
        $font_links = $scan['fontLinks'] ?? [];
        if (!empty($font_links)) {
            $lines[] = '### Google Fonts URLs (loaded by browser — use these exact URLs)';
            foreach ($font_links as $fl) {
                $lines[] = '  ' . $fl;
            }
            $lines[] = '';
        }

        // Image URLs (first 15 — gives Claude real asset URLs to reference)
        $images = $scan['images'] ?? [];
        if (!empty($images)) {
            $lines[] = '### Live Image URLs (extracted from rendered DOM)';
            foreach (array_slice($images, 0, 15) as $img) {
                $lines[] = '  ' . $img;
            }
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';

        return implode("\n", $lines);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Find the React source for a page by checking multiple possible locations.
     */
    private function find_page_source(array $page): string
    {
        $all_files = array_merge(
            $this->source['pages'] ?? [],
            $this->source['components'] ?? [],
            $this->source['files'] ?? [],
        );

        // Try: file path from page config
        if (!empty($page['file'])) {
            foreach ($all_files as $path => $content) {
                if (str_ends_with((string) $path, basename($page['file']))) {
                    return is_string($content) ? $content : '';
                }
            }
        }

        // Try: match by name
        $name = str_replace(' ', '', $page['name'] ?? '');
        $candidates = [$name, $name . 'Page', ucfirst($page['slug'] ?? '')];

        foreach ($candidates as $candidate) {
            foreach ($all_files as $path => $content) {
                if (strcasecmp(pathinfo((string) $path, PATHINFO_FILENAME), $candidate) === 0) {
                    return is_string($content) ? $content : '';
                }
            }
        }

        return '';
    }

    private function build_minimal_front_page(): string
    {
        $prefix     = $this->prefix;
        $theme_name = $this->source['theme_name'] ?? 'My Theme';

        return <<<PHP
<?php
/**
 * front-page.php — Homepage for {$theme_name}
 * @package {$prefix}
 */
defined('ABSPATH') || exit;
get_header();
?>
<main id="{$prefix}-main" class="{$prefix}-main {$prefix}-main--home" role="main">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <div class="{$prefix}-content"><?php the_content(); ?></div>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>
PHP;
    }

    private function build_front_page_assembler(array $template_part_keys): string
    {
        $prefix     = $this->prefix;
        $theme_name = $this->source['theme_name'] ?? 'My Theme';

        $part_calls = '';
        foreach ($template_part_keys as $tpl_path) {
            $part_slug   = preg_replace('/\.php$/', '', $tpl_path) ?? $tpl_path;
            $part_calls .= "\tget_template_part( '" . str_replace("'", "\\'", $part_slug) . "' );\n";
        }

        return <<<PHP
<?php
/**
 * front-page.php — Homepage for {$theme_name}
 * @package {$prefix}
 */
defined('ABSPATH') || exit;
get_header();
?>
<main id="{$prefix}-main" class="{$prefix}-main {$prefix}-main--home" role="main">
<?php
{$part_calls}?>
</main>
<?php get_footer(); ?>
PHP;
    }

    private function update_status(string $status): void
    {
        try {
            $stmt = db()->prepare('UPDATE conversions SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':status' => $status, ':id' => $this->conversion['id']]);
        } catch (\Throwable $e) {
            error_log('[Converter] update_status failed: ' . $e->getMessage());
        }
    }

    private function increment_ai_calls(int $n = 1): void
    {
        try {
            $stmt = db()->prepare('UPDATE conversions SET ai_calls_used = ai_calls_used + :n, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([':n' => $n, ':id' => $this->conversion['id']]);
        } catch (\Throwable $e) {
            error_log('[Converter] increment_ai_calls failed: ' . $e->getMessage());
        }
    }

    private function extract_code_block(string $response, string $lang = 'php'): string
    {
        $pattern = '/```' . preg_quote($lang, '/') . '\s*\n(.*?)```/si';
        if (preg_match($pattern, $response, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/```\s*\n(.*?)```/si', $response, $m)) {
            return trim($m[1]);
        }
        return trim($response);
    }

    /**
     * Parse Claude response with === filename === delimiters.
     */
    private function parse_delimited_files(string $response, array $expected_keys): array
    {
        $parts = preg_split('/^===\s*(.+?)\s*===\s*$/m', $response, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 3) {
            // Try to extract a single code block if only one file was expected
            if (count($expected_keys) === 1) {
                $code = $this->extract_code_block($response, 'php');
                if (!empty($code)) {
                    return [$expected_keys[0] => $code];
                }
            }
            return [];
        }

        $blocks = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $filename    = trim($parts[$i]);
            $raw_content = $parts[$i + 1];
            $raw_content = preg_replace('/^```[a-z]*\s*\n?/im', '', $raw_content) ?? $raw_content;
            $raw_content = preg_replace('/\n?```\s*$/m', '', $raw_content) ?? $raw_content;
            $blocks[$filename] = trim($raw_content);
        }

        $result = [];
        foreach ($expected_keys as $key) {
            // Exact match
            if (isset($blocks[$key])) {
                $result[$key] = $blocks[$key];
                continue;
            }
            // Basename fallback
            $base = basename($key);
            foreach ($blocks as $fn => $content) {
                if (basename($fn) === $base) {
                    $result[$key] = $content;
                    break;
                }
            }
            // Path-contains fallback (for nested paths like woocommerce/archive-product.php)
            if (!isset($result[$key])) {
                foreach ($blocks as $fn => $content) {
                    if (str_contains($fn, $key) || str_contains($key, $fn)) {
                        $result[$key] = $content;
                        break;
                    }
                }
            }
        }

        return $result;
    }
}
