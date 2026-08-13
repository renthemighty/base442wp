# Conversion Learnings Feed

Append-only log of lessons from real site conversions.
Each entry is injected into every Claude API call as lived experience.
Add new entries at the bottom. Never edit or delete existing entries.

Format:
## YYYY-MM-DD — [Site] — [Topic]
**Observed:** what went wrong or what we noticed
**Root cause:** why it happened
**Rule:** what to do going forward

---

## 2026-06-08 — Inkwell Co. — WordPress Navigation block ignores margin-left: auto in JSON

**Observed:** The nav was left-aligned on all pages despite `style.spacing.margin.left: "auto"` in the block JSON template.

**Root cause:** WordPress's Navigation block does not reliably translate block JSON spacing attributes into CSS margin on its wrapper element. The attribute is silently ignored at render time.

**Rule:** Never rely on block JSON `style.spacing.margin` for Navigation block alignment. Always add a CSS override:
```css
.site-nav .wp-block-navigation { margin-left: auto !important; }
```
`!important` is required because the block editor injects inline styles that otherwise win the cascade.

---

## 2026-06-08 — Inkwell Co. — wp_footer fires on FSE block template pages too

**Observed:** A cart drawer injected via `add_action('wp_footer', ...)` in `inc/woocommerce.php` rendered as raw unstyled HTML below the footer on every FSE page (homepage, bundles, about), not just WooCommerce cart/checkout pages.

**Root cause:** `wp_footer` fires on every WordPress page without exception — FSE block templates, PHP templates, all of them.

**Rule:** Any `add_action('wp_footer', ...)` that should only appear on WooCommerce pages must be guarded:
```php
if ( is_woocommerce() || is_cart() || is_checkout() || is_product() ) {
    // inject
}
```
Also: the CSS class in the injected HTML must exactly match the selector in the stylesheet. A mismatch (e.g. `.inkwell-cart-drawer` in HTML vs `.ink-cart-drawer` in CSS) renders raw visible HTML on every page and is invisible from CSS inspection alone.

---

## 2026-06-08 — Inkwell Co. — CSS media query shorthand resets all four padding sides

**Observed:** A hero section vertical spacing fix was applied in the base rule (`padding: 80px 24px`) but the responsive media query copied the original shorthand (`padding: 120px 64px`), silently re-introducing 120px top/bottom on wider viewports.

**Root cause:** CSS shorthand `padding: Xpx Ypx` always sets all four sides. If the base rule corrects the vertical and the media query only intends to adjust horizontal, using shorthand in the media query overwrites the vertical fix.

**Rule:** When a media query only needs to change one axis, use axis-specific properties:
```css
/* Base — sets all four sides */
.hero { padding: 80px 24px !important; }

/* Responsive — ONLY change horizontal, never re-declare vertical here */
@media (min-width: 768px) {
    .hero {
        padding-left: 64px !important;
        padding-right: 64px !important;
    }
}
```

---

## 2026-06-08 — Inkwell Co. — WooCommerce mini-cart blocks inject into FSE Navigation

**Observed:** WooCommerce automatically injects a mini-cart block into the site's Navigation block, adding a cart icon and flyout panel that conflicted with the theme's custom cart drawer.

**Root cause:** WooCommerce hooks into the Navigation block and adds its own cart component when the block is present in an FSE template.

**Rule:** If the theme uses a custom cart drawer (JS-driven slide-out), suppress the WC mini-cart block injection in `inc/woocommerce.php`:
```php
add_filter( 'woocommerce_blocks_register_feature_plugin_block', function( $register, $block_name ) {
    if ( $block_name === 'woocommerce/mini-cart' ) return false;
    return $register;
}, 10, 2 );
```
Or target with CSS if the block still renders: `.wc-block-mini-cart { display: none !important; }`

---

## 2026-06-08 — Inkwell Co. — Product images missing after conversion (grey placeholders)

