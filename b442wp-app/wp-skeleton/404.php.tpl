<?php get_header(); ?>

<main id="{PREFIX}-main" class="{PREFIX}-main {PREFIX}-main--404">
    <div class="{PREFIX}-container {PREFIX}-container--narrow" style="text-align:center;padding:6rem 1.5rem;">
        <h1 class="{PREFIX}-404__title" style="font-size:6rem;font-weight:900;opacity:.15;line-height:1;margin:0;">
            404
        </h1>
        <h2 class="{PREFIX}-404__subtitle">
            <?php esc_html_e( 'Page not found', '{TEXT_DOMAIN}' ); ?>
        </h2>
        <p class="{PREFIX}-404__desc">
            <?php esc_html_e( "Sorry, we couldn't find the page you're looking for.", '{TEXT_DOMAIN}' ); ?>
        </p>
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="{PREFIX}-btn {PREFIX}-btn--primary">
            <?php esc_html_e( '← Back to Home', '{TEXT_DOMAIN}' ); ?>
        </a>
    </div>
</main>

<?php get_footer(); ?>
