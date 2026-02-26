<?php
/**
 * How Screwed Am I? – Theme Functions
 *
 * Converts the Base44 React TOS-analyzer app into a WordPress theme.
 * Design tokens: Orange #e8983f / #F7941D, Dark navy #1B2A5C, Cream #fcf7ee.
 *
 * @package screwed
 * @version 1.0.0
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'SCREWED_VERSION', '1.0.0' );
define( 'SCREWED_URI', get_template_directory_uri() );

// ---------------------------------------------------------------------------
// Theme Setup
// ---------------------------------------------------------------------------

add_action( 'after_setup_theme', 'screwed_setup' );
/**
 * Sets up theme defaults and registers support for various WordPress features.
 */
function screwed_setup() {
    // Let WordPress manage the document title.
    add_theme_support( 'title-tag' );

    // Custom logo support.
    add_theme_support( 'custom-logo', array(
        'height'               => 60,
        'width'                => 200,
        'flex-height'          => true,
        'flex-width'           => true,
        'header-text'          => array( 'site-title', 'site-description' ),
        'unlink-homepage-logo' => false,
    ) );

    // Featured image support.
    add_theme_support( 'post-thumbnails' );

    // HTML5 markup for core elements.
    add_theme_support( 'html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ) );

    // Responsive embeds.
    add_theme_support( 'responsive-embeds' );

    // Wide and full alignment support for block editor.
    add_theme_support( 'align-wide' );

    // Editor colour palette matching theme design tokens.
    add_theme_support( 'editor-color-palette', array(
        array(
            'name'  => __( 'Orange', 'screwed' ),
            'slug'  => 'orange',
            'color' => '#e8983f',
        ),
        array(
            'name'  => __( 'Orange Vivid', 'screwed' ),
            'slug'  => 'orange-vivid',
            'color' => '#F7941D',
        ),
        array(
            'name'  => __( 'Orange Dark', 'screwed' ),
            'slug'  => 'orange-dark',
            'color' => '#d4882f',
        ),
        array(
            'name'  => __( 'Orange Flame', 'screwed' ),
            'slug'  => 'orange-flame',
            'color' => '#F15A24',
        ),
        array(
            'name'  => __( 'Cream', 'screwed' ),
            'slug'  => 'cream',
            'color' => '#fcf7ee',
        ),
        array(
            'name'  => __( 'Navy', 'screwed' ),
            'slug'  => 'navy',
            'color' => '#1B2A5C',
        ),
        array(
            'name'  => __( 'Surface', 'screwed' ),
            'slug'  => 'surface',
            'color' => '#F7F7FC',
        ),
        array(
            'name'  => __( 'Dark', 'screwed' ),
            'slug'  => 'dark',
            'color' => '#111827',
        ),
        array(
            'name'  => __( 'Darker', 'screwed' ),
            'slug'  => 'darker',
            'color' => '#030712',
        ),
        array(
            'name'  => __( 'Muted', 'screwed' ),
            'slug'  => 'muted',
            'color' => '#6b7280',
        ),
        array(
            'name'  => __( 'Light Text', 'screwed' ),
            'slug'  => 'light-text',
            'color' => '#9ca3af',
        ),
    ) );

    // Load theme text domain.
    load_theme_textdomain( 'screwed', get_template_directory() . '/languages' );

    // Register navigation menus.
    register_nav_menus( array(
        'primary' => __( 'Primary Menu', 'screwed' ),
        'footer'  => __( 'Footer Menu', 'screwed' ),
    ) );
}

// ---------------------------------------------------------------------------
// Enqueue Scripts & Styles
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', 'screwed_enqueue_assets' );
/**
 * Enqueues the compiled CSS and JS bundles.
 */
function screwed_enqueue_assets() {
    // Main theme stylesheet (compiled, not style.css).
    wp_enqueue_style(
        'screwed-style',
        SCREWED_URI . '/assets/css/screwed-theme.css',
        array(),
        SCREWED_VERSION
    );

    // Main theme script.
    wp_enqueue_script(
        'screwed-script',
        SCREWED_URI . '/assets/js/screwed-theme.js',
        array(),
        SCREWED_VERSION,
        true // load in footer
    );

    // Pass dynamic data to the script.
    wp_localize_script( 'screwed-script', 'screwedData', array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'screwed_nonce' ),
        'primaryColor' => screwed_primary_color(),
        'accentColor'  => screwed_accent_color(),
    ) );
}

