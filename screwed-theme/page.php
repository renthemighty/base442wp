<?php
/**
 * The template for displaying all static Pages.
 *
 * Handles any WordPress page (?page_id=...) that does not have a dedicated
 * page-{slug}.php or page-{id}.php template in the theme. Outputs the full
 * page content with optional comments and no sidebar, keeping the reading
 * experience clean for the TOS-analyzer landing context.
 *
 * @package screwed
 */

get_header();
?>

<main id="screwed-main" class="screwed-main screwed-main--page" role="main">
    <div class="screwed-container screwed-container--narrow">

        <?php
        while ( have_posts() ) :
            the_post();
        ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class( 'screwed-page' ); ?>>

                <?php if ( ! is_front_page() ) : ?>
                    <header class="screwed-page__header">
                        <h1 class="screwed-page__title"><?php the_title(); ?></h1>
                        <?php
                        // Show the featured image as a page hero when present and not
                        // on the front page (which manages its own hero section).
                        if ( has_post_thumbnail() ) :
                        ?>
                            <div class="screwed-page__featured-image">
                                <?php the_post_thumbnail( 'large', array( 'alt' => get_the_title() ) ); ?>
                            </div><!-- .screwed-page__featured-image -->
                        <?php endif; ?>
                    </header><!-- .screwed-page__header -->
                <?php endif; ?>

                <div class="screwed-page__content entry-content">
                    <?php
                    the_content();

                    // Paginate long pages using <!--nextpage--> dividers.
                    wp_link_pages( array(
                        'before'      => '<nav class="screwed-page__pagination" aria-label="' . esc_attr__( 'Page pagination', 'screwed' ) . '"><span class="screwed-page__pagination-label">' . __( 'Pages:', 'screwed' ) . '</span>',
                        'after'       => '</nav>',
                        'link_before' => '<span class="screwed-page__pagination-item">',
                        'link_after'  => '</span>',
                    ) );
                    ?>
                </div><!-- .screwed-page__content -->

                <?php if ( get_edit_post_link() ) : ?>
                    <footer class="screwed-page__footer">
                        <?php
                        edit_post_link(
                            sprintf(
                                /* translators: %s: Post title. Only visible to screen readers. */
                                __( 'Edit <span class="screen-reader-text">%s</span>', 'screwed' ),
                                wp_kses_post( get_the_title() )
                            ),
                            '<span class="screwed-page__edit-link">',
                            '</span>'
                        );
                        ?>
                    </footer><!-- .screwed-page__footer -->
                <?php endif; ?>

            </article><!-- #post-<?php the_ID(); ?> -->

            <?php
            // Show comments if enabled for this page.
            if ( comments_open() || get_comments_number() ) :
                comments_template();
            endif;
            ?>

        <?php endwhile; ?>

    </div><!-- .screwed-container -->
</main><!-- #screwed-main -->

<?php
get_footer();
