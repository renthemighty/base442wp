<?php
/**
 * Template Part: Roadmap
 *
 * Timeline-style "Coming Soon" roadmap section for the "How Screwed Am I?" / Screwed TOS Scan landing page.
 * CSS prefix: screwed-   Animation class: screwed-animate
 * toggled to screwed-animate--visible by JS IntersectionObserver
 *
 * Background: dark (#111827)
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
 * Roadmap items data.
 * Each item: title and description.
 * data-delay values (1–4) stagger the screwed-animate entrance.
 */
$screwed_roadmap_items = array(
	array(
		'title' => 'Analysis History',
		'desc'  => 'Keep track of every company you\'ve caught red-handed.',
	),
	array(
		'title' => 'Share as Image',
		'desc'  => 'Screenshot your score and shame companies on social media.',
	),
	array(
		'title' => 'Side-by-Side Compare',
		'desc'  => 'Stack two TOS documents and see who\'s worse.',
	),
	array(
		'title' => 'TOS Update Alerts',
		'desc'  => 'Get notified when companies quietly change their terms.',
	),
);
?>

<section id="roadmap" class="screwed-section screwed-section--dark">
	<div class="screwed-container">

		<!-- Section header -->
		<div class="screwed-section__header">
			<p class="screwed-eyebrow">COMING SOON</p>
			<h2 class="screwed-section__title">We&#8217;re just getting started</h2>
		</div>

		<!-- Roadmap timeline list -->
		<ol class="screwed-roadmap__list" role="list">

			<?php foreach ( $screwed_roadmap_items as $index => $item ) : ?>

				<li
					class="screwed-roadmap__item screwed-animate"
					data-delay="<?php echo esc_attr( $index + 1 ); ?>"
				>

					<!-- Timeline node with connecting line -->
					<div class="screwed-roadmap__node" aria-hidden="true">
						<span class="screwed-roadmap__dot"></span>
						<?php if ( $index < count( $screwed_roadmap_items ) - 1 ) : ?>
							<span class="screwed-roadmap__line"></span>
						<?php endif; ?>
					</div>

					<!-- Item content -->
					<div class="screwed-roadmap__content">
						<h3 class="screwed-roadmap__title">
							<?php echo esc_html( $item['title'] ); ?>
						</h3>
						<p class="screwed-roadmap__desc">
							<?php echo esc_html( $item['desc'] ); ?>
						</p>
					</div><!-- /.screwed-roadmap__content -->

				</li><!-- /.screwed-roadmap__item -->

			<?php endforeach; ?>

		</ol><!-- /.screwed-roadmap__list -->

	</div><!-- /.screwed-container -->
</section><!-- /#roadmap.screwed-section--dark -->
