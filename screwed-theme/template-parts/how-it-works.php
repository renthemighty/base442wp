<?php
/**
 * Template Part: How It Works
 *
 * Three-step explainer section for the "How Screwed Am I?" / Screwed TOS Scan landing page.
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
 * Steps data.
 * Each step: number, title, description.
 * data-delay values (1–3) stagger the screwed-animate entrance.
 */
$screwed_steps = array(
	array(
		'number' => '1',
		'title'  => 'Paste or Upload',
		'desc'   => 'Drop in any TOS URL, upload the document, or paste text directly.',
	),
	array(
		'number' => '2',
		'title'  => 'AI Reads It',
		'desc'   => 'Our AI analyzes the document against known consumer rights patterns.',
	),
	array(
		'number' => '3',
		'title'  => 'Get Your Score',
		'desc'   => 'See your Villain Score, key findings, and plain-English translations.',
	),
);
?>

<section id="how-it-works" class="screwed-section screwed-section--alt">
	<div class="screwed-container">

		<!-- Section header -->
		<div class="screwed-section__header">
			<p class="screwed-eyebrow">HOW IT WORKS</p>
			<h2 class="screwed-section__title">Three steps to the truth</h2>
		</div>

		<!-- Steps — numbered cards -->
		<ol class="screwed-steps__list" role="list">

			<?php foreach ( $screwed_steps as $index => $step ) : ?>

				<li
					class="screwed-steps__card screwed-animate"
					data-delay="<?php echo esc_attr( $index + 1 ); ?>"
				>

					<!-- Step number badge -->
					<div class="screwed-steps__number" aria-label="Step <?php echo esc_attr( $step['number'] ); ?>">
						<?php echo esc_html( $step['number'] ); ?>
					</div>

					<!-- Step content -->
					<h3 class="screwed-steps__title">
						<?php echo esc_html( $step['title'] ); ?>
					</h3>
					<p class="screwed-steps__desc">
						<?php echo esc_html( $step['desc'] ); ?>
					</p>

				</li><!-- /.screwed-steps__card -->

			<?php endforeach; ?>

		</ol><!-- /.screwed-steps__list -->

	</div><!-- /.screwed-container -->
</section><!-- /#how-it-works -->
