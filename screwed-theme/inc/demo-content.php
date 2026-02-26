<?php
/**
 * Screwed TOS Scan — Demo Content Importer
 *
 * Registers a "Screwed: Demo Import" page under the WordPress Tools menu.
 * When the import button is pressed (with nonce verification), it:
 *   1. Sets Customizer theme mods (store URLs, primary color).
 *   2. Creates Home, Privacy Policy and Terms of Service pages.
 *   3. Sets Home as the static front page.
 *   4. Creates a "Primary Navigation" menu with anchor links.
 *   5. Assigns that menu to the registered primary nav location.
 *   6. Displays an admin notice confirming success.
 *
 * @package Screwed_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/* ==========================================================================
   Admin menu registration
   ========================================================================== */

add_action( 'admin_menu', 'screwed_add_demo_page' );

/**
 * Add a sub-page under Tools → "Screwed: Demo Import".
 *
 * @return void
 */
function screwed_add_demo_page() {
	add_management_page(
		__( 'Screwed: Demo Import', 'screwed-theme' ),  // <title>
		__( 'Screwed: Demo Import', 'screwed-theme' ),  // Menu label
		'manage_options',                                // Capability required
		'screwed-demo-import',                           // Menu slug
		'screwed_demo_page_html'                         // Callback
	);
}

/* ==========================================================================
   Admin page HTML
   ========================================================================== */

/**
 * Render the demo-import admin page.
 *
 * @return void
 */
function screwed_demo_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'screwed-theme' ) );
	}

	// Handle form submission (show inline result notice as well).
	$import_result = '';
	if (
		isset( $_POST['screwed_import_nonce'] ) &&
		wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['screwed_import_nonce'] ) ), 'screwed_import_demo_action' )
	) {
		$import_result = screwed_import_demo();
	}
	?>
	<div class="wrap">
		<h1>
			<?php esc_html_e( 'Screwed TOS Scan — Demo Content Importer', 'screwed-theme' ); ?>
		</h1>

		<p>
			<?php esc_html_e( 'Click the button below to import demo pages, navigation menus, and Customizer settings. Existing content will not be deleted, but pages with matching slugs may be skipped.', 'screwed-theme' ); ?>
		</p>

		<?php if ( ! empty( $import_result ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php echo wp_kses_post( $import_result ); ?></p>
			</div>
		<?php endif; ?>

		<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:24px;max-width:520px;margin-top:16px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'What will be imported?', 'screwed-theme' ); ?></h2>
			<ul style="list-style:disc;padding-left:1.5em;line-height:1.8;">
				<li><?php esc_html_e( 'Customizer settings (store URLs, primary color)', 'screwed-theme' ); ?></li>
				<li><?php esc_html_e( 'Pages: Home, Privacy Policy, Terms of Service', 'screwed-theme' ); ?></li>
				<li><?php esc_html_e( 'Static front page → Home', 'screwed-theme' ); ?></li>
				<li><?php esc_html_e( 'Primary Navigation menu with anchor links', 'screwed-theme' ); ?></li>
			</ul>

			<form method="post" action="">
				<?php wp_nonce_field( 'screwed_import_demo_action', 'screwed_import_nonce' ); ?>
				<p>
					<button type="submit" class="button button-primary button-large">
						<?php esc_html_e( 'Import Demo Content', 'screwed-theme' ); ?>
					</button>
				</p>
			</form>
		</div>
	</div>
	<?php
}

/* ==========================================================================
   Core import function
   ========================================================================== */

/**
 * Perform the full demo-content import.
 *
 * @return string Human-readable result message (may contain HTML).
 */