**Observed:** All product cards on the bundles page showed grey placeholder boxes instead of product images after conversion.

**Root cause:** Base44 exports contain React component code that references images via JS imports (e.g. `import heroImg from './assets/hero.jpg'`). These are build-time imports resolved by Vite — the actual image files are in the compiled `dist/` or `public/` folder, not in the component source the Parser reads. The Parser only reads source files (JSX/JS/CSS), so image paths are never extracted and no actual image files land in the WordPress theme.

**Rule:** The zip-based pipeline cannot recover product images. The Playwright live scan is the only reliable way to get them. When scanning the live site, extract all `<img src>` attributes and `background-image` CSS values with absolute URLs, download them, and include them in the theme's `assets/images/` folder. Map the original filenames to WordPress attachment imports in the demo content importer.

For WooCommerce product images specifically: the live scan must visit each product URL, extract the featured image URL, and store it alongside the product data so the demo content importer can attach images to products on import.

---

## 2026-06-08 — Kinderpunt — Auth pages in ZIP sub_pages produce useless WP templates

**Observed:** A Base44 ecommerce app exported with 5 pages — the homepage plus forgotpassword, login, register, resetpassword. The converter spent ~950s generating WordPress page templates for all four auth pages, producing useless output that a WordPress site doesn't need (WP has its own auth system).

**Root cause:** Base44 apps frequently include auth flow pages (login, register, forgot-password, reset-password) as full React pages in the ZIP. The Parser correctly detects them as sub_pages. The ThemeBuilder then calls Claude for each one, wasting API calls and time.

**Rule:** Filter auth pages out of sub_pages before the pages stage. Any sub_page whose slug matches these patterns should be skipped: `login`, `register`, `signup`, `forgot.*password`, `reset.*password`, `verify.*email`, `logout`, `auth`, `oauth`. These map to WordPress's built-in auth system — no custom templates needed.

---

## 2026-06-08 — Kinderpunt — Base44 editor URLs hang the SiteCrawler indefinitely

**Observed:** When `live_url` is a Base44 editor workspace URL (`app.base44.com/apps/*/editor/*`), SiteCrawler sends HTTP requests to an auth-gated endpoint. The scraper receives no bytes and hangs until the PHP execution limit kills the process. The conversion stays stuck at `converting` with no error message because PHP Fatal Error from `set_time_limit` expiry is uncatchable.

**Root cause:** Base44 editor URLs require authentication. They are not live sites — they are the Base44 app builder UI, not the deployed app. SiteCrawler cannot distinguish this and blocks indefinitely.

**Rule:** Before SiteCrawler runs, check if `live_url` matches `app.base44.com`. If it does, skip live scraping entirely and proceed ZIP-only. Check: `strpos($live_url, 'app.base44.com') !== false`. Log a warning but do not treat it as an error — the ZIP analysis provides sufficient data.

---

## 2026-06-08 — Kinderpunt — Filter tabs (ALL / JOURNEY / WHOLESALE / PREMIUM) require JS category filtering

**Observed:** The bundles page had category filter tabs that visually rendered correctly but had no filtering behaviour — clicking a tab did nothing.

**Root cause:** Base44's filter tabs are pure React state — clicking sets a filter value in component state, React re-renders only matching items. WordPress has no equivalent. The converter generated the HTML for the tabs but no JS to connect them to WooCommerce product visibility.

**Rule:** When a Base44 source component has filter tabs above a product grid, generate a JS-driven filter using WooCommerce product category slugs as data attributes:
```html
<div class="bundle-card" data-category="journey">...</div>
```
```js
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        const cat = tab.dataset.filter;
        document.querySelectorAll('.bundle-card').forEach(card => {
            card.style.display = (cat === 'all' || card.dataset.category === cat) ? '' : 'none';
        });
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
    });
});
```
WooCommerce product categories must be created to match the tab labels, and products assigned to them at import time.

---
