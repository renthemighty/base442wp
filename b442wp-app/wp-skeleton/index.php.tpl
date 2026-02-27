<?php get_header(); ?>

<main id="{PREFIX}-main" class="{PREFIX}-main">
    <div class="{PREFIX}-container">
        <?php if ( have_posts() ) : ?>
            <?php while ( have_posts() ) : the_post(); ?>
                <article id="post-<?php the_ID(); ?>" <?php post_class( '{PREFIX}-post' ); ?>>
                    <h1 class="{PREFIX}-post__title"><?php the_title(); ?></h1>
                    <div class="{PREFIX}-post__content">
                        <?php the_content(); ?>
                    </div>
                </article>
            <?php endwhile; ?>
        <?php else : ?>
            <div class="{PREFIX}-no-results">
                <h1><?php esc_html_e( 'Nothing found', '{TEXT_DOMAIN}' ); ?></h1>
                <p><?php esc_html_e( 'Try adjusting your search or filter to find what you\'re looking for.', '{TEXT_DOMAIN}' ); ?></p>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php get_footer(); ?>