function screwed_import_demo() {
	$log = array();

	/* ------------------------------------------------------------------
	   1. Customizer theme mods
	   ------------------------------------------------------------------ */

	$customizer_mods = array(
		// App store URLs (theme should expose these in its Customizer panel)
		'screwed_google_play_url' => 'https://play.google.com/store/apps/details?id=com.screwed.tosscan',
		'screwed_app_store_url'   => 'https://apps.apple.com/app/screwed-tos-scan/id0000000000',

		// Primary brand color
		'screwed_primary_color'      => '#e8983f',
		'screwed_primary_color_dark' => '#d4882f',

		// Basic site identity overrides
		'screwed_tagline'         => __( 'Know exactly how screwed you are before agreeing.', 'screwed-theme' ),
	);

	foreach ( $customizer_mods as $mod_key => $mod_value ) {
		set_theme_mod( $mod_key, $mod_value );
	}

	$log[] = __( 'Customizer settings saved.', 'screwed-theme' );

	/* ------------------------------------------------------------------
	   2. Create pages
	   ------------------------------------------------------------------ */

	$pages = array(
		array(
			'post_title'   => __( 'Home', 'screwed-theme' ),
			'post_name'    => 'home',
			'post_content' => screwed_get_home_page_content(),
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'page_template' => 'templates/page-landing.php', // adjust if your theme uses a different path
		),
		array(
			'post_title'   => __( 'Privacy Policy', 'screwed-theme' ),
			'post_name'    => 'privacy-policy',
			'post_content' => screwed_get_privacy_page_content(),
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
		array(
			'post_title'   => __( 'Terms of Service', 'screwed-theme' ),
			'post_name'    => 'terms-of-service',
			'post_content' => screwed_get_tos_page_content(),
			'post_status'  => 'publish',
			'post_type'    => 'page',
		),
	);

	$home_page_id = 0;

	foreach ( $pages as $page_data ) {
		// Check for an existing page with the same slug to avoid duplicates.
		$existing = get_page_by_path( $page_data['post_name'], OBJECT, 'page' );

		if ( $existing ) {
			// Page already exists — record ID for Home but skip insert.
			if ( 'home' === $page_data['post_name'] ) {
				$home_page_id = (int) $existing->ID;
			}
			/* translators: %s: page title */
			$log[] = sprintf( __( 'Page "%s" already exists — skipped.', 'screwed-theme' ), $page_data['post_title'] );
			continue;
		}

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			/* translators: 1: page title  2: error message */
			$log[] = sprintf(
				__( 'Failed to create page "%1$s": %2$s', 'screwed-theme' ),
				$page_data['post_title'],
				$page_id->get_error_message()
			);
			continue;
		}

		if ( 'home' === $page_data['post_name'] ) {
			$home_page_id = (int) $page_id;
		}

		/* translators: %s: page title */
		$log[] = sprintf( __( 'Created page "%s".', 'screwed-theme' ), $page_data['post_title'] );
	}

	/* ------------------------------------------------------------------
	   3. Set Home as the static front page
	   ------------------------------------------------------------------ */

	if ( $home_page_id > 0 ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home_page_id );
		$log[] = __( 'Front page set to "Home".', 'screwed-theme' );
	}

	/* ------------------------------------------------------------------
	   4. Create "Primary Navigation" menu with anchor links
	   ------------------------------------------------------------------ */

	$menu_name     = __( 'Primary Navigation', 'screwed-theme' );
	$existing_menu = wp_get_nav_menu_object( $menu_name );

	if ( $existing_menu ) {
		$menu_id = (int) $existing_menu->term_id;
		$log[]   = __( 'Navigation menu "Primary Navigation" already exists — reusing.', 'screwed-theme' );
	} else {
		$menu_id = wp_create_nav_menu( $menu_name );

		if ( is_wp_error( $menu_id ) ) {
			$log[] = sprintf(
				/* translators: %s: error message */
				__( 'Failed to create nav menu: %s', 'screwed-theme' ),
				$menu_id->get_error_message()
			);
			// Still return what we have so far.
			return screwed_format_import_log( $log );
		}

		$log[] = __( 'Created "Primary Navigation" menu.', 'screwed-theme' );
	}

	// Nav menu items: label → URL
	$nav_items = array(
		__( 'How It Works', 'screwed-theme' ) => '/#how-it-works',
		__( 'Features',     'screwed-theme' ) => '/#features',
		__( 'Roadmap',      'screwed-theme' ) => '/#roadmap',
		__( 'Download',     'screwed-theme' ) => '/#download',
	);

	// Remove all existing items from the menu before re-adding, to prevent duplication on re-import.
	$existing_items = wp_get_nav_menu_items( $menu_id );
	if ( is_array( $existing_items ) ) {
		foreach ( $existing_items as $item ) {
			wp_delete_post( (int) $item->ID, true );
		}
	}

	$menu_order = 1;
	foreach ( $nav_items as $label => $url ) {
		$item_args = array(
			'menu-item-title'  => $label,
			'menu-item-url'    => $url,
			'menu-item-status' => 'publish',
			'menu-item-type'   => 'custom',
		);

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $item_args );

		if ( is_wp_error( $item_id ) ) {
			/* translators: %s: menu item label */
			$log[] = sprintf( __( 'Failed to add menu item "%s".', 'screwed-theme' ), $label );
		} else {
			// Set the menu order correctly.
			wp_update_post( array(
				'ID'         => (int) $item_id,
				'menu_order' => $menu_order,
			) );
			$menu_order++;
		}
	}

	$log[] = __( 'Navigation menu items saved.', 'screwed-theme' );

	/* ------------------------------------------------------------------
	   5. Assign menu to the "primary" theme location
	   ------------------------------------------------------------------ */

	$locations = get_theme_mod( 'nav_menu_locations', array() );

	// Use the registered location key — themes commonly call it "primary".
	$locations['primary'] = $menu_id;

	set_theme_mod( 'nav_menu_locations', $locations );
	$log[] = __( 'Menu assigned to primary navigation location.', 'screwed-theme' );

	/* ------------------------------------------------------------------
	   6. Admin notice (also returned for inline display)
	   ------------------------------------------------------------------ */

	add_action( 'admin_notices', function () {
		?>
		<div class="notice notice-success is-dismissible">
			<p><strong><?php esc_html_e( 'Screwed Theme:', 'screwed-theme' ); ?></strong>
			<?php esc_html_e( 'Demo content imported successfully!', 'screwed-theme' ); ?></p>
		</div>
		<?php
	} );

	return screwed_format_import_log( $log );
}

