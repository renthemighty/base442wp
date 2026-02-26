<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<!-- =========================================================
     STICKY HEADER
     ========================================================= -->
<header id="screwed-header" class="screwed-header" role="banner">
	<div class="screwed-header__inner screwed-container">

		<!-- Logo -->
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"
		   class="screwed-logo"
		   aria-label="<?php esc_attr_e( 'How Screwed Am I? – Home', 'screwed' ); ?>">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<span class="screwed-logo__text">
					How Screwed Am I?<svg
						class="screwed-logo__icon"
						width="22"
						height="22"
						viewBox="0 0 24 24"
						fill="none"
						aria-hidden="true"
						focusable="false"
						xmlns="http://www.w3.org/2000/svg">
						<circle cx="12" cy="12" r="10" stroke="#e8983f" stroke-width="2"/>
						<path d="M9.5 9.5C9.5 8.12 10.62 7 12 7s2.5 1.12 2.5 2.5c0 1.5-1.5 2-2.5 2.75"
						      stroke="#e8983f"
						      stroke-width="2"
						      stroke-linecap="round"/>
						<circle cx="12" cy="17" r="1" fill="#e8983f"/>
					</svg>
				</span>
			<?php endif; ?>
		</a>

		<!-- Desktop Primary Navigation -->
		<nav class="screwed-nav" aria-label="<?php esc_attr_e( 'Primary navigation', 'screwed' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'screwed-nav__list',
				'fallback_cb'    => 'screwed_header_fallback_nav',
			) );
			?>
		</nav>

		<!-- Desktop CTA Buttons -->
		<div class="screwed-header__ctas">
			<a href="<?php echo screwed_google_play(); ?>"
			   class="screwed-btn screwed-btn--sm screwed-btn--outline"
			   target="_blank"
			   rel="noopener noreferrer"
			   aria-label="<?php esc_attr_e( 'Download on Google Play', 'screwed' ); ?>">
				<!-- Google Play icon -->
				<svg class="screwed-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
					<path d="M3.18 23.76a2 2 0 0 0 2.08-.18l12.24-11.4L5.26.42A2 2 0 0 0 2 2.2v19.6a2 2 0 0 0 1.18 1.96zm9.14-10.6L5 19.47V4.53l7.32 8.63zM19 10.22l-2.18-1.27-2.38 3.05 2.38 3.05L19 13.78a2 2 0 0 0 0-3.56z"/>
				</svg>
				<?php esc_html_e( 'Google Play', 'screwed' ); ?>
			</a>
			<a href="<?php echo screwed_app_store(); ?>"
			   class="screwed-btn screwed-btn--sm screwed-btn--primary"
			   target="_blank"
			   rel="noopener noreferrer"
			   aria-label="<?php esc_attr_e( 'Download on the App Store', 'screwed' ); ?>">
				<!-- Apple icon -->
				<svg class="screwed-btn__icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
					<path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
				</svg>
				<?php esc_html_e( 'App Store', 'screwed' ); ?>
			</a>
		</div>

		<!-- Mobile Hamburger Toggle -->
		<button class="screwed-menu-toggle"
		        aria-label="<?php esc_attr_e( 'Toggle menu', 'screwed' ); ?>"
		        aria-expanded="false"
		        aria-controls="screwed-mobile-nav"
		        type="button">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
				<!-- Line 1 -->
				<line class="screwed-menu-toggle__line screwed-menu-toggle__line--top" x1="3" y1="6" x2="21" y2="6"/>
				<!-- Line 2 -->
				<line class="screwed-menu-toggle__line screwed-menu-toggle__line--mid" x1="3" y1="12" x2="21" y2="12"/>
				<!-- Line 3 -->
				<line class="screwed-menu-toggle__line screwed-menu-toggle__line--bot" x1="3" y1="18" x2="21" y2="18"/>
			</svg>
		</button>

	</div><!-- .screwed-header__inner -->
</header>

<!-- =========================================================
     MOBILE NAV OVERLAY
     ========================================================= -->
