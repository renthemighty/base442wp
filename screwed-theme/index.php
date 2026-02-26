<?php
/**
 * The main template file — fallback for all content types.
 *
 * WordPress uses this when no more specific template (page.php, single.php, etc.)
 * matches the current request. It renders a standard loop with sidebar support.
 *
 * @package screwed
 */

get_header();
?>

<main id="screwed-main" class="screwed-main screwed-main--index" role="main">
    <div class="screwed-container">

        <?php if ( have_posts() ) : ?>

            <div class="screwed-posts-grid">

                <?php
                while ( have_posts() ) :
                    the_post();

                    /*
                     * Include the template part for displaying content.
                     * Chooses template-parts/content-{format}.php when a post
                     * format is assigned, otherwise falls back to
                     * template-parts/content.php.
                     */
                    get_template_part( 'template-parts/content', get_post_format() );

                endwhile;
                ?>

            </div><!-- .screwed-posts-grid -->

            <?php
            the_posts_navigation( array(
                'prev_text'          => __( '&larr; Older posts', 'screwed' ),
                'next_text'          => __( 'Newer posts &rarr;', 'screwed' ),
                'screen_reader_text' => __( 'Posts navigation', 'screwed' ),
            ) );
            ?>

        <?php else : ?>

            <div class="screwed-no-results">
                <h2 class="screwed-no-results__title">
                    <?php esc_html_e( 'Nothing found.', 'screwed' ); ?>
                </h2>
                <p class="screwed-no-results__text">
                    <?php esc_html_e( 'It looks like nothing was found at this location. Maybe try a search?', 'screwed' ); ?>
                </p>
                <?php get_search_form(); ?>
            </div><!-- .screwed-no-results -->

        <?php endif; ?>

    </div><!-- .screwed-container -->
</main><!-- #screwed-main -->

<?php
get_sidebar();
get_footer();