/* ==========================================================================
   Helper: format log lines into readable HTML
   ========================================================================== */

/**
 * Convert an array of log lines into an HTML unordered list.
 *
 * @param  string[] $log
 * @return string
 */
function screwed_format_import_log( array $log ) {
	if ( empty( $log ) ) {
		return '';
	}

	$items = array_map( function ( $line ) {
		return '<li>' . esc_html( $line ) . '</li>';
	}, $log );

	return '<strong>' . esc_html__( 'Import complete. Summary:', 'screwed-theme' ) . '</strong>'
		. '<ul style="list-style:disc;padding-left:1.5em;margin:.5em 0 0;">'
		. implode( '', $items )
		. '</ul>';
}

/* ==========================================================================
   Helper: page content stubs
   ========================================================================== */

/**
 * Return starter block content for the Home (landing) page.
 *
 * The actual front-page template will typically override this content,
 * but the blocks act as a reasonable fallback and provide section IDs.
 *
 * @return string
 */
function screwed_get_home_page_content() {
	return '<!-- wp:html -->
<div id="how-it-works"></div>
<!-- /wp:html -->

<!-- wp:heading -->
<h2>How It Works</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Paste any Terms of Service URL. Our AI reads every clause so you don\'t have to.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<div id="features"></div>
<!-- /wp:html -->

<!-- wp:heading -->
<h2>Features</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Instant risk scoring, plain-English summaries, red-flag highlights, and more.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<div id="roadmap"></div>
<!-- /wp:html -->

<!-- wp:heading -->
<h2>Roadmap</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Browser extension, API access, team plans — coming soon.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
<div id="download"></div>
<!-- /wp:html -->

<!-- wp:heading -->
<h2>Download</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Available on iOS and Android. Free to get started.</p>
<!-- /wp:paragraph -->';
}

/**
 * Return starter content for the Privacy Policy page.
 *
 * @return string
 */
function screwed_get_privacy_page_content() {
	return '<!-- wp:heading -->
<h2>Privacy Policy</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><em>Last updated: ' . esc_html( date_i18n( get_option( 'date_format' ) ) ) . '</em></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>Information We Collect</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We collect information you provide directly to us, such as when you create an account, submit a Terms of Service URL for analysis, or contact us for support.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>How We Use Your Information</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We use the information we collect to provide, maintain, and improve our services, process transactions, and communicate with you.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>Contact Us</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>If you have questions about this Privacy Policy, please contact us at privacy@example.com.</p>
<!-- /wp:paragraph -->';
}

/**
 * Return starter content for the Terms of Service page.
 *
 * @return string
 */
function screwed_get_tos_page_content() {
	return '<!-- wp:heading -->
<h2>Terms of Service</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><em>Last updated: ' . esc_html( date_i18n( get_option( 'date_format' ) ) ) . '</em></p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>Acceptance of Terms</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>By accessing or using the Screwed TOS Scan service, you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use our service.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>Use of Service</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>You may use Screwed TOS Scan for lawful purposes only. You agree not to use the service to infringe any third-party intellectual property rights.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>Disclaimer</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The analysis provided by Screwed TOS Scan is for informational purposes only and does not constitute legal advice. Always consult a qualified attorney for legal matters.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3>Contact Us</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>If you have questions about these Terms, please contact us at legal@example.com.</p>
<!-- /wp:paragraph -->';
}
