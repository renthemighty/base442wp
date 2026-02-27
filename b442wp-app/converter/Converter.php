<?php
/**
 * Converter — Stage 3 orchestrator for the B442WP pipeline.
 *
 * Drives all Claude API calls to transform parsed + analysed Base44 source
 * data into a complete set of WordPress theme PHP/CSS/JS files.
 *
 * @package B442WP
 */

declare(strict_types=1);

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/prompts/system.php';
require_once __DIR__ . '/prompts/layout.php';
require_once __DIR__ . '/prompts/home-section.php';
require_once __DIR__ . '/prompts/functions-gen.php';
require_once __DIR__ . '/prompts/css-gen.php';

class Converter
{
    private ClaudeClient $claude;
    private array $conversion;   // DB row for this conversion
    private array $source;       // merged parsed + analyzed data
    private string $prefix;      // CSS/PHP prefix e.g. "malle"
    private array $files = [];   // accumulates generated file content

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
        // Inject prefix into source so prompt helpers can reference it
        $this->source['prefix'] = $this->prefix;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Run the full conversion pipeline.
     *
     * @return array<string, string>  ['relative_filename' => 'file_content', ...]
     * @throws RuntimeException       On any unrecoverable generation failure.
     */
    public function convert(): array
    {
        $this->update_status('converting');

        // 1. functions.php
        $this->files['functions.php'] = $this->generate_functions_php();
        $this->increment_ai_calls();

        // 2. header.php + footer.php
        $layout_files  = $this->generate_header_footer();
        $this->files   = array_merge($this->files, $layout_files);
        $this->increment_ai_calls();

        // 3. Homepage template parts + front-page.php
        $homepage_files = $this->generate_homepage();
        $this->files    = array_merge($this->files, $homepage_files);
        $this->increment_ai_calls(count($this->source['sections'] ?? []) ?: 1);

        // 4. Additional page templates (one Claude call for all pages)
        $page_files  = $this->generate_page_templates();
        $this->files = array_merge($this->files, $page_files);
        if (!empty($page_files)) {
            $this->increment_ai_calls();
        }

        // 5. CSS
        $this->files['assets/css/theme.css'] = $this->generate_css();
        $this->increment_ai_calls();

        // 6. JS — no AI call (rule: keep API costs low for JS)
        $this->files['assets/js/theme.js'] = $this->generate_js();

        // 7. WooCommerce (if applicable)
        if (!empty($this->source['has_woocommerce'])) {
            $woo_files   = $this->generate_woocommerce_templates();
            $this->files = array_merge($this->files, $woo_files);
            $this->increment_ai_calls(2);
        }

        // 8. Demo content importer — no AI call
        $this->files['inc/demo-content.php'] = $this->generate_demo_content();
        $this->increment_ai_calls();

        // 9. Standard WP skeleton files — not AI-generated
        $this->files = array_merge($this->files, $this->load_skeleton_files());

        return $this->files;
    }

    // ─── Private generation methods ───────────────────────────────────────────

    /**
     * Generate functions.php via Claude.
     *
     * Uses prompt_system() as system prompt and prompt_functions_user() as the user turn.
     * Extracts the PHP code block from the response.
     */
    private function generate_functions_php(): string
    {
        $response = $this->claude->message(
            prompt_system(),
            prompt_functions_user($this->source),
            ['max_tokens' => 8192]
        );

        $code = $this->extract_code_block($response, 'php');

        // Strip the === functions.php === delimiter line if Claude included it
        $code = preg_replace('/^===\s*functions\.php\s*===\s*\n/i', '', $code) ?? $code;

        return $code;
    }

    /**
     * Generate header.php and footer.php in a single Claude call.
     *
     * Parses the response looking for === header.php === and === footer.php === delimiters.
     *
     * @return array<string, string>
     */
    private function generate_header_footer(): array
    {
        // Include Layout.jsx and globals.css as attached source files when available
        $attached = [];
        if (!empty($this->source['layout_jsx'])) {
            $attached[] = ['name' => 'Layout.jsx', 'content' => $this->source['layout_jsx']];
        }
        if (!empty($this->source['globals_css'])) {
            $attached[] = ['name' => 'globals.css', 'content' => $this->source['globals_css']];
        }

        if (!empty($attached)) {
            $response = $this->claude->message_with_files(
                prompt_system(),
                prompt_layout_user($this->source),
                $attached
            );
        } else {
            $response = $this->claude->message(
                prompt_system(),
                prompt_layout_user($this->source)
            );
        }

        return $this->parse_delimited_files($response, ['header.php', 'footer.php']);
    }

