<?php get_header(); ?>

<main id="{PREFIX}-main" class="{PREFIX}-main {PREFIX}-main--page">
    <div class="{PREFIX}-container {PREFIX}-container--narrow">
        <?php while ( have_posts() ) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class( '{PREFIX}-page' ); ?>>
                <h1 class="{PREFIX}-page__title"><?php the_title(); ?></h1>
                <div class="{PREFIX}-page__content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
</main>

<?php get_footer(); ?>