// ---------------------------------------------------------------------------
// Google Fonts — raw <link> output to avoid WordPress mangling CSS2 URLs
// ---------------------------------------------------------------------------

add_action( 'wp_head', 'screwed_google_fonts', 5 );
/**
 * Outputs Google Fonts preconnect and stylesheet link tags.
 * Uses Customizer font choices; falls back to Inter when system fonts are not selected.
 */
function screwed_google_fonts() {
    $heading_font = get_theme_mod( 'screwed_heading_font', 'Inter' );
    $body_font    = get_theme_mod( 'screwed_body_font', 'Inter' );

    // Collect unique non-system fonts to request.
    $fonts_needed = array();

    if ( ! theme_is_system_font( $heading_font ) ) {
        $fonts_needed[] = $heading_font;
    }
    if ( ! theme_is_system_font( $body_font ) && $body_font !== $heading_font ) {
        $fonts_needed[] = $body_font;
    }

    // Always load Inter as the baseline if nothing else is selected.
    if ( empty( $fonts_needed ) ) {
        return; // both fonts are system fonts – nothing to load
    }

    // Build the family query strings.
    $family_params = array();
    $weight_range  = 'wght@400;500;600;700;800;900';

    foreach ( $fonts_needed as $font ) {
        // Normalise: replace spaces with + for URL.
        $family_params[] = urlencode( $font ) . ':' . $weight_range;
    }

    // Always include Inter if it was part of the selection or as fallback.
    if ( ! in_array( 'Inter', $fonts_needed, true ) ) {
        $family_params[] = 'Inter:' . $weight_range;
    }

    $family_query = implode( '&family=', $family_params );

    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link href="https://fonts.googleapis.com/css2?family=' . $family_query . '&display=swap" rel="stylesheet">' . "\n";
}

// ---------------------------------------------------------------------------
// Typography Helper Functions
// ---------------------------------------------------------------------------

/**
 * Returns true when the font name is a system-font stack alias.
 *
 * @param  string $font Font name from customizer setting.
 * @return bool
 */
function theme_is_system_font( $font ) {
    $system_fonts = array( 'System Sans', 'System Serif' );
    return in_array( $font, $system_fonts, true );
}

/**
 * Returns a full CSS font-family stack for the given font name.
 *
 * @param  string $font Font name (Google Font name or system alias).
 * @return string CSS font-family value (not escaped for HTML; use in <style> blocks).
 */