    /**
     * Generate all homepage section template-parts plus front-page.php.
     *
     * For each section in $this->source['sections'], one Claude call is made.
     * The result is stored as template-parts/{section_name}.php.
     * Then front-page.php is synthesised (no extra API call) to assemble them.
     *
     * @return array<string, string>
     */
    private function generate_homepage(): array
    {
        $files   = [];
        $sections = $this->source['sections'] ?? [];

        foreach ($sections as $key => $value) {
            // Support both list format (int key, section name as value)
            // and map format (section name as key, source code as value)
            if (is_int($key)) {
                $section_name   = (string) $value;
                $section_source = $this->find_section_source($section_name);
            } else {
                $section_name   = (string) $key;
                $section_source = (string) $value;
                if (empty($section_source)) {
                    $section_source = $this->find_section_source($section_name);
                }
            }

            // footer is handled by generate_header_footer; skip it here
            if (strtolower($section_name) === 'footer') {
                continue;
            }

            $response = $this->claude->message(
                prompt_system(),
                prompt_home_section_user($this->source, $section_name, $section_source),
                ['max_tokens' => 4096]
            );

            $tpl_key        = 'template-parts/' . $section_name . '.php';
            $parsed         = $this->parse_delimited_files($response, [$tpl_key]);
            $files[$tpl_key] = !empty($parsed[$tpl_key])
                ? $parsed[$tpl_key]
                : $this->extract_code_block($response, 'php');
        }

        // Build front-page.php without a Claude call
        $files['front-page.php'] = $this->generate_front_page_assembler(array_keys($files));

        return $files;
    }

