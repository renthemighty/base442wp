<?php
/**
 * Front Page Template
 *
 * Rendered when the site's front page is set to a static page
 * (Appearance > Customize > Homepage Settings) OR when WordPress
 * falls back to the front page. Pulls in each section via template
 * parts so each section can be developed and edited independently.
 *
 * @package screwed
 */

get_header();
?>

<main id="screwed-main" class="screwed-main" role="main">

	<?php get_template_part( 'template-parts/hero' ); ?>

	<?php get_template_part( 'template-parts/how-it-works' ); ?>

	<?php get_template_part( 'template-parts/features' ); ?>

	<?php get_template_part( 'template-parts/roadmap' ); ?>

	<?php get_template_part( 'template-parts/download-cta' ); ?>

</main><!-- #screwed-main -->

<?php get_footer(); ?>