function theme_font_stack( $font ) {
    switch ( $font ) {
        case 'System Sans':
            return "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif";

        case 'System Serif':
            return "Georgia, 'Times New Roman', Times, serif";

        // Google Fonts with known serif nature.
        case 'Playfair Display':
        case 'Merriweather':
        case 'Lora':
        case 'PT Serif':
        case 'Cormorant Garamond':
        case 'EB Garamond':
        case 'Libre Baskerville':
        case 'Source Serif Pro':
            return "'{$font}', Georgia, 'Times New Roman', serif";

        // Google Fonts that are monospace.
        case 'JetBrains Mono':
        case 'Fira Code':
        case 'Source Code Pro':
        case 'Roboto Mono':
            return "'{$font}', 'Courier New', Courier, monospace";

        // Everything else is treated as a sans-serif Google Font.
        default:
            return "'{$font}', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    }
}

// ---------------------------------------------------------------------------
// Customizer
// ---------------------------------------------------------------------------

add_action( 'customize_register', 'screwed_customize_register' );
/**
 * Registers all Customizer sections, settings and controls for the theme.
 *
 * @param WP_Customize_Manager $wp_customize Customizer object.
 */
function screwed_customize_register( $wp_customize ) {

    // =========================================================================
    // SECTION: General
    // =========================================================================

    $wp_customize->add_section( 'screwed_general', array(
        'title'       => __( 'Screwed – General', 'screwed' ),
        'description' => __( 'App store links, hero copy, and trust badge.', 'screwed' ),
        'priority'    => 30,
    ) );

    // --- Google Play URL ---
    $wp_customize->add_setting( 'screwed_google_play_url', array(
        'default'           => 'https://play.google.com/store/apps',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'screwed_google_play_url', array(
        'label'       => __( 'Google Play Store URL', 'screwed' ),
        'description' => __( 'Full URL to your app on Google Play.', 'screwed' ),
        'section'     => 'screwed_general',
        'type'        => 'url',
    ) );

    // --- App Store URL ---
    $wp_customize->add_setting( 'screwed_app_store_url', array(
        'default'           => 'https://apps.apple.com',
        'sanitize_callback' => 'esc_url_raw',
        'transport'         => 'refresh',
    ) );
    $wp_customize->add_control( 'screwed_app_store_url', array(
        'label'       => __( 'Apple App Store URL', 'screwed' ),
        'description' => __( 'Full URL to your app on the App Store.', 'screwed' ),
        'section'     => 'screwed_general',
        'type'        => 'url',
    ) );

    // --- Hero Headline ---
    $wp_customize->add_setting( 'screwed_hero_headline', array(
        'default'           => 'How Screwed Am I?',
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_hero_headline', array(
        'label'   => __( 'Hero Headline', 'screwed' ),
        'section' => 'screwed_general',
        'type'    => 'text',
    ) );

    // --- Hero Sub-headline ---
    $wp_customize->add_setting( 'screwed_hero_subheadline', array(
        'default'           => "That Terms of Service you didn't read? We did.",
        'sanitize_callback' => 'wp_kses_post',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_hero_subheadline', array(
        'label'   => __( 'Hero Sub-headline', 'screwed' ),
        'section' => 'screwed_general',
        'type'    => 'textarea',
    ) );

    // --- Trust Badge ---
    $wp_customize->add_setting( 'screwed_trust_badge', array(
        'default'           => '100% on-device · No data stored · No backend',
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_trust_badge', array(
        'label'       => __( 'Trust Badge Text', 'screwed' ),
        'description' => __( 'Short reassurance text shown beneath the CTA buttons.', 'screwed' ),
        'section'     => 'screwed_general',
        'type'        => 'text',
    ) );

    // =========================================================================
    // SECTION: Typography
    // =========================================================================

    $wp_customize->add_section( 'screwed_typography', array(
        'title'       => __( 'Screwed – Typography', 'screwed' ),
        'description' => __( 'Control heading and body font families, weights and the hero text size.', 'screwed' ),
        'priority'    => 35,
    ) );

    // Build font choices list (25+ Google Fonts + system options).
    $font_choices = array(
        // System options first.
        'System Sans'       => __( 'System Sans (default UI fonts)', 'screwed' ),
        'System Serif'      => __( 'System Serif (Georgia-based)', 'screwed' ),
        // --- Sans-serif Google Fonts ---
        'Inter'             => 'Inter',
        'Roboto'            => 'Roboto',
        'Open Sans'         => 'Open Sans',
        'Lato'              => 'Lato',
        'Montserrat'        => 'Montserrat',
        'Poppins'           => 'Poppins',
        'Nunito'            => 'Nunito',
        'Nunito Sans'       => 'Nunito Sans',
        'Raleway'           => 'Raleway',
        'Oswald'            => 'Oswald',
        'Source Sans Pro'   => 'Source Sans Pro',
        'Ubuntu'            => 'Ubuntu',
        'Rubik'             => 'Rubik',
        'Mulish'            => 'Mulish',
        'DM Sans'           => 'DM Sans',
        'Outfit'            => 'Outfit',
        'Plus Jakarta Sans' => 'Plus Jakarta Sans',
        'Barlow'            => 'Barlow',
        'Exo 2'             => 'Exo 2',
        'Figtree'           => 'Figtree',
        'Manrope'           => 'Manrope',
        'Work Sans'         => 'Work Sans',
        'Sora'              => 'Sora',
        // --- Serif Google Fonts ---
        'Playfair Display'  => 'Playfair Display',
        'Merriweather'      => 'Merriweather',
        'Lora'              => 'Lora',
        'PT Serif'          => 'PT Serif',
        'Cormorant Garamond'=> 'Cormorant Garamond',
        'EB Garamond'       => 'EB Garamond',
        'Libre Baskerville' => 'Libre Baskerville',
        'Source Serif Pro'  => 'Source Serif Pro',
        // --- Monospace ---
        'JetBrains Mono'    => 'JetBrains Mono',
        'Fira Code'         => 'Fira Code',
        'Source Code Pro'   => 'Source Code Pro',
        'Roboto Mono'       => 'Roboto Mono',
    );

    // Heading font.
    $wp_customize->add_setting( 'screwed_heading_font', array(
        'default'           => 'Inter',
        'sanitize_callback' => 'screwed_sanitize_font_choice',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_heading_font', array(
        'label'   => __( 'Heading Font', 'screwed' ),
        'section' => 'screwed_typography',
        'type'    => 'select',
        'choices' => $font_choices,
    ) );

    // Body font.
    $wp_customize->add_setting( 'screwed_body_font', array(
        'default'           => 'Inter',
        'sanitize_callback' => 'screwed_sanitize_font_choice',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_body_font', array(
        'label'   => __( 'Body Font', 'screwed' ),
        'section' => 'screwed_typography',
        'type'    => 'select',
        'choices' => $font_choices,
    ) );

    // Heading weight.
    $weight_choices = array(
        '300' => __( 'Light (300)', 'screwed' ),
        '400' => __( 'Regular (400)', 'screwed' ),
        '500' => __( 'Medium (500)', 'screwed' ),
        '600' => __( 'Semi-Bold (600)', 'screwed' ),
        '700' => __( 'Bold (700)', 'screwed' ),
        '800' => __( 'Extra Bold (800)', 'screwed' ),
        '900' => __( 'Black (900)', 'screwed' ),
    );

    $wp_customize->add_setting( 'screwed_heading_weight', array(
        'default'           => '800',
        'sanitize_callback' => 'screwed_sanitize_font_weight',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_heading_weight', array(
        'label'   => __( 'Heading Font Weight', 'screwed' ),
        'section' => 'screwed_typography',
        'type'    => 'select',
        'choices' => $weight_choices,
    ) );

    // Body weight.
    $wp_customize->add_setting( 'screwed_body_weight', array(
        'default'           => '400',
        'sanitize_callback' => 'screwed_sanitize_font_weight',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_body_weight', array(
        'label'   => __( 'Body Font Weight', 'screwed' ),
        'section' => 'screwed_typography',
        'type'    => 'select',
        'choices' => $weight_choices,
    ) );

    // Hero text size.
    $hero_size_choices = array(
        '2.5rem'  => __( 'Small (2.5rem)', 'screwed' ),
        '3rem'    => __( 'Medium (3rem)', 'screwed' ),
        '3.5rem'  => __( 'Large (3.5rem) – default', 'screwed' ),
        '4rem'    => __( 'X-Large (4rem)', 'screwed' ),
        '4.5rem'  => __( 'XX-Large (4.5rem)', 'screwed' ),
        '5rem'    => __( 'Huge (5rem)', 'screwed' ),
        'clamp(2.5rem, 6vw, 5rem)' => __( 'Fluid (clamp)', 'screwed' ),
    );

    $wp_customize->add_setting( 'screwed_hero_size', array(
        'default'           => '3.5rem',
        'sanitize_callback' => 'screwed_sanitize_hero_size',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( 'screwed_hero_size', array(
        'label'       => __( 'Hero Headline Size', 'screwed' ),
        'description' => __( 'Font size for the main hero heading.', 'screwed' ),
        'section'     => 'screwed_typography',
        'type'        => 'select',
        'choices'     => $hero_size_choices,
    ) );

    // =========================================================================
    // SECTION: Colors
    // =========================================================================

    $wp_customize->add_section( 'screwed_colors', array(
        'title'       => __( 'Screwed – Colors', 'screwed' ),
        'description' => __( 'Override the primary orange and accent dark orange. Leave blank to use theme defaults.', 'screwed' ),
        'priority'    => 40,
    ) );

    // Primary color (orange).
    $wp_customize->add_setting( 'screwed_primary_color', array(
        'default'           => '#e8983f',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'screwed_primary_color', array(
        'label'       => __( 'Primary Orange', 'screwed' ),
        'description' => __( 'Main accent color. Default: #e8983f', 'screwed' ),
        'section'     => 'screwed_colors',
    ) ) );

    // Accent dark color.
    $wp_customize->add_setting( 'screwed_accent_dark_color', array(
        'default'           => '#d4882f',
        'sanitize_callback' => 'sanitize_hex_color',
        'transport'         => 'postMessage',
    ) );
    $wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, 'screwed_accent_dark_color', array(
        'label'       => __( 'Accent Dark Orange', 'screwed' ),
        'description' => __( 'Darker variant used for hover states. Default: #d4882f', 'screwed' ),
        'section'     => 'screwed_colors',
    ) ) );
}

// ---------------------------------------------------------------------------
// Customizer Sanitize Callbacks
// ---------------------------------------------------------------------------

/**
 * Sanitizes a font choice setting against the allowed font list.
 *
 * @param  string $value  Submitted value.
 * @return string         Sanitized value or default 'Inter'.
 */
function screwed_sanitize_font_choice( $value ) {
    $allowed = array(
        'System Sans', 'System Serif',
        'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Poppins',
        'Nunito', 'Nunito Sans', 'Raleway', 'Oswald', 'Source Sans Pro',
        'Ubuntu', 'Rubik', 'Mulish', 'DM Sans', 'Outfit', 'Plus Jakarta Sans',
        'Barlow', 'Exo 2', 'Figtree', 'Manrope', 'Work Sans', 'Sora',
        'Playfair Display', 'Merriweather', 'Lora', 'PT Serif',
        'Cormorant Garamond', 'EB Garamond', 'Libre Baskerville', 'Source Serif Pro',
        'JetBrains Mono', 'Fira Code', 'Source Code Pro', 'Roboto Mono',
    );
    return in_array( $value, $allowed, true ) ? $value : 'Inter';
}

/**
 * Sanitizes a font weight setting.
 *
 * @param  string $value Submitted value.
 * @return string        Validated weight or '400'.
 */
function screwed_sanitize_font_weight( $value ) {
    $allowed = array( '100', '200', '300', '400', '500', '600', '700', '800', '900' );
    return in_array( (string) $value, $allowed, true ) ? (string) $value : '400';
}

/**
 * Sanitizes the hero size setting.
 *
 * @param  string $value Submitted value.
 * @return string        Validated size or '3.5rem'.
 */
function screwed_sanitize_hero_size( $value ) {
    $allowed = array(
        '2.5rem', '3rem', '3.5rem', '4rem', '4.5rem', '5rem',
        'clamp(2.5rem, 6vw, 5rem)',
    );
    return in_array( $value, $allowed, true ) ? $value : '3.5rem';
}

// ---------------------------------------------------------------------------
// Customizer Live Preview (postMessage)
// ---------------------------------------------------------------------------

add_action( 'customize_preview_init', 'screwed_customize_preview_js' );
/**
 * Enqueues live-preview JS for postMessage transport settings.
 */
function screwed_customize_preview_js() {
    wp_enqueue_script(
        'screwed-customizer-preview',
        SCREWED_URI . '/assets/js/customizer-preview.js',
        array( 'customize-preview', 'jquery' ),
        SCREWED_VERSION,
        true
    );
}

add_action( 'customize_controls_enqueue_scripts', 'screwed_customize_controls_js' );
/**
 * Enqueues Customizer panel controls JS.
 */
function screwed_customize_controls_js() {
    wp_enqueue_script(
        'screwed-customizer-controls',
        SCREWED_URI . '/assets/js/customizer-controls.js',
        array( 'customize-controls', 'jquery' ),
        SCREWED_VERSION,
        true
    );
}

// ---------------------------------------------------------------------------
// Dynamic CSS — inject Customizer values as CSS custom properties
// ---------------------------------------------------------------------------

add_action( 'wp_head', 'screwed_dynamic_css', 99 );
/**
 * Outputs a <style> block with CSS custom properties driven by Customizer settings.
 * Runs late (priority 99) so it overrides the static stylesheet.
 */
function screwed_dynamic_css() {
    $primary      = sanitize_hex_color( get_theme_mod( 'screwed_primary_color', '#e8983f' ) );
    $accent_dark  = sanitize_hex_color( get_theme_mod( 'screwed_accent_dark_color', '#d4882f' ) );
    $heading_font = get_theme_mod( 'screwed_heading_font', 'Inter' );
    $body_font    = get_theme_mod( 'screwed_body_font', 'Inter' );
    $heading_wt   = screwed_sanitize_font_weight( get_theme_mod( 'screwed_heading_weight', '800' ) );
    $body_wt      = screwed_sanitize_font_weight( get_theme_mod( 'screwed_body_weight', '400' ) );
    $hero_size    = screwed_sanitize_hero_size( get_theme_mod( 'screwed_hero_size', '3.5rem' ) );

    $heading_stack = theme_font_stack( $heading_font );
    $body_stack    = theme_font_stack( $body_font );

    // Only output if values differ from stylesheet defaults (always output for reliability).
    ?>
    <style id="screwed-dynamic-css">
    :root {
        --color-primary:      <?php echo esc_attr( $primary ); ?>;
        --color-accent-dark:  <?php echo esc_attr( $accent_dark ); ?>;
        --font-heading:       <?php echo esc_attr( $heading_stack ); ?>;
        --font-body:          <?php echo esc_attr( $body_stack ); ?>;
        --font-weight-heading:<?php echo esc_attr( $heading_wt ); ?>;
        --font-weight-body:   <?php echo esc_attr( $body_wt ); ?>;
        --hero-font-size:     <?php echo esc_attr( $hero_size ); ?>;
    }
    </style>
    <?php
}

// ---------------------------------------------------------------------------
// Helper Functions — public API for templates
// ---------------------------------------------------------------------------

/**
 * Returns the escaped Google Play Store URL.
 *
 * @return string
 */
function screwed_google_play() {
    return esc_url( get_theme_mod( 'screwed_google_play_url', 'https://play.google.com/store' ) );
}

/**
 * Returns the escaped Apple App Store URL.
 *
 * @return string
 */
function screwed_app_store() {
    return esc_url( get_theme_mod( 'screwed_app_store_url', 'https://apps.apple.com' ) );
}

/**
 * Returns the sanitized primary (orange) color hex value.
 *
 * @return string Hex color, e.g. '#e8983f'.
 */
function screwed_primary_color() {
    return sanitize_hex_color( get_theme_mod( 'screwed_primary_color', '#e8983f' ) );
}

/**
 * Returns the sanitized accent dark color hex value.
 *
 * @return string Hex color, e.g. '#d4882f'.
 */
function screwed_accent_color() {
    return sanitize_hex_color( get_theme_mod( 'screwed_accent_dark_color', '#d4882f' ) );
}

/**
 * Returns the escaped hero headline string.
 *
 * @return string
 */
function screwed_hero_headline() {
    return esc_html( get_theme_mod( 'screwed_hero_headline', 'How Screwed Am I?' ) );
}

/**
 * Returns the kses-sanitized hero sub-headline (allows basic inline HTML).
 *
 * @return string
 */
function screwed_hero_sub() {
    return wp_kses_post( get_theme_mod( 'screwed_hero_subheadline', "That Terms of Service you didn't read? We did." ) );
}

/**
 * Returns the escaped trust badge text.
 *
 * @return string
 */
function screwed_trust_badge() {
    return esc_html( get_theme_mod( 'screwed_trust_badge', '100% on-device · No data stored · No backend' ) );
}

// ---------------------------------------------------------------------------
// Body Class Additions
// ---------------------------------------------------------------------------

add_filter( 'body_class', 'screwed_body_classes' );
/**
 * Appends theme-specific body classes.
 *
 * @param  array $classes Existing body classes.
 * @return array
 */
function screwed_body_classes( $classes ) {
    // Signal which font stack is active.
    $heading_font = get_theme_mod( 'screwed_heading_font', 'Inter' );
    if ( theme_is_system_font( $heading_font ) ) {
        $classes[] = 'heading-font--system';
    }

    $body_font = get_theme_mod( 'screwed_body_font', 'Inter' );
    if ( theme_is_system_font( $body_font ) ) {
        $classes[] = 'body-font--system';
    }

    // Front page signal.
    if ( is_front_page() && ! is_home() ) {
        $classes[] = 'screwed-front-page';
    }

    return $classes;
}

// ---------------------------------------------------------------------------
// Excerpt Length
// ---------------------------------------------------------------------------

add_filter( 'excerpt_length', 'screwed_excerpt_length' );
/**
 * Shortens the excerpt to 25 words for card-style layouts.
 *
 * @param  int $length Default excerpt word count.
 * @return int
 */
function screwed_excerpt_length( $length ) {
    return 25;
}

add_filter( 'excerpt_more', 'screwed_excerpt_more' );
/**
 * Replaces the default [...] excerpt suffix.
 *
 * @return string
 */
function screwed_excerpt_more() {
    return '&hellip;';
}

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------

require_once get_template_directory() . '/inc/demo-content.php';