    /**
     * Synthesise front-page.php that calls get_template_part() for each section.
     *
     * No API call — pure PHP string construction.
     *
     * @param  string[] $template_part_keys  e.g. ['template-parts/hero.php', ...]
     * @return string
     */
    private function generate_front_page_assembler(array $template_part_keys): string
    {
        $prefix     = $this->prefix;
        $theme_name = $this->source['theme_name'] ?? 'My Theme';

        $part_calls = '';
        foreach ($template_part_keys as $tpl_path) {
            // 'template-parts/hero.php' → 'template-parts/hero'
            $part_slug   = preg_replace('/\.php$/', '', $tpl_path) ?? $tpl_path;
            $part_calls .= "\tget_template_part( '" . str_replace("'", "\\'", $part_slug) . "' );\n";
        }

        return <<<PHP
<?php
/**
 * front-page.php — Homepage template for {$theme_name}
 *
 * Assembles the homepage by loading individual section template-parts.
 * Configure the static front page at: Settings → Reading.
 *
 * @package {$prefix}
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="{$prefix}-main" class="{$prefix}-main {$prefix}-main--home" role="main">
<?php
{$part_calls}?>
</main>

<?php get_footer(); ?>
PHP;
    }

    /**
     * Generate all non-home page templates in a single Claude call.
     *
     * Batching all pages into one call avoids N additional API round-trips.
     *
     * @return array<string, string>
     */
    private function generate_page_templates(): array
    {
        $pages = $this->source['pages'] ?? [];

        // Filter out the home page
        $non_home = array_filter($pages, static function (array $page): bool {
            return !in_array(strtolower($page['slug'] ?? ''), ['', 'home', '/'], true);
        });

        if (empty($non_home)) {
            return [];
        }

        // Build the pages listing section of the prompt
        $pages_list    = '';
        $expected_keys = [];
        foreach ($non_home as $page) {
            $slug           = trim($page['slug'] ?? 'page');
            $name           = $page['name']  ?? ucfirst($slug);
            $pages_list    .= "- {$name} (slug: {$slug}), template file: page-{$slug}.php\n";
            $expected_keys[] = 'page-' . $slug . '.php';
        }

        // Include relevant source for these pages (budget-limited)
        $source_context = $this->build_source_context();
        $context        = $this->build_context_string();

        $user_prompt = <<<PROMPT
{$context}

## Source components (for reference)
{$source_context}

---

## Your task

Generate WordPress page templates for the following pages (one file each):

{$pages_list}

### Requirements for every template
1. Begin with `<?php get_header(); ?>`
2. Include a `<main>` section using `have_posts()` / `the_post()` loop with `the_content()`
3. Add an above-content section (page hero/intro) that uses `get_theme_mod()` for configurable heading and description text — derive sensible defaults from the source
4. BEM class names using the `{$this->prefix}` prefix
5. All strings wrapped in `esc_html__('...', '{$this->prefix}')`
6. All URLs wrapped in `esc_url()`
7. End with `<?php get_footer(); ?>`
8. No inline `<style>` or `<script>` blocks

### Output format

Output each file separated by its delimiter:

=== page-SLUG.php ===
[complete template content]

(repeat for each page, replacing SLUG with the actual slug)
PROMPT;

        $response = $this->claude->message(prompt_system(), $user_prompt, ['max_tokens' => 8192]);

        return $this->parse_delimited_files($response, $expected_keys);
    }

    /**
     * Generate assets/css/theme.css via Claude.
     *
     * Attaches globals.css when available so Claude can derive exact values.
     */
    private function generate_css(): string
    {
        $tailwind_classes = $this->source['tailwind_classes'] ?? [];

        $attached = [];
        if (!empty($this->source['globals_css'])) {
            $attached[] = ['name' => 'globals.css', 'content' => $this->source['globals_css']];
        }

        if (!empty($attached)) {
            $response = $this->claude->message_with_files(
                prompt_system(),
                prompt_css_user($this->source, $tailwind_classes),
                $attached
            );
        } else {
            $response = $this->claude->message(
                prompt_system(),
                prompt_css_user($this->source, $tailwind_classes),
                ['max_tokens' => 8192]
            );
        }

        $css = $this->extract_code_block($response, 'css');

        // Strip the delimiter line if Claude included it
        $css = preg_replace('/^===\s*assets\/css\/theme\.css\s*===\s*\n/i', '', $css) ?? $css;

        return $css;
    }

    /**
     * Generate assets/js/theme.js WITHOUT a Claude API call.
     *
     * Implements:
     * - Sticky header on scroll (adds .is-sticky at scrollY > 80)
     * - Mobile menu toggle (.is-open on drawer, aria-expanded on button)
     * - IntersectionObserver for .{prefix}-animate → .{prefix}-animate--visible
     * - Smooth scroll for anchor links
     * - wp_localize_script compatible structure (reads {prefix}ThemeData global)
     *
     * @return string
     */
    private function generate_js(): string
    {
        $prefix      = $this->prefix;
        $theme_name  = $this->source['theme_name'] ?? 'My Theme';
        $animate     = $prefix . '-animate';
        $animate_vis = $prefix . '-animate--visible';

        return <<<JS
/**
 * {$theme_name} — assets/js/theme.js
 *
 * Vanilla JS theme behaviours. No dependencies. ES5-compatible.
 * Integrates with wp_localize_script via window.{$prefix}ThemeData.
 *
 * Generated by Base44 to WordPress (base44towordpress.com)
 */

( function () {
    'use strict';

    /* ── Theme data injected by wp_localize_script ────────────────────────── */
    var themeData = ( typeof window['{$prefix}ThemeData'] !== 'undefined' )
        ? window['{$prefix}ThemeData']
        : {};

    /* ── Sticky header ────────────────────────────────────────────────────── */
    function initStickyHeader() {
        var header    = document.querySelector( '.{$prefix}-header' );
        var threshold = 80;

        if ( ! header ) { return; }

        function onScroll() {
            if ( window.scrollY > threshold ) {
                header.classList.add( 'is-sticky' );
            } else {
                header.classList.remove( 'is-sticky' );
            }
        }

        window.addEventListener( 'scroll', onScroll, { passive: true } );
        onScroll(); // run once on page load
    }

    /* ── Mobile menu toggle ───────────────────────────────────────────────── */
    function initMobileMenu() {
        var toggle  = document.querySelector( '.{$prefix}-nav__toggle' );
        var drawer  = document.querySelector( '.{$prefix}-nav__drawer' );

        if ( ! toggle || ! drawer ) { return; }

        function open() {
            drawer.classList.add( 'is-open' );
            toggle.classList.add( 'is-active' );
            toggle.setAttribute( 'aria-expanded', 'true' );
            document.body.style.overflow = 'hidden';
        }

        function close() {
            drawer.classList.remove( 'is-open' );
            toggle.classList.remove( 'is-active' );
            toggle.setAttribute( 'aria-expanded', 'false' );
            document.body.style.overflow = '';
        }

        toggle.addEventListener( 'click', function () {
            if ( drawer.classList.contains( 'is-open' ) ) {
                close();
            } else {
                open();
            }
        } );

        // Close on Escape
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && drawer.classList.contains( 'is-open' ) ) {
                close();
                toggle.focus();
            }
        } );

        // Close when a menu link is tapped
        drawer.querySelectorAll( 'a' ).forEach( function ( link ) {
            link.addEventListener( 'click', close );
        } );

        // Close on backdrop click (if a backdrop/overlay element is present)
        var overlay = document.querySelector( '.{$prefix}-nav__overlay' );
        if ( overlay ) {
            overlay.addEventListener( 'click', close );
        }
    }

    /* ── Scroll animations (IntersectionObserver) ─────────────────────────── */
    function initScrollAnimations() {
        var animateClass  = '.{$animate}';
        var visibleClass  = '{$animate_vis}';

        if ( ! ( 'IntersectionObserver' in window ) ) {
            // Graceful fallback: reveal immediately for older browsers
            document.querySelectorAll( animateClass ).forEach( function ( el ) {
                el.classList.add( visibleClass );
            } );
            return;
        }

        var observer = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    entry.target.classList.add( visibleClass );
                    observer.unobserve( entry.target );
                }
            } );
        }, { threshold: 0.1 } );

        document.querySelectorAll( animateClass ).forEach( function ( el ) {
            observer.observe( el );
        } );
    }

    /* ── Smooth scroll for anchor links ───────────────────────────────────── */
    function initSmoothScroll() {
        document.addEventListener( 'click', function ( e ) {
            var anchor = e.target.closest( 'a[href^="#"]' );
            if ( ! anchor ) { return; }

            var hash = anchor.getAttribute( 'href' );
            if ( ! hash || hash === '#' || hash === '#!' ) { return; }

            var target = document.querySelector( hash );
            if ( ! target ) { return; }

            e.preventDefault();

            var header       = document.querySelector( '.{$prefix}-header' );
            var headerOffset = header ? header.offsetHeight : 0;
            var top          = target.getBoundingClientRect().top
                             + window.scrollY
                             - headerOffset
                             - 16; // 1rem breathing room

            window.scrollTo( { top: top, behavior: 'smooth' } );

            // Update URL without triggering a jump
            if ( history.pushState ) {
                history.pushState( null, '', hash );
            }
        } );
    }

    /* ── Active nav link highlight ────────────────────────────────────────── */
    function initActiveNavLink() {
        var currentPath = window.location.pathname.replace( /\/$/, '' );

        document.querySelectorAll( '.{$prefix}-nav a' ).forEach( function ( link ) {
            var href = ( link.getAttribute( 'href' ) || '' ).replace( /\/$/, '' );
            // Exact match or hash-only anchor pointing to current page
            if ( href && ( href === currentPath || href === window.location.href ) ) {
                link.classList.add( 'is-active' );
            }
        } );
    }

    /* ── Customizer live-preview CSS variable updates ─────────────────────── */
    function initCustomizerPreview() {
        if ( typeof wp === 'undefined' || ! wp.customize ) { return; }

        var colorMap = {
            primary_color:    '--color-primary',
            accent_color:     '--color-accent',
            background_color: '--color-background',
            text_color:       '--color-text',
        };

        Object.keys( colorMap ).forEach( function ( setting ) {
            wp.customize( setting, function ( value ) {
                value.bind( function ( newVal ) {
                    document.documentElement.style.setProperty( colorMap[ setting ], newVal );
                } );
            } );
        } );

        wp.customize( 'hero_font_size', function ( value ) {
            value.bind( function ( v ) {
                document.documentElement.style.setProperty( '--font-size-hero', v + 'rem' );
            } );
        } );

        wp.customize( 'section_heading_size', function ( value ) {
            value.bind( function ( v ) {
                document.documentElement.style.setProperty( '--font-size-section-heading', v + 'rem' );
            } );
        } );

        wp.customize( 'body_font_size', function ( value ) {
            value.bind( function ( v ) {
                document.documentElement.style.setProperty( '--font-size-body', v + 'rem' );
            } );
        } );
    }

    /* ── Boot ─────────────────────────────────────────────────────────────── */
    document.addEventListener( 'DOMContentLoaded', function () {
        initStickyHeader();
        initMobileMenu();
        initScrollAnimations();
        initSmoothScroll();
        initActiveNavLink();
        initCustomizerPreview();
    } );

} )();
JS;
    }

    /**
     * Generate WooCommerce template overrides via Claude.
     *
     * Generates:
     *   - woocommerce/single-product.php
     *   - woocommerce/archive-product.php
     *   - woocommerce/content-product.php
     *   - woocommerce/cart/cart.php
     *   - woocommerce/checkout/form-checkout.php
     *   - assets/css/woocommerce.css
     *
     * @return array<string, string>
     */
    private function generate_woocommerce_templates(): array
    {
        $context        = $this->build_context_string();
        $entities       = $this->source['entities'] ?? [];
        $entities_ctx   = '';
        foreach ($entities as $entity) {
            $fields        = is_array($entity['fields'] ?? null)
                ? implode(', ', $entity['fields'])
                : '';
            $entities_ctx .= '- ' . ($entity['name'] ?? 'entity') . ": [{$fields}]\n";
        }

        $user_prompt = <<<PROMPT
{$context}

## Data entities
{$entities_ctx}

---

## Your task

Generate WooCommerce template overrides for the **{$this->source['theme_name']}** theme.

### Files required

1. `woocommerce/archive-product.php` — Shop / product archive page
2. `woocommerce/single-product.php` — Single product detail page
3. `woocommerce/content-product.php` — Product card used within the shop grid
4. `woocommerce/cart/cart.php` — Cart page
5. `woocommerce/checkout/form-checkout.php` — Checkout page wrapper
6. `assets/css/woocommerce.css` — WooCommerce-specific CSS additions

### General requirements
- All PHP templates: `defined('ABSPATH') || exit;` at the top
- Use standard WooCommerce template hooks (`woocommerce_before_main_content`, etc.)
- BEM class names with `{$this->prefix}` prefix for any added wrapper markup
- CSS must reference theme CSS custom properties (`--color-primary`, etc.)
- No inline `<style>` or `<script>` blocks
- `get_header()` / `get_footer()` in page-level templates (archive, single, cart, checkout)

### Output format

=== woocommerce/archive-product.php ===
[content]

=== woocommerce/single-product.php ===
[content]

=== woocommerce/content-product.php ===
[content]

=== woocommerce/cart/cart.php ===
[content]

=== woocommerce/checkout/form-checkout.php ===
[content]

=== assets/css/woocommerce.css ===
[content]
PROMPT;

        $response = $this->claude->message(prompt_system(), $user_prompt, ['max_tokens' => 8192]);

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
     * Generate inc/demo-content.php WITHOUT a Claude API call.
     *
     * The generated file provides a WP Admin → Tools → {Theme} Demo Import page
     * that creates all pages from $this->source['pages'], sets the static front
     * page, and registers the primary navigation menu in one click.
     *
     * @return string
     */
    private function generate_demo_content(): string
    {
        $theme_name  = $this->source['theme_name'] ?? 'My Theme';
        $prefix      = $this->prefix;
        $text_domain = $prefix;
        $pages       = $this->source['pages'] ?? [];

        // Build the PHP array literal for the pages list
        $page_entries = [];
        foreach ($pages as $page) {
            $name    = addslashes($page['name'] ?? 'Page');
            $slug    = addslashes($page['slug'] ?? 'page');
            $is_home = in_array(strtolower($slug), ['home', ''], true) ? 'true' : 'false';
            $page_entries[] = "\t\t[ 'name' => '{$name}', 'slug' => '{$slug}', 'is_home' => {$is_home} ]";
        }

        // Fall back to sensible defaults when no pages were detected
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
 * Accessible at: WP Admin → Tools → {$theme_name} Demo Import
 *
 * On import:
 *   1. Creates all theme pages (skips duplicates by slug)
 *   2. Sets the static front page (Reading Settings)
 *   3. Creates and registers the primary navigation menu
 *
 * @package {$text_domain}
 */

defined( 'ABSPATH' ) || exit;

// ─── Admin page callback ──────────────────────────────────────────────────────

/**
 * Render the Demo Import admin page.
 * Registered in functions.php via add_management_page().
 */
function {$prefix}_demo_import_page(): void {
    \$imported = false;
    \$errors   = [];

    if (
        isset( \$_POST['{$prefix}_demo_nonce'] )
        && wp_verify_nonce(
            sanitize_text_field( wp_unslash( \$_POST['{$prefix}_demo_nonce'] ) ),
            '{$prefix}_run_demo_import'
        )
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
                <p><?php esc_html_e( 'Demo content imported successfully! Your front page has been configured.', '{$text_domain}' ); ?></p>
                <p>
                    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank">
                        <?php esc_html_e( 'View your site →', '{$text_domain}' ); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <?php foreach ( \$errors as \$err ) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html( \$err ); ?></p>
            </div>
        <?php endforeach; ?>

        <p><?php esc_html_e( 'Click the button below to create the theme demo pages and configure your navigation menu. Pages that already exist will not be duplicated.', '{$text_domain}' ); ?></p>

        <table class="widefat" style="max-width:560px;margin-bottom:1.5rem;">
            <thead>
                <tr><th colspan="2"><?php esc_html_e( 'Content that will be created', '{$text_domain}' ); ?></th></tr>
            </thead>
            <tbody>
                <?php foreach ( {$prefix}_demo_get_pages() as \$pg ) : ?>
                    <tr>
                        <td><?php echo esc_html( \$pg['name'] ); ?></td>
                        <td>
                            <?php if ( \$pg['is_home'] ) : ?>
                                <span class="description"><?php esc_html_e( '(front page)', '{$text_domain}' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td colspan="2"><?php esc_html_e( 'Primary navigation menu', '{$text_domain}' ); ?></td>
                </tr>
            </tbody>
        </table>

        <form method="post">
            <?php wp_nonce_field( '{$prefix}_run_demo_import', '{$prefix}_demo_nonce' ); ?>
            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <?php esc_html_e( 'Import Demo Content', '{$text_domain}' ); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

// ─── Data ─────────────────────────────────────────────────────────────────────

/**
 * Return the list of demo pages to create.
 *
 * @return list<array{name: string, slug: string, is_home: bool}>
 */
function {$prefix}_demo_get_pages(): array {
    return [
{$pages_array}
    ];
}

// ─── Import runner ────────────────────────────────────────────────────────────

/**
 * Execute the one-click demo import.
 *
 * Creates pages, sets front page, registers primary nav menu.
 *
 * @return true|\WP_Error
 */
function {$prefix}_run_demo_import(): true|\WP_Error {
    \$pages        = {$prefix}_demo_get_pages();
    \$home_page_id = 0;
    \$menu_items   = [];

    foreach ( \$pages as \$page_data ) {
        \$slug = sanitize_title( \$page_data['slug'] );
        \$name = sanitize_text_field( \$page_data['name'] );

        // Skip creation if page already exists
        \$existing = get_page_by_path( \$slug, OBJECT, 'page' );
        if ( \$existing instanceof \WP_Post ) {
            \$page_id = (int) \$existing->ID;
        } else {
            \$page_id = wp_insert_post(
                [
                    'post_title'   => \$name,
                    'post_name'    => \$slug,
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_content' => '',
                ],
                true
            );
            if ( is_wp_error( \$page_id ) ) {
                return \$page_id;
            }
        }

        if ( ! empty( \$page_data['is_home'] ) ) {
            \$home_page_id = \$page_id;
        } else {
            \$menu_items[] = [ 'title' => \$name, 'page_id' => \$page_id ];
        }
    }

    // Set static front page
    if ( \$home_page_id > 0 ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', \$home_page_id );
    }

    // Create or locate the primary nav menu
    \$menu_name     = esc_html__( '{$theme_name} Primary Menu', '{$text_domain}' );
    \$existing_menu = wp_get_nav_menu_object( \$menu_name );
    if ( \$existing_menu ) {
        \$menu_id = (int) \$existing_menu->term_id;
    } else {
        \$menu_id = wp_create_nav_menu( \$menu_name );
        if ( is_wp_error( \$menu_id ) ) {
            return \$menu_id;
        }
    }

    // Add page items to the menu
    foreach ( \$menu_items as \$item ) {
        wp_update_nav_menu_item(
            \$menu_id,
            0,
            [
                'menu-item-title'     => \$item['title'],
                'menu-item-object-id' => \$item['page_id'],
                'menu-item-object'    => 'page',
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
            ]
        );
    }

    // Assign menu to the 'primary' theme location
    \$locations            = get_theme_mod( 'nav_menu_locations', [] );
    \$locations['primary'] = \$menu_id;
    set_theme_mod( 'nav_menu_locations', \$locations );

    return true;
}
PHP;
    }

    /**
     * Load and process all *.tpl files from wp-skeleton/.
     *
     * Replaces template placeholders with theme-specific values.
     *
     * Supported placeholders:
     *   {THEME_NAME}   — human-readable theme name
     *   {PREFIX}       — CSS/PHP function prefix
     *   {TEXT_DOMAIN}  — WordPress text domain (same as prefix)
     *   {CONST_PREFIX} — upper-cased prefix for PHP constants
     *   {AUTHOR}       — theme author
     *   {DESCRIPTION}  — theme description
     *   {VERSION}      — theme version
     *
     * @return array<string, string>
     */
    private function load_skeleton_files(): array
    {
        $skeleton_dir = dirname(__DIR__) . '/wp-skeleton';
        $files        = [];

        if (!is_dir($skeleton_dir)) {
            return $files;
        }

        $has_woo     = !empty($this->source['has_woocommerce']);
        $description = ($this->source['description'] ?? '')
            . ' Converted from Base44 by Base44 to WordPress (base44towordpress.com).'
            . ($has_woo ? ' Includes WooCommerce support.' : '');

        $replacements = [
            '{THEME_NAME}'   => $this->source['theme_name'] ?? 'My Theme',
            '{PREFIX}'       => $this->prefix,
            '{TEXT_DOMAIN}'  => $this->prefix,
            '{CONST_PREFIX}' => strtoupper($this->prefix),
            '{AUTHOR}'       => $this->source['author']     ?? 'Base44 to WordPress',
            '{DESCRIPTION}'  => trim($description),
            '{VERSION}'      => '1.0.0',
        ];

        $tpl_files = glob($skeleton_dir . '/*.tpl');
        if ($tpl_files === false) {
            return $files;
        }

        foreach ($tpl_files as $tpl_path) {
            // Remove the .tpl extension to get the WordPress filename
            $wp_filename = preg_replace('/\.tpl$/', '', basename($tpl_path)) ?? basename($tpl_path);

            $content = file_get_contents($tpl_path);
            if ($content === false) {
                continue;
            }

            $content = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $content
            );

            $files[$wp_filename] = $content;
        }

        return $files;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Update the conversion row status in the database.
     */
    private function update_status(string $status): void
    {
        try {
            $stmt = db()->prepare(
                'UPDATE conversions SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $status,
                ':id'     => $this->conversion['id'],
            ]);
        } catch (\Throwable $e) {
            // Non-fatal: log but do not interrupt the conversion
            error_log('[Converter] update_status failed: ' . $e->getMessage());
        }
    }

    /**
     * Increment the ai_calls_used counter for the current conversion row.
     */
    private function increment_ai_calls(int $n = 1): void
    {
        try {
            $stmt = db()->prepare(
                'UPDATE conversions SET ai_calls_used = ai_calls_used + :n, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $stmt->execute([
                ':n'  => $n,
                ':id' => $this->conversion['id'],
            ]);
        } catch (\Throwable $e) {
            error_log('[Converter] increment_ai_calls failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract the content of a fenced code block from Claude's response.
     *
     * Looks for ``` {$lang} ... ``` blocks. If the language-specific fence is
     * not found, tries a generic fence. Falls back to returning the full
     * trimmed response if no fence markers are present.
     */
    private function extract_code_block(string $response, string $lang = 'php'): string
    {
        // Language-specific: ```php ... ```
        $pattern = '/```' . preg_quote($lang, '/') . '\s*\n(.*?)```/si';
        if (preg_match($pattern, $response, $m)) {
            return trim($m[1]);
        }

        // Generic fence: ``` ... ```
        if (preg_match('/```\s*\n(.*?)```/si', $response, $m)) {
            return trim($m[1]);
        }

        // No fences found — return raw response
        return trim($response);
    }

    /**
     * Build a compact context string summarising key source data.
     *
     * Used in prompts where a brief reminder of the theme context is helpful
     * without repeating the full source payload.
     *
     * @return string
     */
    private function build_context_string(): string
    {
        $theme_name      = $this->source['theme_name']     ?? 'My Theme';
        $has_woocommerce = !empty($this->source['has_woocommerce']) ? 'yes' : 'no';

        // Colors
        $colors_lines = '';
        foreach ($this->source['colors'] ?? [] as $key => $val) {
            $colors_lines .= "  --color-{$key}: {$val}\n";
        }
        if ($colors_lines === '') {
            $colors_lines = "  (none extracted)\n";
        }

        // Fonts
        $fonts     = $this->source['fonts'] ?? [];
        $font_info = 'Primary: ' . ($fonts['primary'] ?? 'system-ui, sans-serif')
            . "\n  Secondary: " . ($fonts['secondary'] ?? 'system-ui, sans-serif');

        // Pages
        $pages_lines = '';
        foreach ($this->source['pages'] ?? [] as $page) {
            $pages_lines .= "  - {$page['name']} (/{$page['slug']})\n";
        }
        if ($pages_lines === '') {
            $pages_lines = "  (none)\n";
        }

        // Sections
        $sections      = $this->source['sections'] ?? [];
        $section_names = [];
        foreach ($sections as $k => $v) {
            $section_names[] = is_int($k) ? (string) $v : (string) $k;
        }
        $sections_line = empty($section_names)
            ? '  (none)'
            : '  ' . implode(', ', $section_names);

        return <<<CTX
## Theme: {$theme_name}
## CSS / PHP prefix: {$this->prefix}
## WooCommerce: {$has_woocommerce}

### Colors
{$colors_lines}
### Fonts
  {$font_info}

### Pages
{$pages_lines}
### Homepage sections
{$sections_line}
CTX;
    }

    /**
     * Parse a Claude response that contains multiple files separated by
     * `=== filename.ext ===` delimiters.
     *
     * Only files whose keys appear in $expected_keys are returned.
     * Any code fences inside each block are stripped automatically.
     *
     * @param  string   $response
     * @param  string[] $expected_keys  Filenames/paths to extract.
     * @return array<string, string>
     */
    private function parse_delimited_files(string $response, array $expected_keys): array
    {
        // Split on === ... === delimiters, capturing the filename
        $parts = preg_split('/^===\s*(.+?)\s*===\s*$/m', $response, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 3) {
            return [];
        }

        // $parts[0] = preamble (ignore), then alternating: filename, content
        $blocks = [];
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $filename            = trim($parts[$i]);
            $raw_content         = $parts[$i + 1];
            // Strip leading/trailing code fences
            $raw_content         = preg_replace('/^```[a-z]*\s*\n?/im', '', $raw_content) ?? $raw_content;
            $raw_content         = preg_replace('/\n?```\s*$/m', '', $raw_content) ?? $raw_content;
            $blocks[$filename]   = trim($raw_content);
        }

        $result = [];
        foreach ($expected_keys as $key) {
            // Exact match first
            if (isset($blocks[$key])) {
                $result[$key] = $blocks[$key];
                continue;
            }
            // Basename fallback (e.g. Claude omits subdirectory)
            $base = basename($key);
            foreach ($blocks as $fn => $content) {
                if (basename($fn) === $base) {
                    $result[$key] = $content;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Find the most relevant source code string for a given section name.
     *
     * Searches source components, pages, and file maps for a filename that
     * matches (or partially matches) the section name. Returns a sensible
     * fallback comment string when nothing is found.
     *
     * @return string
     */
    private function find_section_source(string $section_name): string
    {
        $pool = array_merge(
            $this->source['components'] ?? [],
            $this->source['pages']      ?? [],
            $this->source['files']      ?? [],
        );

        $candidates = [
            $section_name,
            ucfirst($section_name),
            ucfirst($section_name) . 'Section',
            ucfirst($section_name) . 'Component',
        ];

        // Exact filename match
        foreach ($candidates as $c) {
            foreach ($pool as $path => $content) {
                if (strcasecmp(pathinfo((string) $path, PATHINFO_FILENAME), $c) === 0) {
                    return is_string($content) ? $content : '';
                }
            }
        }

        // Partial match on filename
        foreach ($pool as $path => $content) {
            if (str_contains(strtolower(pathinfo((string) $path, PATHINFO_FILENAME)), strtolower($section_name))) {
                return is_string($content) ? $content : '';
            }
        }

        // Home/index fallback — first 6000 characters
        foreach ($pool as $path => $content) {
            $base = strtolower(pathinfo((string) $path, PATHINFO_FILENAME));
            if (in_array($base, ['home', 'index', 'homepage', 'landing'], true)) {
                return substr(is_string($content) ? $content : '', 0, 6000);
            }
        }

        return "/* No matching source component found for section: {$section_name} */";
    }

    /**
     * Build a compact source context string from the most relevant source files.
     *
     * Imposes a character budget to keep prompts within token limits.
     *
     * @return string
     */
    private function build_source_context(): string
    {
        $context = '';
        $budget  = 20_000;
        $used    = 0;

        $pool = array_merge(
            $this->source['pages']      ?? [],
            $this->source['components'] ?? [],
        );

        foreach ($pool as $path => $content) {
            if ($used >= $budget) {
                break;
            }
            $content = is_string($content) ? $content : '';
            $chunk   = strlen($content) > 4_000
                ? substr($content, 0, 4_000) . "\n// [truncated]"
                : $content;
            $ext     = pathinfo((string) $path, PATHINFO_EXTENSION);
            $lang    = in_array($ext, ['tsx', 'jsx'], true) ? 'jsx' : 'js';
            $context .= "=== {$path} ===\n```{$lang}\n{$chunk}\n```\n\n";
            $used    += strlen($chunk);
        }

        return $context;
    }
}
