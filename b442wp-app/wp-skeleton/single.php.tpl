<?php get_header(); ?>

<main id="{PREFIX}-main" class="{PREFIX}-main {PREFIX}-main--single">
    <div class="{PREFIX}-container {PREFIX}-container--narrow">
        <?php while ( have_posts() ) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class( '{PREFIX}-single' ); ?>>
                <?php if ( has_post_thumbnail() ) : ?>
                    <div class="{PREFIX}-single__thumbnail">
                        <?php the_post_thumbnail( 'large' ); ?>
                    </div>
                <?php endif; ?>

                <header class="{PREFIX}-single__header">
                    <h1 class="{PREFIX}-single__title"><?php the_title(); ?></h1>
                    <div class="{PREFIX}-single__meta">
                        <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
                            <?php echo esc_html( get_the_date() ); ?>
                        </time>
                    </div>
                </header>

                <div class="{PREFIX}-single__content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>
    </div>
</main>

<?php get_footer(); ?>