<div id="screwed-mobile-nav"
     class="screwed-mobile-nav"
     aria-hidden="true"
     role="dialog"
     aria-label="<?php esc_attr_e( 'Mobile navigation', 'screwed' ); ?>">

	<div class="screwed-mobile-nav__panel">

		<!-- Close Button -->
		<button class="screwed-mobile-nav__close"
		        aria-label="<?php esc_attr_e( 'Close menu', 'screwed' ); ?>"
		        type="button">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
				<line x1="18" y1="6" x2="6" y2="18"/>
				<line x1="6"  y1="6" x2="18" y2="18"/>
			</svg>
		</button>

		<!-- Mobile Logo -->
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"
		   class="screwed-logo screwed-logo--mobile"
		   tabindex="-1">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<span class="screwed-logo__text">
					How Screwed Am I?<svg
						class="screwed-logo__icon"
						width="20"
						height="20"
						viewBox="0 0 24 24"
						fill="none"
						aria-hidden="true"
						focusable="false"
						xmlns="http://www.w3.org/2000/svg">
						<circle cx="12" cy="12" r="10" stroke="#e8983f" stroke-width="2"/>
						<path d="M9.5 9.5C9.5 8.12 10.62 7 12 7s2.5 1.12 2.5 2.5c0 1.5-1.5 2-2.5 2.75"
						      stroke="#e8983f"
						      stroke-width="2"
						      stroke-linecap="round"/>
						<circle cx="12" cy="17" r="1" fill="#e8983f"/>
					</svg>
				</span>
			<?php endif; ?>
		</a>

		<!-- Mobile Navigation Menu -->
		<nav class="screwed-mobile-nav__nav"
		     aria-label="<?php esc_attr_e( 'Mobile navigation', 'screwed' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'screwed-mobile-nav__list',
				'fallback_cb'    => 'screwed_mobile_fallback_nav',
			) );
			?>
		</nav>

		<!-- Mobile CTA Buttons -->
		<div class="screwed-mobile-nav__ctas">
			<a href="<?php echo screwed_google_play(); ?>"
			   class="screwed-btn screwed-btn--outline screwed-btn--full"
			   target="_blank"
			   rel="noopener noreferrer">
				<svg class="screwed-btn__icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
					<path d="M3.18 23.76a2 2 0 0 0 2.08-.18l12.24-11.4L5.26.42A2 2 0 0 0 2 2.2v19.6a2 2 0 0 0 1.18 1.96zm9.14-10.6L5 19.47V4.53l7.32 8.63zM19 10.22l-2.18-1.27-2.38 3.05 2.38 3.05L19 13.78a2 2 0 0 0 0-3.56z"/>
				</svg>
				<?php esc_html_e( 'Google Play', 'screwed' ); ?>
			</a>
			<a href="<?php echo screwed_app_store(); ?>"
			   class="screwed-btn screwed-btn--primary screwed-btn--full"
			   target="_blank"
			   rel="noopener noreferrer">
				<svg class="screwed-btn__icon" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
					<path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
				</svg>
				<?php esc_html_e( 'App Store', 'screwed' ); ?>
			</a>
		</div>

	</div><!-- .screwed-mobile-nav__panel -->

	<!-- Backdrop -->
	<div class="screwed-mobile-nav__backdrop" id="screwed-nav-backdrop" aria-hidden="true"></div>

</div><!-- #screwed-mobile-nav -->

<?php
// ---------------------------------------------------------------------------
// Fallback nav callbacks (no menu assigned in Appearance > Menus)
// ---------------------------------------------------------------------------

/**
 * Fallback nav for desktop header when no menu is assigned.
 */
function screwed_header_fallback_nav() {
	echo '<ul class="screwed-nav__list">';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#how-it-works' ) ) . '">' . esc_html__( 'How It Works', 'screwed' ) . '</a></li>';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#features' ) ) . '">'    . esc_html__( 'Features',     'screwed' ) . '</a></li>';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#roadmap' ) ) . '">'     . esc_html__( 'Roadmap',      'screwed' ) . '</a></li>';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#download' ) ) . '">'    . esc_html__( 'Download',     'screwed' ) . '</a></li>';
	echo '</ul>';
}

/**
 * Fallback nav for mobile overlay when no menu is assigned.
 */
function screwed_mobile_fallback_nav() {
	echo '<ul class="screwed-mobile-nav__list">';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#how-it-works' ) ) . '">' . esc_html__( 'How It Works', 'screwed' ) . '</a></li>';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#features' ) ) . '">'    . esc_html__( 'Features',     'screwed' ) . '</a></li>';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#roadmap' ) ) . '">'     . esc_html__( 'Roadmap',      'screwed' ) . '</a></li>';
	echo '<li class="menu-item"><a href="' . esc_url( home_url( '/#download' ) ) . '">'    . esc_html__( 'Download',     'screwed' ) . '</a></li>';
	echo '</ul>';
}
?>
