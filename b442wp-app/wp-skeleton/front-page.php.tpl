<?php get_header(); ?>

<main id="{PREFIX}-main" class="{PREFIX}-main">
    <?php get_template_part( 'template-parts/hero' ); ?>
    <?php // Additional sections will be output by the converter based on detected pages ?>
    {SECTION_PARTS}
</main>

<?php get_footer(); ?>
