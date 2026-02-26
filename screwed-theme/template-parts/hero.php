<?php
/**
 * Template Part: Hero
 *
 * Full-screen hero section for the "How Screwed Am I?" / Screwed TOS Scan landing page.
 * CSS prefix: screwed-   Animation class: screwed-animate
 * toggled to screwed-animate--visible by JS IntersectionObserver
 *
 * Design tokens:
 *   orange       = #e8983f
 *   orange-dark  = #d4882f
 *   cream        = #fcf7ee
 *   navy         = #1B2A5C
 *   muted        = #6b7280
 *   dark-bg      = #111827
 *   darker       = #030712
 *
 * Typography: ui-sans-serif system stack + Inter (Google Fonts)
 * Headline: font-black (900), text-5xl→8xl, tracking-tight, leading-[0.95]
 *
 * PHP helpers used: screwed_google_play(), screwed_app_store()
 *
 * @package ScrewedTheme
 */

defined( 'ABSPATH' ) || exit;
?>

<section id="hero" class="screwed-hero">

	<!-- Decorative background gradient blobs -->
	<div class="screwed-hero__orb screwed-hero__orb--1" aria-hidden="true"></div>
	<div class="screwed-hero__orb screwed-hero__orb--2" aria-hidden="true"></div>
	<div class="screwed-hero__orb screwed-hero__orb--3" aria-hidden="true"></div>

	<div class="screwed-container">
		<div class="screwed-hero__content screwed-animate">

			<!-- Badge pill: shield icon + tagline -->
			<div class="screwed-hero__badge" role="note">
				<svg
					class="screwed-hero__badge-icon"
					width="16"
					height="16"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					stroke-width="2.5"
					stroke-linecap="round"
					stroke-linejoin="round"
					aria-hidden="true"
					focusable="false"
				>
					<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
				</svg>
				<span>Your privacy, decoded</span>
			</div>

			<!-- H1 Headline
			     Line 1: "How Screwed"
			     Line 2: "Am I" + accent "?" + animated underline bar
			-->
			<h1 class="screwed-hero__title">
				How Screwed<br>
				<span class="screwed-hero__title-wrap underline-wrap">Am I<span class="accent">?</span><span class="underline-bar" aria-hidden="true"></span></span>
			</h1>

			<!-- Sub-headline -->
			<p class="screwed-hero__desc">
				That Terms of Service you didn&#8217;t read? We did.<br>
				<strong>AI-powered analysis that tells you exactly how bad it is.</strong>
			</p>

			<!-- CTA buttons -->
			<div class="screwed-hero__buttons">

				<!-- Google Play — colorful four-quadrant play-store logo -->
				<a
					href="<?php echo esc_url( screwed_google_play() ); ?>"
					class="screwed-btn screwed-btn--primary screwed-btn--lg"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="Get it on Google Play"
				>
					<!-- Google Play colorful four-triangle logo -->
					<svg
						class="screwed-btn__icon"
						width="20"
						height="20"
						viewBox="0 0 512 512"
						aria-hidden="true"
						focusable="false"
						xmlns="http://www.w3.org/2000/svg"
					>
						<defs>
							<linearGradient id="gp-blue" x1="0" y1="0" x2="1" y2="0">
								<stop offset="0%" stop-color="#00c3ff"/>
								<stop offset="100%" stop-color="#1976d2"/>
							</linearGradient>
							<linearGradient id="gp-green" x1="0" y1="0" x2="1" y2="1">
								<stop offset="0%" stop-color="#5fe87c"/>
								<stop offset="100%" stop-color="#04b151"/>
							</linearGradient>
							<linearGradient id="gp-red" x1="0" y1="0" x2="0" y2="1">
								<stop offset="0%" stop-color="#ff4759"/>
								<stop offset="100%" stop-color="#c5143c"/>
							</linearGradient>
							<linearGradient id="gp-yellow" x1="0" y1="0" x2="1" y2="0">
								<stop offset="0%" stop-color="#ffe959"/>
								<stop offset="100%" stop-color="#ff9800"/>
							</linearGradient>
						</defs>
						<!-- Blue: top-left triangle -->
						<path fill="url(#gp-blue)"   d="M56 24 L56 248 L280 248 Z"/>
						<!-- Green: bottom-left triangle -->
						<path fill="url(#gp-green)"  d="M56 488 L56 264 L280 264 Z"/>
						<!-- Red: upper-right triangle -->
						<path fill="url(#gp-red)"    d="M56 24 L432 218 L280 248 Z"/>
						<!-- Yellow: lower-right triangle -->
						<path fill="url(#gp-yellow)" d="M56 488 L432 294 L280 264 Z"/>
						<!-- Center join: fills the mid-right gap -->
						<path fill="url(#gp-yellow)" d="M280 248 L432 218 L432 294 L280 264 Z"/>
					</svg>
					<span>Google Play</span>
				</a>

				<!-- App Store — Apple logo -->
				<a
					href="<?php echo esc_url( screwed_app_store() ); ?>"
					class="screwed-btn screwed-btn--outline screwed-btn--lg"
					target="_blank"
					rel="noopener noreferrer"
					aria-label="Download on the App Store"
				>
					<!-- Apple logo (standard path) -->
					<svg
						class="screwed-btn__icon"
						width="20"
						height="20"
						viewBox="0 0 814 1000"
						fill="currentColor"
						aria-hidden="true"
						focusable="false"
						xmlns="http://www.w3.org/2000/svg"
					>
						<path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.5-164-39.5c-76 0-103.7 40.8-165.9 40.8s-105-37.3-155.5-127.2C45.5 727.8 0 617.8 0 512.6 0 322 123.6 220.2 245.3 220.2c62 0 113.6 41.5 151.7 41.5 36.3 0 97.2-44.5 168.6-44.5 26.7 0 107.7 3.8 159.5 57.6zm-195-102.4c-14.2 16.8-54 46.8-108.3 46.8-5.8 0-11.6-.6-17.3-1.3C470.8 178.6 488.1 120 543 78.9c28.6-21.3 77.4-38.9 112-40.3 3.8 54.3-16.1 108.3-61.9 159.9z"/>
					</svg>
					<span>App Store</span>
				</a>

			</div><!-- /.screwed-hero__buttons -->

			<!-- Trust / privacy line -->
			<p class="screwed-hero__trust">
				100% on-device &#8226; No data stored &#8226; No backend
			</p>

		</div><!-- /.screwed-hero__content -->
	</div><!-- /.screwed-container -->

</section><!-- /#hero.screwed-hero -->
