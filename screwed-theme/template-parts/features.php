<?php
/**
 * Template Part: Features
 *
 * Six-card features grid for the "How Screwed Am I?" / Screwed TOS Scan landing page.
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
 * @package ScrewedTheme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feature cards data.
 * Each feature: emoji icon, title, description.
 * data-delay values (1–6) stagger the screwed-animate entrance.
 */
$screwed_features = array(
	array(
		'emoji' => '&#x1F50D;',
		'title' => 'Villain Score',
		'desc'  => '0-100 score showing just how badly a company is trying to screw you.',
	),
	array(
		'emoji' => '&#x26A1;',
		'title' => 'Instant Analysis',
		'desc'  => 'Results in seconds, not hours. No waiting, no login, no BS.',
	),
	array(
		'emoji' => '&#x1F3AD;',
		'title' => 'Snarky Summaries',
		'desc'  => 'Legal jargon translated into plain English with our signature attitude.',
	),
	array(
		'emoji' => '&#x1F4CB;',
		'title' => 'Category Breakdown',
		'desc'  => 'Busted / Sketchy / Sus ratings for each problematic clause.',
	),
	array(
		'emoji' => '&#x1F512;',
		'title' => '100% Private',
		'desc'  => 'All analysis happens on your device. We never see your data. Ever.',
	),
	array(
		'emoji' => '&#x1F4F1;',
		'title' => 'Works Anywhere',
		'desc'  => 'iOS, Android, wherever you are. Because bad TOS follows you everywhere.',
	),
);
?>

<section id="features" class="screwed-section">
	<div class="screwed-container">

		<!-- Section header -->
		<div class="screwed-section__header">
			<p class="screwed-eyebrow">FEATURES</p>
			<h2 class="screwed-section__title">Everything you need to know you&#8217;re screwed</h2>
		</div>

		<!-- Features grid — 6 cards, data-delay 1–6 -->
		<div class="screwed-features__grid">

			<?php foreach ( $screwed_features as $index => $feature ) : ?>

				<div
					class="screwed-features__card screwed-animate"
					data-delay="<?php echo esc_attr( $index + 1 ); ?>"
				>

					<!-- Emoji icon -->
					<div class="screwed-features__icon" aria-hidden="true">
						<?php echo $feature['emoji']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>

					<!-- Feature content -->
					<h3 class="screwed-features__title">
						<?php echo esc_html( $feature['title'] ); ?>
					</h3>
					<p class="screwed-features__desc">
						<?php echo esc_html( $feature['desc'] ); ?>
					</p>

				</div><!-- /.screwed-features__card -->

			<?php endforeach; ?>

		</div><!-- /.screwed-features__grid -->

	</div><!-- /.screwed-container -->
</section><!-- /#features -->
