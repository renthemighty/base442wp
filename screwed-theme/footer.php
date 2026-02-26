<!-- =========================================================
     SITE FOOTER
     ========================================================= -->
<footer class="screwed-footer" role="contentinfo">

	<!-- Dark primary band -->
	<div class="screwed-footer__main" style="background-color:#030712;">
		<div class="screwed-container">
			<div class="screwed-footer__top">

				<!-- Brand Column -->
				<div class="screwed-footer__brand">

					<!-- Logo -->
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"
					   class="screwed-logo screwed-logo--footer"
					   aria-label="<?php esc_attr_e( 'How Screwed Am I? – Home', 'screwed' ); ?>">
						<span class="screwed-logo__text screwed-logo__text--light">
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
					</a>

					<!-- Tagline -->
					<p class="screwed-footer__tagline">
						<?php esc_html_e( 'They wrote it to confuse you. We expose them.', 'screwed' ); ?>
					</p>

					<!-- App Store Buttons -->
					<div class="screwed-footer__store-buttons">
						<a href="<?php echo screwed_google_play(); ?>"
						   class="screwed-footer__store-btn screwed-footer__store-btn--play"
						   target="_blank"
						   rel="noopener noreferrer"
						   aria-label="<?php esc_attr_e( 'Get it on Google Play', 'screwed' ); ?>">
							<!-- Google Play badge -->
							<svg class="screwed-footer__store-icon" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
								<path d="M3.18 23.76a2 2 0 0 0 2.08-.18l12.24-11.4L5.26.42A2 2 0 0 0 2 2.2v19.6a2 2 0 0 0 1.18 1.96zm9.14-10.6L5 19.47V4.53l7.32 8.63zM19 10.22l-2.18-1.27-2.38 3.05 2.38 3.05L19 13.78a2 2 0 0 0 0-3.56z"/>
							</svg>
							<span class="screwed-footer__store-label">
								<span class="screwed-footer__store-sup"><?php esc_html_e( 'Get it on', 'screwed' ); ?></span>
								<span class="screwed-footer__store-name"><?php esc_html_e( 'Google Play', 'screwed' ); ?></span>
							</span>
						</a>

						<a href="<?php echo screwed_app_store(); ?>"
						   class="screwed-footer__store-btn screwed-footer__store-btn--apple"
						   target="_blank"
						   rel="noopener noreferrer"
						   aria-label="<?php esc_attr_e( 'Download on the App Store', 'screwed' ); ?>">
							<!-- Apple icon -->
							<svg class="screwed-footer__store-icon" width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
								<path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
							</svg>
							<span class="screwed-footer__store-label">
								<span class="screwed-footer__store-sup"><?php esc_html_e( 'Download on the', 'screwed' ); ?></span>
								<span class="screwed-footer__store-name"><?php esc_html_e( 'App Store', 'screwed' ); ?></span>
							</span>
						</a>
					</div>

					<!-- Trust Badge -->
					<p class="screwed-footer__trust">
						<svg class="screwed-footer__trust-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e8983f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
							<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
						</svg>
						<?php esc_html_e( '100% on-device', 'screwed' ); ?>
						<span class="screwed-footer__trust-sep" aria-hidden="true">&bull;</span>
						<?php esc_html_e( 'No data stored', 'screwed' ); ?>
						<span class="screwed-footer__trust-sep" aria-hidden="true">&bull;</span>
						<?php esc_html_e( 'No backend', 'screwed' ); ?>
					</p>

				</div><!-- .screwed-footer__brand -->

				<!-- Footer Navigation Links -->
				<nav class="screwed-footer__nav"
				     aria-label="<?php esc_attr_e( 'Footer navigation', 'screwed' ); ?>">
					<ul class="screwed-footer__nav-list">
						<li class="screwed-footer__nav-item">
							<a href="<?php echo esc_url( get_privacy_policy_url() ? get_privacy_policy_url() : home_url( '/privacy-policy/' ) ); ?>"
							   class="screwed-footer__nav-link">
								<?php esc_html_e( 'Privacy Policy', 'screwed' ); ?>
							</a>
						</li>
						<li class="screwed-footer__nav-item">
							<a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"
							   class="screwed-footer__nav-link">
								<?php esc_html_e( 'Terms', 'screwed' ); ?>
							</a>
						</li>
						<li class="screwed-footer__nav-item">
							<a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"
							   class="screwed-footer__nav-link">
								<?php esc_html_e( 'Contact', 'screwed' ); ?>
							</a>
						</li>
					</ul>
				</nav><!-- .screwed-footer__nav -->

			</div><!-- .screwed-footer__top -->

			<!-- Bottom Bar -->
			<div class="screwed-footer__bottom">
				<p class="screwed-footer__copy">
					&copy; <?php echo esc_html( date( 'Y' ) ); ?>
					<?php esc_html_e( 'How Screwed Am I?', 'screwed' ); ?>
					<span class="screwed-footer__copy-sep" aria-hidden="true">&bull;</span>
					<?php esc_html_e( 'Built to expose the fine print.', 'screwed' ); ?>
				</p>
			</div><!-- .screwed-footer__bottom -->

		</div><!-- .screwed-container -->
	</div><!-- .screwed-footer__main -->

</footer><!-- .screwed-footer -->

<?php wp_footer(); ?>
</body>
</html>
