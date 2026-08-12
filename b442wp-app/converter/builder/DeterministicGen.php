<?php
/**
 * DeterministicGen — Generates WordPress theme files without any AI calls.
 *
 * Every file produced here is template-based with token replacement from
 * the Analyzer output. This covers ~70% of the theme by volume.
 */
declare(strict_types=1);

class DeterministicGen
{
    private string $prefix;
    private string $php_prefix;
    private string $const_prefix;
    private array $analysis;
    private array $page_contents;

    public function __construct(string $prefix, array $analysis, array $page_contents = [])
    {
        $this->prefix        = $prefix;
        $this->php_prefix    = str_replace('-', '_', $prefix);
        $this->const_prefix  = strtoupper($this->php_prefix);
        $this->analysis      = $analysis;
        $this->page_contents = $page_contents;
    }

    // ─── Live-scan ground truth (v2.1.0) ─────────────────────────────────────

    /**
     * Normalized design values measured on the live site by the Playwright
     * scanner (analysis['live_scan']). Returns [] when no scan is present, in
     * which case all callers fall back to the CSS-bundle heuristics.
     *
     * Keys (all optional): heading_stack, body_stack, background, text,
     * primary, footer_bg, h1 (props array), h2 (props array), google_urls.
     */
    private function liveScanDesign(): array
    {
        $scan = $this->analysis['live_scan'] ?? [];
        $c = $scan['computed'] ?? [];
        if (empty($c)) return [];

        $design = [];
        $stack = function (string $v): string {
            // Computed font-family strings may contain double quotes; the
            // generated PHP/CSS embeds them in double-quoted contexts → normalize.
            return str_replace('"', "'", trim($v));
        };

        $heading_ff = $c['h1']['font-family'] ?? ($c['h2']['font-family'] ?? '');
        if ($heading_ff !== '') $design['heading_stack'] = $stack($heading_ff);
        if (!empty($c['body']['font-family'])) $design['body_stack'] = $stack($c['body']['font-family']);

        // Per-heading typographic fidelity (emitted as CSS rules)
        foreach (['h1', 'h2'] as $h) {
            if (empty($c[$h])) continue;
            $props = [];
            if (!empty($c[$h]['font-family'])) $props['font-family'] = $stack($c[$h]['font-family']);
            foreach (['font-weight', 'font-style', 'text-transform', 'letter-spacing'] as $pk) {
                if (isset($c[$h][$pk]) && $c[$h][$pk] !== '') $props[$pk] = $c[$h][$pk];
            }
            if (!empty($props)) $design[$h] = $props;
        }

        // Palette (hex; transparent/invalid values dropped)
        $pal = $scan['palette'] ?? [];
        $hexmap = [
            'background' => $pal['background'] ?? '',
            'text'       => $pal['text'] ?? '',
            'primary'    => $pal['link'] ?? '',     // link/accent color = best primary signal
        ];
        foreach ($hexmap as $k => $v) {
            $hex = self::cssColorToHex((string) $v);
            if ($hex !== null) $design[$k] = $hex;
        }

        // Google Fonts URLs (sanitized: https://fonts.googleapis.com only)
        $urls = [];
        foreach (($scan['fonts']['google_links'] ?? []) as $u) {
            $u = trim((string) $u);
            if (preg_match('#^https://fonts\.googleapis\.com/[A-Za-z0-9/?&=:;,+._@%-]+$#', $u)) {
                $urls[] = $u;
            }
        }
        if (!empty($urls)) $design['google_urls'] = array_values(array_unique($urls));

        return $design;
    }

    /**
     * rgb()/rgba()/#hex CSS color → #rrggbb, or null for transparent/unparseable.
     */
    private static function cssColorToHex(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('/^#([0-9a-f]{6})$/i', $v, $m)) return '#' . strtolower($m[1]);
        if (preg_match('/^#([0-9a-f]{3})$/i', $v, $m)) {
            return '#' . strtolower($m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2]);
        }
        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([0-9.]+))?\s*\)$/i', $v, $m)) {
            if (isset($m[4]) && (float) $m[4] < 0.5) return null; // mostly transparent
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        return null;
    }

    /**
     * Relative luminance (0 = black, 1 = white) of a #rrggbb hex color.
     */
    private static function hexLuma(string $hex): float
    {
        $h = ltrim($hex, '#');
        if (strlen($h) !== 6 || !ctype_xdigit($h)) return 0.5;
        return (0.2126 * hexdec(substr($h, 0, 2))
              + 0.7152 * hexdec(substr($h, 2, 2))
              + 0.0722 * hexdec(substr($h, 4, 2))) / 255;
    }

    // ─── Shared content filters (used here and in ThemeBuilder WXR path) ─────

    /**
     * True when the content is clearly raw JS/JSX source (React component code
     * leaked through the renderer) rather than real HTML.
     */
    public static function isJsxSource(string $content): bool
    {
        if (trim($content) === '') return false;
        if (preg_match('/^\s*import\s/m', $content)) return true;
        if (preg_match('/\bfrom\s+[\'"](?:react|framer-motion|@\/)/', $content)) return true;
        if (preg_match('/\bexport\s+default\s+function\b/', $content)) return true;
        if (preg_match('/<\w+\s+className=/', $content) && preg_match('/=>/', $content)) return true;
        return false;
    }

    /**
     * True when the slug is an auth/admin/utility page or a React Router
     * parameterized route template — neither should become a WP page.
     */
    public static function isSkippableSlug(string $slug): bool
    {
        // Route-param templates like shop/:id, TanStack product.$id, wildcards
        if (strpos($slug, ':') !== false || strpos($slug, '*') !== false || strpos($slug, '$') !== false) return true;
        // Normalize: lowercase, strip non-alphanumerics, then substring match
        $norm = preg_replace('/[^a-z0-9]/', '', strtolower($slug));
        if ($norm === 'notfound') return true;
        foreach (['login', 'register', 'signup', 'signin', 'logout', 'forgotpassword', 'resetpassword', 'admin', 'dashboard', 'auth'] as $kw) {
            if (strpos($norm, $kw) !== false) return true;
        }
        return false;
    }

    /**
     * v3.1.0 FAITHFUL-COPY: section slugs that must NOT render on the page.
     * The deterministic header.php reproduces the nav verbatim, so navbar /
     * mobile-nav / header / nav sections would double-print it. seo-snapshot
     * is a non-visual SEO/auth artifact (invents Sign In / Register links).
     */
    public static function isChromeOrJunkSection(string $slug): bool
    {
        $norm = preg_replace('/[^a-z0-9]/', '', strtolower($slug));
        $junk = ['navbar', 'mobilenav', 'navmenu', 'header', 'nav', 'navigation',
                 'topbar', 'sitenav', 'seosnapshot', 'seo', 'sitemap'];
        foreach ($junk as $j) {
            if ($norm === $j) return true;
        }
        return false;
    }

    /**
     * True when a label/slug is a detail-page route (AlbumDetail, BlogArticle,
     * EventDetail, …). Detail pages must never become nav menu items (v2.2.0).
     */
    public static function isDetailNavTarget(string $labelOrSlug): bool
    {
        $norm = preg_replace('/[^a-z0-9]/', '', strtolower($labelOrSlug));
        if (preg_match('/(album|blog|event|song|product)detail/', $norm)) return true;
        if (preg_match('/article$/', $norm)) return true;
        return false;
    }

    /**
     * FAITHFUL nav model (v3.1.0). Single source of truth for the header nav,
     * the WP menu, and the footer nav. Built STRICTLY from the scanned nav
     * model (analysis.live_scan.nav = [{text, kind, target}]). The scanner
     * captured these VERBATIM from the rendered DOM (buttons + anchors +
     * role=menuitem). NEVER falls back to the page list, NEVER renames.
     *
     * Returns [['label', 'kind', 'href'], …] preserving DOM order:
     *   kind=page     → '/path' (internal converted page)   → home_url('/path')
     *   kind=anchor   → '/#slug' (home-anchored) or '#slug'  → home_url('/') . '#slug'
     *   kind=external → absolute URL / mailto: / tel:        → as-is
     *   kind=action   → source target if any, else '#'       → pure JS CTA
     *
     * If the scanned nav model is EMPTY, returns [] (a hard WARNING is logged
     * by the caller — there is no page-list fallback in faithful-copy mode).
     */
    private function navItems(): array
    {
        $items = [];
        $seen  = [];

        foreach (($this->analysis['live_scan']['nav'] ?? []) as $n) {
            $label = trim(preg_replace('/\s+/', ' ', (string) ($n['text'] ?? '')));
            if ($label === '' || strlen($label) > 60) continue;
            $key = strtolower($label);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $kind   = (string) ($n['kind'] ?? 'action');
            $target = (string) ($n['target'] ?? '');
            $href   = $this->navHref($kind, $target);
            $items[] = ['label' => $label, 'kind' => $kind, 'href' => $href];
        }
        return $items;
    }

    /**
     * Resolve a nav model {kind, target} into a usable href string.
     * Internal page/anchor hrefs stay path-relative (rendered with home_url()).
     */
    private function navHref(string $kind, string $target): string
    {
        $target = trim($target);
        switch ($kind) {
            case 'page':
                if ($target === '') return '/';
                return $target[0] === '/' ? $target : '/' . $target;
            case 'anchor':
                if ($target === '') return '/';
                // home-anchored: '/#slug'
                $anchor = $target[0] === '#' ? $target : '#' . ltrim($target, '#');
                return '/' . $anchor;
            case 'external':
                return $target !== '' ? $target : '#';
            case 'action':
            default:
                // pure JS CTA — keep the source target if it is a real URL/anchor
                if ($target === '' || $target === null) return '#';
                if (preg_match('#^(https?://|mailto:|tel:|/|#)#i', $target)) return $target;
                return '#';
        }
    }

    /** PHP expression that renders a nav href (home_url for internal paths). */
    private function navHrefPhp(string $href): string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') return "'#'";
        if (preg_match('#^(https?://|mailto:|tel:)#i', $href)) {
            return "'" . addslashes($href) . "'";
        }
        // internal path or '/#anchor'
        return "home_url('" . addslashes($href) . "')";
    }

    /**
     * Scanned site logo {src(local path after media import), alt}. The media
     * importer rewrites remote URLs to local theme paths; we look the logo URL
     * up in the media map so the header points at the bundled asset.
     */
    private function siteLogo(): array
    {
        $logo = $this->analysis['live_scan']['logo'] ?? null;
        if (!is_array($logo) || empty($logo['src'])) return [];
        $src   = (string) $logo['src'];
        $alt   = (string) ($logo['alt'] ?? ($this->analysis['theme_name'] ?? ''));
        $local = $this->analysis['media_map'][$src] ?? '';
        return ['src' => $src, 'alt' => $alt, 'local' => (string) $local];
    }

    // ─── v4.2.0 chrome transcription (header/footer verbatim from scan) ──────

    /**
     * Read the home page's scanned rendered HTML from
     * site-scan/scan/<html_file>, using the same site_scan_dir + manifest
     * resolution as ThemeBuilder::readScanHtml(). Returns '' when no scan
     * data is present (older jobs, or a scan that failed) so callers fall
     * back to the pre-v4.2.0 generic generation.
     */
    private function scanHomeHtml(): string
    {
        $dir   = (string) ($this->analysis['site_scan_dir'] ?? '');
        $pages = $this->analysis['site_scan']['pages'] ?? [];
        if ($dir === '' || empty($pages)) return '';

        $home = null;
        foreach ($pages as $pg) {
            if (($pg['slug'] ?? '') === 'home') { $home = $pg; break; }
        }
        if ($home === null) $home = $pages[0] ?? null;
        if (!is_array($home)) return '';

        $rel = (string) ($home['html_file'] ?? '');
        if ($rel === '') return '';

        $path = $dir . '/' . $rel;
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Depth-scan from an opening tag's byte offset to its matching closing
     * tag, counting nested same-name tags along the way. Used instead of
     * DOMDocument because scanned HTML can be malformed; a simple tokenizer
     * over just this one tag name is enough and never chokes on the rest of
     * the page. Returns '' if the tag never closes (bails rather than
     * risking a runaway match).
     */
    private function extractBalancedFrom(string $html, string $tagName, int $start): string
    {
        $openRe  = '/<' . $tagName . '\b[^>]*>/i';
        $closeRe = '/<\/' . $tagName . '\s*>/i';
        $len   = strlen($html);

        // Consume the anchor opening tag itself so the depth loop starts
        // counting from inside the element, not from the anchor's own open.
        if (!preg_match($openRe, $html, $om, PREG_OFFSET_CAPTURE, $start) || $om[0][1] !== $start) {
            return ''; // anchor tag not found at $start, bail rather than guess
        }
        $pos   = $start + strlen($om[0][0]);
        $depth = 0;

        while ($pos < $len) {
            $hasOpen  = preg_match($openRe, $html, $om, PREG_OFFSET_CAPTURE, $pos);
            $hasClose = preg_match($closeRe, $html, $cm, PREG_OFFSET_CAPTURE, $pos);
            if (!$hasClose) return ''; // unbalanced — bail rather than guess

            $openAt  = $hasOpen ? $om[0][1] : -1;
            $closeAt = $cm[0][1];

            if ($hasOpen && $openAt < $closeAt) {
                $depth++;
                $pos = $openAt + strlen($om[0][0]);
                continue;
            }

            if ($depth === 0) {
                $end = $closeAt + strlen($cm[0][0]);
                return substr($html, $start, $end - $start);
            }
            $depth--;
            $pos = $closeAt + strlen($cm[0][0]);
        }
        return '';
    }

    /** First element with tag $tagName, or (if none) the first element whose
     *  class attribute contains $classNeedle as a substring. Empty when
     *  neither is found. */
    private function extractFirstBalanced(string $html, string $tagName, string $classNeedle = ''): string
    {
        if (preg_match('/<' . $tagName . '\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $this->extractBalancedFrom($html, $tagName, $m[0][1]);
        }
        if ($classNeedle === '') return '';

        if (!preg_match('/<([a-z0-9]+)\b[^>]*class=["\'][^"\']*'
            . preg_quote($classNeedle, '/') . '[^"\']*["\'][^>]*>/i', $html, $cm, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        return $this->extractBalancedFrom($html, $cm[1][0], $cm[0][1]);
    }

    /** Last occurrence of $tagName, balanced. Empty when the tag never appears. */
    private function extractLastBalanced(string $html, string $tagName): string
    {
        if (!preg_match_all('/<' . $tagName . '\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) return '';
        $last = end($m[0]);
        return $this->extractBalancedFrom($html, $tagName, $last[1]);
    }

    /**
     * v4.3.0 Item 9: Strip frozen dynamic widget content baked into the scanned
     * HTML. Client-side date/time and weather widgets render a one-time snapshot
     * during the scan (e.g. "Tuesday, 14 July 2026", "23°C Overcast") and appear
     * as stale text in the converted theme. Strip the innermost element whose text
     * exclusively matches a frozen date or weather pattern so the converted page
     * doesn't carry visibly stale copy. Conservative: only removes elements that
     * are wholly the frozen content (no mixed text siblings), never strips an
     * ancestor that contains other real copy.
     *
     * Patterns stripped:
     *  - Dates: "Monday, 14 July 2026" / "Monday July 14" / "14 July 2026" etc.
     *  - Times: "14:32" / "2:32 PM"
     *  - Weather: "23°C Overcast" / "72°F Sunny" / "Partly Cloudy 18°C" etc.
     */
    public static function stripFrozenDynamicWidgets(string $html): string
    {
        if ($html === '') return $html;

        // Day-name patterns (abbreviated or full)
        $day    = '(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)';
        // Month-name patterns
        $month  = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        // Date patterns — anchored so partial matches inside longer sentences won't fire
        $date_pats = [
            // "Monday, 14 July 2026" or "Mon, 14 Jul 2026"
            "{$day},?\\s+\\d{1,2}\\s+{$month}\\s+\\d{4}",
            // "Monday July 14, 2026" or "Mon Jul 14 2026"
            "{$day},?\\s+{$month}\\s+\\d{1,2},?\\s*\\d{4}",
            // "14 July 2026"
            "\\d{1,2}\\s+{$month}\\s+\\d{4}",
            // "July 14, 2026"
            "{$month}\\s+\\d{1,2},\\s*\\d{4}",
        ];
        // Time patterns
        $time_pats = [
            "\\d{1,2}:\\d{2}(?::\\d{2})?\\s*(?:AM|PM|am|pm)?",
        ];
        // Weather patterns (temperature + condition or vice versa)
        $cond   = '(?:Sunny|Cloudy|Overcast|Rainy|Drizzle|Foggy|Windy|Snowy|Clear|Partly Cloudy|Mostly Cloudy|Thunderstorm|Showers|Haze|Mist|Sleet|Hail)';
        $temp   = '-?\\d{1,3}\\s*°\\s*[CF]';
        $weather_pats = [
            "{$temp}\\s+{$cond}",
            "{$cond}\\s+{$temp}",
            "{$temp}",   // bare temperature reading alone in an element
        ];

        // Build a combined inner-text pattern (element text must be ONLY this content,
        // possibly with a leading/trailing icon glyph or whitespace).
        $all_pats = array_merge($date_pats, $time_pats, $weather_pats);
        $inner_re = '/^[\\s\\x{2600}-\\x{26FF}\\x{1F300}-\\x{1F9FF}]*(?:' . implode('|', $all_pats) . ')[\\s\\x{2600}-\\x{26FF}\\x{1F300}-\\x{1F9FF}]*$/isu';

        // Strip inline elements (<span>, <time>, <p>, <div>) whose STRIPPED text
        // (tag-stripped) matches entirely. Only remove them when their stripped
        // text matches — leave mixed elements alone. Use a callback that checks
        // the tag-stripped content.
        $tag_re = '/<(span|time|p|div|small|em|strong)\b[^>]*>((?:[^<]|<(?!\1\b)[^>]*>)*?)<\/\1>/isu';
        $html = (string) preg_replace_callback($tag_re, static function (array $m) use ($inner_re): string {
            $inner_text = trim(strip_tags($m[2]));
            if ($inner_text === '') return $m[0]; // empty element — leave
            if (preg_match($inner_re, $inner_text)) {
                return ''; // frozen widget — strip entirely
            }
            return $m[0];
        }, $html);

        return $html;
    }

    /**
     * Prepare a transcribed chrome element (nav or footer) for use in a WP
     * template: strip <script> blocks and inline event handlers, rewrite the
     * logo/asset <img src> to the packaged theme asset when the media
     * importer downloaded that image, and rewrite internal <a href> targets
     * to home_url() PHP calls. Class attributes are left untouched.
     */
    private function rewriteChromeMarkup(string $html): string
    {
        if ($html === '') return '';

        // Drop <script>...</script> blocks entirely.
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        // Strip inline event handlers (onclick=, onmouseover=, etc.).
        $html = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\\s+on[a-z]+\\s*=\\s*'[^']*'/i", '', $html);

        // Rewrite <img src> to the packaged asset when it was downloaded by
        // the media importer (matched by exact URL first, then basename).
        $media_map = $this->analysis['media_map'] ?? [];
        $by_basename = [];
        foreach ($media_map as $orig_url => $local_path) {
            $by_basename[basename((string) $local_path)] = (string) $local_path;
        }
        $html = preg_replace_callback('/(<img\b[^>]*\bsrc=)(["\'])([^"\']+)\2/i', function ($m) use ($media_map, $by_basename) {
            $src   = $m[3];
            $local = $media_map[$src] ?? ($by_basename[basename($src)] ?? '');
            if ($local === '') return $m[0];
            $local_esc = addslashes($local);
            return $m[1] . $m[2] . "<?php echo esc_url(get_template_directory_uri() . '/{$local_esc}'); ?>" . $m[2];
        }, (string) $html);

        // Rewrite internal <a href> targets to home_url(); external, mailto,
        // tel, javascript:, and empty/anchor-only hrefs are left as-is.
        $html = preg_replace_callback('/(<a\b[^>]*\bhref=)(["\'])([^"\']*)\2/i', function ($m) {
            $href = trim($m[3]);
            if ($href === '' || $href === '#') return $m[0];
            if (preg_match('#^(https?://|mailto:|tel:|javascript:)#i', $href)) return $m[0];
            if ($href[0] === '#') $href = '/' . $href;
            $php = $this->navHrefPhp($href);
            return $m[1] . $m[2] . "<?php echo esc_url({$php}); ?>" . $m[2];
        }, (string) $html);

        // v4.3.0 Item 9: strip frozen dynamic widget snapshots (date/time, weather)
        // from chrome elements before they land in the theme template.
        $html = self::stripFrozenDynamicWidgets((string) $html);

        return (string) $html;
    }

    // ─── style.css (WP theme header) ─────────────────────────────────────────

    public function generateStyleCss(): string
    {
        $name = $this->analysis['theme_name'] ?? 'Theme';
        $has_woo = !empty($this->analysis['has_woocommerce']);
        $desc = "Converted from Base44 by base44towordpress.com."
              . ($has_woo ? ' Includes WooCommerce support.' : '');

        return <<<CSS
/*
Theme Name: {$name}
Theme URI: https://base44towordpress.com
Author: Base44 to WordPress
Description: {$desc}
Version: 1.0.0
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
License: GNU General Public License v2 or later
Text Domain: {$this->prefix}
Tags: custom-colors, custom-menu, featured-images
*/
CSS;
    }

    // ─── functions.php ───────────────────────────────────────────────────────

    public function generateFunctions(): string
    {
        $p   = $this->php_prefix;
        $cp  = $this->const_prefix;
        $td  = $this->prefix;
        $c   = $this->analysis['colors'] ?? [];
        $f   = $this->analysis['fonts'] ?? [];
        $has_woo = !empty($this->analysis['has_woocommerce']);
        $sections = $this->analysis['sections'] ?? [];
        $google_url = $f['google_url'] ?? '';

        $primary     = $c['primary'] ?? '#b8952a';
        $secondary   = $c['secondary'] ?? '#d4af52';
        $bg          = $c['background'] ?? '#f0ead6';
        $text_color  = $c['text_primary'] ?? '#1a1a1a';
        $footer_bg   = $c['footer_bg'] ?? '#080f08';
        $heading_font = $f['heading'] ?? 'system-ui, serif';
        $body_font    = $f['body'] ?? 'system-ui, sans-serif';
        $heading_stack = $f['heading_stack'] ?? "'system-ui', serif";
        $body_stack    = $f['body_stack'] ?? "'system-ui', sans-serif";

        // Live-scan ground truth overrides the CSS-bundle heuristics (v2.1.0)
        $live = $this->liveScanDesign();
        if (!empty($live)) {
            $heading_stack = $live['heading_stack'] ?? $heading_stack;
            $body_stack    = $live['body_stack'] ?? $body_stack;
            $bg            = $live['background'] ?? $bg;
            $text_color    = $live['text'] ?? $text_color;
            $primary       = $live['primary'] ?? $primary;
        }
        $google_urls = $live['google_urls'] ?? ($google_url !== '' ? [$google_url] : []);
        $google_urls = array_values(array_filter($google_urls, fn($u) =>
            preg_match('#^https://fonts\.googleapis\.com/#', (string) $u)
        ));
        $fonts_array_php = '[' . implode(', ', array_map(
            fn($u) => "'" . str_replace(["\\", "'"], ['', ''], (string) $u) . "'",
            $google_urls
        )) . ']';

        $woo_block = '';
        if ($has_woo) {
            $woo_block = <<<PHP

// ── WooCommerce ──────────────────────────────────────────────────────────────
define('{$cp}_HAS_WOOCOMMERCE', true);

add_action('after_setup_theme', function () {
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
});

// Clean WooCommerce shop: no default sidebar.
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
PHP;
        }

        // Build section visibility customizer controls
        $section_toggles = '';
        foreach ($sections as $slug) {
            $label = ucwords(str_replace('-', ' ', $slug));
            $section_toggles .= <<<PHP

    \$wp_customize->add_setting('{$p}_show_{$slug}', ['default' => true, 'sanitize_callback' => 'wp_validate_boolean']);
    \$wp_customize->add_control('{$p}_show_{$slug}', [
        'label'   => 'Show {$label} Section',
        'section' => '{$p}_sections',
        'type'    => 'checkbox',
    ]);
PHP;
        }

        return <<<PHP
<?php
/**
 * {$this->analysis['theme_name']} — Theme Functions
 * Generated by Base44 to WordPress (base44towordpress.com)
 *
 * @package {$td}
 */
defined('ABSPATH') || exit;

define('{$cp}_VERSION', '1.0.0');
define('{$cp}_URI', get_template_directory_uri());
define('{$cp}_DIR', get_template_directory());
{$woo_block}

// ── Theme Setup ──────────────────────────────────────────────────────────────
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', ['height' => 80, 'width' => 300, 'flex-height' => true, 'flex-width' => true]);
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
    add_theme_support('customize-selective-refresh-widgets');

    register_nav_menus([
        'primary' => 'Primary Navigation',
        'footer'  => 'Footer Navigation',
    ]);
});

// ── Enqueue Assets ───────────────────────────────────────────────────────────
// v4.3.0 Item 8: theme.css uses a content-hash version string so the ADC/LiteSpeed
// edge cache is busted automatically on every redeploy that changes the CSS.
// md5_file() is called once per request at enqueue time (fast; result cached by
// the opcode cache in production). Falls back to the theme version constant when
// the file is missing (e.g. during unit tests).
add_action('wp_enqueue_scripts', function () {
    \$theme_css_path = {$cp}_DIR . '/assets/css/theme.css';
    \$theme_css_ver  = file_exists(\$theme_css_path) ? substr(md5_file(\$theme_css_path), 0, 8) : {$cp}_VERSION;
    wp_enqueue_style('{$td}-style', get_stylesheet_uri(), [], {$cp}_VERSION);
    wp_enqueue_style('{$td}-theme', {$cp}_URI . '/assets/css/theme.css', [], \$theme_css_ver);
    wp_enqueue_script('{$td}-theme', {$cp}_URI . '/assets/js/theme.js', [], {$cp}_VERSION, true);
});

// ── Google Fonts (ground truth from live-site scan; v2.1.0) ──────────────────
add_action('wp_enqueue_scripts', function () {
    \$fonts = {$fonts_array_php};
    foreach (\$fonts as \$i => \$url) {
        wp_enqueue_style('{$td}-fonts-' . \$i, \$url, [], null);
    }
});
add_action('wp_head', function () {
    \$fonts = {$fonts_array_php};
    if (!empty(\$fonts)) {
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\\n";
    }
}, 4);

// ── CSS Custom Properties via wp_head ────────────────────────────────────────
add_action('wp_head', function () {
    \$primary   = get_theme_mod('{$p}_primary_color', '{$primary}');
    \$secondary = get_theme_mod('{$p}_secondary_color', '{$secondary}');
    \$bg        = get_theme_mod('{$p}_bg_color', '{$bg}');
    \$text      = get_theme_mod('{$p}_text_color', '{$text_color}');
    \$footer_bg = get_theme_mod('{$p}_footer_bg', '{$footer_bg}');
    echo "<style>:root {
    --{$td}-primary: {\$primary};
    --{$td}-secondary: {\$secondary};
    --{$td}-bg: {\$bg};
    --{$td}-text: {\$text};
    --{$td}-footer-bg: {\$footer_bg};
    --{$td}-heading-font: {$heading_stack};
    --{$td}-body-font: {$body_stack};
}</style>\\n";
}, 6);

// ── Customizer ───────────────────────────────────────────────────────────────
add_action('customize_register', function (\$wp_customize) {
    // Colors panel
    \$wp_customize->add_section('{$p}_colors', ['title' => 'Theme Colors', 'priority' => 30]);

    \$wp_customize->add_setting('{$p}_primary_color', ['default' => '{$primary}', 'sanitize_callback' => 'sanitize_hex_color']);
    \$wp_customize->add_control(new WP_Customize_Color_Control(\$wp_customize, '{$p}_primary_color', [
        'label' => 'Primary Accent', 'section' => '{$p}_colors',
    ]));

    \$wp_customize->add_setting('{$p}_secondary_color', ['default' => '{$secondary}', 'sanitize_callback' => 'sanitize_hex_color']);
    \$wp_customize->add_control(new WP_Customize_Color_Control(\$wp_customize, '{$p}_secondary_color', [
        'label' => 'Secondary Accent', 'section' => '{$p}_colors',
    ]));

    \$wp_customize->add_setting('{$p}_bg_color', ['default' => '{$bg}', 'sanitize_callback' => 'sanitize_hex_color']);
    \$wp_customize->add_control(new WP_Customize_Color_Control(\$wp_customize, '{$p}_bg_color', [
        'label' => 'Background', 'section' => '{$p}_colors',
    ]));

    \$wp_customize->add_setting('{$p}_text_color', ['default' => '{$text_color}', 'sanitize_callback' => 'sanitize_hex_color']);
    \$wp_customize->add_control(new WP_Customize_Color_Control(\$wp_customize, '{$p}_text_color', [
        'label' => 'Text Color', 'section' => '{$p}_colors',
    ]));

    \$wp_customize->add_setting('{$p}_footer_bg', ['default' => '{$footer_bg}', 'sanitize_callback' => 'sanitize_hex_color']);
    \$wp_customize->add_control(new WP_Customize_Color_Control(\$wp_customize, '{$p}_footer_bg', [
        'label' => 'Footer Background', 'section' => '{$p}_colors',
    ]));

    // Section visibility
    \$wp_customize->add_section('{$p}_sections', ['title' => 'Section Visibility', 'priority' => 35]);
{$section_toggles}
});

// ── Form Handler ─────────────────────────────────────────────────────────────
add_action('admin_post_nopriv_{$p}_form', '{$p}_handle_form');
add_action('admin_post_{$p}_form', '{$p}_handle_form');

function {$p}_handle_form() {
    if (!isset(\$_POST['{$p}_nonce']) || !wp_verify_nonce(\$_POST['{$p}_nonce'], '{$p}_form_action')) {
        wp_die('Security check failed.');
    }

    \$fields = [];
    foreach (\$_POST as \$key => \$value) {
        if (strpos(\$key, '{$p}_') === 0 && \$key !== '{$p}_nonce') {
            \$fields[sanitize_text_field(\$key)] = sanitize_textarea_field(\$value);
        }
    }

    \$to      = get_option('admin_email');
    \$subject = '[' . get_bloginfo('name') . '] New Form Submission';
    \$body    = "New form submission:\\n\\n";
    foreach (\$fields as \$k => \$v) {
        \$label = ucwords(str_replace(['{$p}_', '_'], ['', ' '], \$k));
        \$body .= "{\$label}: {\$v}\\n";
    }
    \$headers = ['Content-Type: text/plain; charset=UTF-8'];

    wp_mail(\$to, \$subject, \$body, \$headers);

    \$redirect = \$_POST['_wp_http_referer'] ?? home_url('/');
    wp_safe_redirect(\$redirect . '?form=success');
    exit;
}

// ── Required Plugins ─────────────────────────────────────────────────────────
require_once {$cp}_DIR . '/inc/required-plugins.php';

// ── Demo Content Importer ────────────────────────────────────────────────────
if (file_exists({$cp}_DIR . '/inc/demo-content.php')) {
    require_once {$cp}_DIR . '/inc/demo-content.php';
}
PHP;
    }

    // ─── header.php ──────────────────────────────────────────────────────────

    /**
     * Minimal shell header (no visible nav). Retained as the fallback when
     * there is NO scanned nav model. Used by generateHeader() dispatch.
     */
    private function generateHeaderShell(): string
    {
        $td = $this->prefix;
        return <<<PHP
<?php
/**
 * Header — minimal shell (no scanned nav model available).
 * @package {$td}
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class('{$td}-body'); ?>>
<?php wp_body_open(); ?>
<div id="page" class="{$td}-site">
<main id="main" class="{$td}-main">
PHP;
    }

    /**
     * generateHeader() — dispatch. v3.1.0 FAITHFUL-COPY: when a scanned nav
     * model exists, build the header DETERMINISTICALLY from it (logo + verbatim
     * nav + scanned CTA). Otherwise emit the minimal shell. NEVER prints the
     * WP blogname as visible header text unless the source header showed it.
     */
    public function generateHeader(): string
    {
        // v4.2.0 CHROME-FAITHFUL: try transcribing the original scanned nav
        // element verbatim first (fixed/translucent bars, backdrop-blur
        // wrappers, decorative lines, etc. that the deterministic nav model
        // can never reproduce). Falls back to the old nav-model build, then
        // to the minimal shell, when no usable scan HTML is available.
        $chrome = $this->generateHeaderFromScannedChrome();
        if ($chrome !== '') return $chrome;

        $nav = $this->navItems();
        if (empty($nav)) {
            return $this->generateHeaderShell();
        }
        return $this->generateHeaderFromNav($nav);
    }

    /**
     * v4.2.0 CHROME-FAITHFUL: read the home page's scanned rendered HTML
     * (when available) and transcribe the ORIGINAL <nav> element verbatim
     * (or the first element matching class 'fixed top-0' when no <nav> tag
     * exists), rewriting only internal links, the logo image, and stripping
     * scripts/inline handlers. Class attributes are kept verbatim since the
     * bundled CSS (fetched by fix v4.2.0's bundle fetch) covers them.
     * Returns '' when no scan HTML or no nav-like element is found, so the
     * caller falls through to the deterministic nav-model build.
     */
    private function generateHeaderFromScannedChrome(): string
    {
        $home_html = $this->scanHomeHtml();
        if ($home_html === '') return '';

        $nav_html = $this->extractFirstBalanced($home_html, 'nav', 'fixed top-0');
        if ($nav_html === '') return '';

        $nav_html = $this->rewriteChromeMarkup($nav_html);
        $td = $this->prefix;

        return <<<PHP
<?php
/**
 * Header — TRANSCRIBED verbatim from the source site's scanned nav element
 * (v4.2.0 chrome-faithful). Classes kept as-is so the bundled CSS applies
 * unchanged; internal links rewritten to home_url(), the logo image
 * rewritten to the packaged asset, inline handlers and scripts stripped.
 * @package {$td}
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class('{$td}-body'); ?>>
<?php wp_body_open(); ?>
<div id="page" class="{$td}-site">
{$nav_html}
<main id="main" class="{$td}-main">
PHP;
    }

    /**
     * Build a faithful header.php from the scanned nav model. The visible nav
     * uses wp_nav_menu(primary) with a fallback that renders EXACTLY the
     * scanned labels/targets verbatim. The logo is the scanned site logo
     * (bundled asset) wrapped in the home link. No blogname text leaks in.
     */
    public function generateHeaderFromNav(array $nav): string
    {
        $td   = $this->prefix;
        $logo = $this->siteLogo();

        // Scanned site title — hardcoded into the generated PHP so the header
        // never depends on the WP blogname option (which may carry a stale
        // value from a previous theme until demo-content.php fires).
        $scan_title_html = htmlspecialchars(
            trim((string) ($this->analysis['live_scan']['site_title'] ?? '')) ?: ($this->analysis['theme_name'] ?? 'Site'),
            ENT_QUOTES
        );

        // Logo HTML (PHP). Prefer the custom logo if one is set; otherwise the
        // scanned bundled logo image; only if neither exists fall back to text.
        if (!empty($logo['local'])) {
            $logo_path = addslashes($logo['local']);
            $logo_alt  = htmlspecialchars($logo['alt'], ENT_QUOTES);
            $logo_html = <<<PHP
            <?php if (has_custom_logo()) : the_custom_logo(); else : ?>
                <a class="{$td}-logo" href="<?php echo esc_url(home_url('/')); ?>" rel="home">
                    <img src="<?php echo esc_url(get_template_directory_uri() . '/{$logo_path}'); ?>" alt="{$logo_alt}" class="{$td}-logo__img">
                </a>
            <?php endif; ?>
PHP;
        } else {
            // No scanned logo image — hardcode the scanned site title as link
            // text. Never use bloginfo('name'): it reflects the WP DB option
            // which may carry a stale value from a prior theme.
            $logo_html = <<<PHP
            <?php if (has_custom_logo()) : the_custom_logo(); else : ?>
                <a class="{$td}-logo" href="<?php echo esc_url(home_url('/')); ?>" rel="home">{$scan_title_html}</a>
            <?php endif; ?>
PHP;
        }

        // Fallback menu markup (verbatim scanned labels + resolved targets).
        // wp_nav_menu uses this when no 'primary' menu is assigned, and it is
        // also the guaranteed faithful default. CTA-styled items get a button
        // class but the TEXT is always the verbatim scanned label.
        $cta_labels = [];
        foreach (($this->analysis['live_scan']['header_ctas'] ?? []) as $c) {
            $t = trim((string) ($c['text'] ?? ''));
            if ($t !== '') $cta_labels[strtolower($t)] = true;
        }

        $items_html = '';
        foreach ($nav as $item) {
            $label   = htmlspecialchars($item['label'], ENT_QUOTES);
            $href_php = $this->navHrefPhp($item['href']);
            $is_cta  = isset($cta_labels[strtolower($item['label'])]);
            $li_class = $is_cta ? "{$td}-nav__item {$td}-nav__item--cta" : "{$td}-nav__item";
            $a_class  = $is_cta ? "{$td}-nav__link {$td}-nav__cta" : "{$td}-nav__link";
            $items_html .= "                <li class=\"{$li_class}\"><a class=\"{$a_class}\" href=\"<?php echo esc_url({$href_php}); ?>\">{$label}</a></li>\n";
        }

        return <<<PHP
<?php
/**
 * Header — FAITHFUL reproduction of the source site header.
 * Logo + verbatim scanned nav + scanned CTA. No invented items, no blogname
 * text. Built deterministically from the scanned nav model (v3.1.0).
 * @package {$td}
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class('{$td}-body'); ?>>
<?php wp_body_open(); ?>
<div id="page" class="{$td}-site">
<header class="{$td}-header" role="banner">
    <div class="{$td}-header__inner">
        <div class="{$td}-header__brand">
{$logo_html}
        </div>
        <nav class="{$td}-nav" aria-label="<?php esc_attr_e('Primary'); ?>">
            <?php
            if (has_nav_menu('primary')) {
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container'      => false,
                    'menu_class'     => '{$td}-nav__menu',
                    'fallback_cb'    => false,
                ]);
            } else {
            ?>
            <ul class="{$td}-nav__menu">
{$items_html}            </ul>
            <?php } ?>
        </nav>
        <button class="{$td}-nav__toggle" aria-label="<?php esc_attr_e('Menu'); ?>" aria-expanded="false">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="12" y2="12"></line><line x1="4" x2="20" y1="6" y2="6"></line><line x1="4" x2="20" y1="18" y2="18"></line></svg>
        </button>
    </div>
    <ul class="{$td}-nav__mobile" hidden>
{$items_html}    </ul>
</header>
<main id="main" class="{$td}-main">
PHP;
    }

    // ─── footer.php ──────────────────────────────────────────────────────────

    public function generateFooter(): string
    {
        // v4.2.0 CHROME-FAITHFUL: try transcribing the original scanned
        // footer element verbatim first; fall back to the generic template
        // when no scan HTML or no <footer> element is found.
        $chrome = $this->generateFooterFromScannedChrome();
        if ($chrome !== '') return $chrome;
        return $this->generateFooterGeneric();
    }

    /**
     * v4.2.0 CHROME-FAITHFUL: transcribe the LAST <footer> element from the
     * home page's scanned HTML verbatim (the last one is the real site
     * footer; content areas rarely nest a <footer> but if they do, the last
     * one in DOM order is still the outermost site chrome on every site seen
     * so far). Rewrites internal links + logo image, strips scripts/inline
     * handlers, keeps classes verbatim. Returns '' when unavailable.
     */
    private function generateFooterFromScannedChrome(): string
    {
        $home_html = $this->scanHomeHtml();
        if ($home_html === '') return '';

        $footer_html = $this->extractLastBalanced($home_html, 'footer');
        if ($footer_html === '') return '';

        $footer_html = $this->rewriteChromeMarkup($footer_html);
        $td = $this->prefix;

        return <<<PHP
<?php
/**
 * Footer — TRANSCRIBED verbatim from the source site's scanned footer
 * element (v4.2.0 chrome-faithful). Classes kept as-is so the bundled CSS
 * applies unchanged; internal links rewritten to home_url(), the logo image
 * rewritten to the packaged asset, inline handlers and scripts stripped.
 * @package {$td}
 */
?>
</main><!-- #main -->
</div><!-- #page -->

{$footer_html}

<?php wp_footer(); ?>
</body>
</html>
PHP;
    }

    /**
     * Generic footer template (pre-v4.2.0 behaviour). Used when no scanned
     * chrome is available.
     */
    private function generateFooterGeneric(): string
    {
        $td = $this->prefix;
        $c  = $this->analysis['colors'] ?? [];
        $footer_bg = $c['footer_bg'] ?? '#080f08';
        $footer_content = $this->analysis['section_content']['footer'] ?? [];
        $name = $this->analysis['theme_name'] ?? 'Site';
        // Hardcoded site title — same rationale as generateHeaderFromNav: never
        // use bloginfo('name') because the WP option may be stale.
        $scan_title_html = htmlspecialchars(
            trim((string) ($this->analysis['live_scan']['site_title'] ?? '')) ?: $name,
            ENT_QUOTES
        );
        $nav = $this->navItems(); // v2.2.0: scanned original nav, not the page list

        $nav_links = '';
        foreach ($nav as $item) {
            $label = htmlspecialchars($item['label']);
            $href  = $item['href'];
            $url_php = preg_match('#^https?://#i', $href)
                ? "'" . addslashes($href) . "'"
                : "home_url('" . addslashes($href) . "')";
            $nav_links .= "            <li><a href=\"<?php echo esc_url({$url_php}); ?>\">{$label}</a></li>\n";
        }

        return <<<PHP
<?php
/**
 * Footer
 * @package {$td}
 */
?>
</main><!-- #main -->
</div><!-- #page -->

<footer class="{$td}-footer" style="background-color: var(--{$td}-footer-bg, {$footer_bg}); color: #ccc; padding: 60px 0 30px;">
    <div class="{$td}-footer__inner" style="max-width: 1200px; margin: 0 auto; padding: 0 24px;">
        <div class="{$td}-footer__top" style="display: flex; flex-wrap: wrap; gap: 40px; justify-content: space-between; margin-bottom: 40px;">
            <div class="{$td}-footer__brand">
                <?php if (has_custom_logo()) : ?>
                    <?php the_custom_logo(); ?>
                <?php else : ?>
                    <span style="font-family: var(--{$td}-heading-font); font-size: 24px; color: #fff; font-weight: 600;">
                        {$scan_title_html}
                    </span>
                <?php endif; ?>
            </div>
            <nav class="{$td}-footer__nav">
                <ul style="list-style: none; padding: 0; margin: 0; display: flex; gap: 24px; flex-wrap: wrap;">
{$nav_links}
                </ul>
            </nav>
        </div>
        <div class="{$td}-footer__bottom" style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 20px; text-align: center; font-size: 13px; opacity: 0.7;">
            <p>&copy; <?php echo date('Y'); ?> {$scan_title_html}. All rights reserved.</p>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
PHP;
    }

    // ─── front-page.php ──────────────────────────────────────────────────────

    public function generateFrontPage(): string
    {
        $td = $this->prefix;
        $p  = $this->php_prefix;
        $sections = $this->analysis['sections'] ?? [];

        // When no sections were extracted, fall back to rendering the WP page content
        // (populated by demo import via wp_insert_post with scraped HTML)
        if (empty($sections)) {
            return <<<FRONTPAGE
<?php
/**
 * Front Page — no section templates extracted; renders WP page content.
 * @package {$td}
 */
get_header();
?>
<div id="{$p}-content" class="{$p}-page-content">
    <div class="{$p}-container">
        <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
            <div class="{$p}-entry"><?php the_content(); ?></div>
        <?php endwhile; endif; ?>
    </div>
</div>
<?php get_footer(); ?>
FRONTPAGE;
        }

        $includes = '';
        foreach ($sections as $slug) {
            if ($slug === 'footer') continue; // footer.php handles this
            // v3.1.0 FAITHFUL-COPY: the header/nav is now reproduced
            // deterministically in header.php. Section parts that merely
            // duplicate the site chrome (navbar / mobile-nav) or are non-visual
            // SEO/auth artifacts (seo-snapshot) must NOT render on the page —
            // rendering them double-prints the nav and leaks invented auth copy.
            if (self::isChromeOrJunkSection($slug)) continue;
            // Skip prefix-stub sections: if the section list contains both
            // 'hero' and 'hero-section', the shorter 'hero' is a thin AI-
            // generated structural wrapper, not a real visual section. Any slug
            // that is a strict prefix (slug + '-') of another slug in the list
            // is treated as its stub and excluded from the front page.
            $slug_prefix = $slug . '-';
            $is_prefix_stub = false;
            foreach ($sections as $_s) {
                if ($_s !== $slug && strpos($_s, $slug_prefix) === 0) {
                    $is_prefix_stub = true;
                    break;
                }
            }
            if ($is_prefix_stub) continue;
            $includes .= <<<PHP

<?php if (get_theme_mod('{$p}_show_{$slug}', true)) : ?>
    <?php get_template_part('template-parts/{$slug}'); ?>
<?php endif; ?>

PHP;
        }

        return <<<PHP
<?php
/**
 * Front Page — includes section template parts.
 * @package {$td}
 */
get_header();
?>
{$includes}
<?php get_footer(); ?>
PHP;
    }

    // ─── page-{slug}.php loader (v3.0.0) ─────────────────────────────────────

    /**
     * Deterministic page template loader: get_header() + ordered
     * get_template_part() calls into template-parts/pages/{slug}/ + get_footer().
     * NEVER AI-generated — the section files carry all converted markup.
     */
    public function generatePageLoader(string $slug, string $title, array $sectionSlugs): string
    {
        $td   = $this->prefix;
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug)) ?: 'page';
        $safe_title = trim(str_replace(['*', '/', "\r", "\n"], ' ', $title));
        if ($safe_title === '') $safe_title = ucwords(str_replace('-', ' ', $slug));

        $parts = '';
        foreach ($sectionSlugs as $s) {
            $s = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $s));
            if ($s === '') continue;
            $parts .= "    <?php get_template_part('template-parts/pages/{$slug}/{$s}'); ?>\n";
        }

        return <<<PHP
<?php
/*
Template Name: {$safe_title}
*/
/**
 * Page template for '{$slug}' — deterministic loader (Builder v3.0.0).
 * Converted sections live in template-parts/pages/{$slug}/.
 * @package {$td}
 */
get_header();
?>
<div id="page-{$slug}" class="{$td}-page {$td}-page--{$slug}">
{$parts}</div>
<?php get_footer(); ?>
PHP;
    }

    // ─── front-page.php from validated HTML ─────────────────────────────────

    /**
     * Generate front-page.php by inlining pre-validated static HTML.
     * Defense-in-depth: strips any stray PHP tags before inlining.
     * Output: <?php get_header(); ?> + body HTML + <?php get_footer(); ?>
     */
    public function generateFrontPageFromHtml(string $validatedBodyHtml): string
    {
        $td = $this->prefix;
        $p  = $this->php_prefix;

        // Defense-in-depth: strip any stray PHP tags from the HTML
        $bodyHtml = str_replace(['<?php', '?>'], '', $validatedBodyHtml);
        // Expand %THEMEURI% token → PHP echo (token carries no PHP tags so the
        // stray-tag strip above leaves it intact; this legitimately reintroduces
        // get_template_directory_uri() for image URLs in the PHP template context).
        $bodyHtml = str_replace('%THEMEURI%', '<?php echo get_template_directory_uri(); ?>', $bodyHtml);

        return <<<PHP
<?php
/**
 * Front Page — inlined HTML content.
 * @package {$td}
 */
get_header();
?>
{$bodyHtml}
<?php get_footer(); ?>
PHP;
    }

    // ─── page-{slug}.php from validated HTML ────────────────────────────────

    /**
     * Generate page-{slug}.php by inlining pre-validated static HTML.
     * Wraps with Template Name docblock and get_header()/get_footer() calls.
     * Defense-in-depth: strips any stray PHP tags before inlining.
     * Output: Template docblock + <?php get_header(); ?> + body HTML + <?php get_footer(); ?>
     */
    public function generatePageFromHtml(string $slug, string $title, string $validatedBodyHtml): string
    {
        $td = $this->prefix;
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower($slug)) ?: 'page';
        $safe_title = trim(str_replace(['*', '/', "\r", "\n"], ' ', $title));
        if ($safe_title === '') $safe_title = ucwords(str_replace('-', ' ', $slug));

        // Defense-in-depth: strip any stray PHP tags from the HTML
        $bodyHtml = str_replace(['<?php', '?>'], '', $validatedBodyHtml);
        // Expand %THEMEURI% token → PHP echo (token carries no PHP tags so the
        // stray-tag strip above leaves it intact; this legitimately reintroduces
        // get_template_directory_uri() for image URLs in the PHP template context).
        $bodyHtml = str_replace('%THEMEURI%', '<?php echo get_template_directory_uri(); ?>', $bodyHtml);

        return <<<PHP
<?php
/*
Template Name: {$safe_title}
*/
/**
 * Page template for '{$slug}' — inlined HTML content.
 * @package {$td}
 */
get_header();
?>
{$bodyHtml}
<?php get_footer(); ?>
PHP;
    }

    // ─── theme.css ───────────────────────────────────────────────────────────

    public function generateThemeCss(): string
    {
        $td = $this->prefix;
        $c  = $this->analysis['colors'] ?? [];
        $f  = $this->analysis['fonts'] ?? [];
        $css_bundle = $this->analysis['css_bundle'] ?? ($c['css_variables']['__raw_css'] ?? '');

        $primary   = $c['primary'] ?? '#b8952a';
        $secondary = $c['secondary'] ?? '#d4af52';
        $bg        = $c['background'] ?? '#f0ead6';
        $text      = $c['text_primary'] ?? '#1a1a1a';
        $footer_bg = $c['footer_bg'] ?? '#080f08';

        $heading_stack = $f['heading_stack'] ?? "'Cormorant Garamond', Georgia, serif";
        $body_stack    = $f['body_stack'] ?? "'Inter', system-ui, sans-serif";

        // Live-scan ground truth overrides the CSS-bundle heuristics (v2.1.0)
        $live = $this->liveScanDesign();
        if (!empty($live)) {
            $heading_stack = $live['heading_stack'] ?? $heading_stack;
            $body_stack    = $live['body_stack'] ?? $body_stack;
            $bg            = $live['background'] ?? $bg;
            $text          = $live['text'] ?? $text;
            $primary       = $live['primary'] ?? $primary;
        }

        // Exact heading typography measured on the live site
        $ground_truth_css = '';
        if (!empty($live['h1']) || !empty($live['h2'])) {
            $ground_truth_css = "\n/* ── Ground truth typography (measured on live site via Playwright) ── */\n";
            foreach (['h1', 'h2'] as $h) {
                if (empty($live[$h])) continue;
                $decls = [];
                foreach ($live[$h] as $prop => $val) {
                    $decls[] = "    {$prop}: {$val};";
                }
                $ground_truth_css .= "{$h} {\n" . implode("\n", $decls) . "\n}\n";
            }
        }

        // Base theme CSS
        $theme_css = <<<CSS
/* Theme CSS — {$this->analysis['theme_name']} */
/* Generated by Base44 to WordPress */

/* ── Reset & Base ──────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body.{$td}-body {
    font-family: var(--{$td}-body-font, {$body_stack});
    color: var(--{$td}-text, {$text});
    background-color: var(--{$td}-bg, {$bg});
    line-height: 1.6;
    -webkit-font-smoothing: antialiased;
}

h1, h2, h3, h4, h5, h6 {
    font-family: var(--{$td}-heading-font, {$heading_stack});
    line-height: 1.2;
    font-weight: 600;
}

a { color: var(--{$td}-primary, {$primary}); text-decoration: none; transition: color 0.3s ease; }
a:hover { color: var(--{$td}-secondary, {$secondary}); }

img { max-width: 100%; height: auto; display: block; }

/* ── Layout ────────────────────────────────────────────────────────── */
.{$td}-main { width: 100%; }
.{$td}-container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
.{$td}-section { padding: 80px 0; }
.{$td}-section--dark { background-color: var(--{$td}-footer-bg, {$footer_bg}); color: #f0ead6; }

/* ── Buttons ───────────────────────────────────────────────────────── */
.{$td}-btn {
    display: inline-block;
    font-family: var(--{$td}-body-font, {$body_stack});
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    padding: 16px 36px;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
    border: none;
}

.{$td}-btn--primary {
    background: linear-gradient(135deg, var(--{$td}-primary, {$primary}), var(--{$td}-secondary, {$secondary}));
    color: #fff;
}
.{$td}-btn--primary:hover { opacity: 0.85; color: #fff; }

.{$td}-btn--outline {
    background: transparent;
    color: var(--{$td}-text, {$text});
    border: 1px solid rgba(0,0,0,0.2);
}
.{$td}-btn--outline:hover { border-color: var(--{$td}-primary, {$primary}); color: var(--{$td}-primary, {$primary}); }

/* ── Section Label (Art Deco decorative element) ───────────────────── */
.{$td}-section-label {
    text-align: center;
    font-family: var(--{$td}-body-font);
    font-size: 10px;
    letter-spacing: 0.3em;
    text-transform: uppercase;
    color: var(--{$td}-primary, {$primary});
    margin-bottom: 16px;
}

.{$td}-deco-divider {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    margin-bottom: 24px;
}
.{$td}-deco-divider::before,
.{$td}-deco-divider::after {
    content: '';
    width: 60px;
    height: 1px;
    background: var(--{$td}-primary, {$primary});
    opacity: 0.4;
}
.{$td}-deco-diamond {
    width: 8px;
    height: 8px;
    background: var(--{$td}-primary, {$primary});
    transform: rotate(45deg);
    opacity: 0.6;
}

/* ── Footer ────────────────────────────────────────────────────────── */
.{$td}-footer a { color: rgba(255,255,255,0.7); }
.{$td}-footer a:hover { color: var(--{$td}-primary, {$primary}); }

/* ── Responsive ────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .{$td}-section { padding: 48px 0; }
    h1 { font-size: clamp(32px, 6vw, 52px); }
    h2 { font-size: clamp(24px, 4vw, 36px); }
    .{$td}-footer__top { flex-direction: column; align-items: center; text-align: center; }
    .{$td}-footer__nav ul { flex-direction: column; align-items: center; }
}

/* ── Form Styles ───────────────────────────────────────────────────── */
.{$td}-form { max-width: 600px; margin: 0 auto; }
.{$td}-form__field {
    width: 100%;
    padding: 14px 18px;
    margin-bottom: 16px;
    border: 1px solid rgba(0,0,0,0.15);
    background: rgba(255,255,255,0.8);
    font-family: var(--{$td}-body-font);
    font-size: 14px;
    transition: border-color 0.3s;
}
.{$td}-form__field:focus {
    outline: none;
    border-color: var(--{$td}-primary, {$primary});
}
.{$td}-form__textarea { min-height: 120px; resize: vertical; }
.{$td}-form__success {
    background: rgba(93,191,110,0.1);
    border: 1px solid #5dbf6e;
    padding: 16px;
    text-align: center;
    margin-bottom: 24px;
}

/* ── Faithful header (v3.1.0) ──────────────────────────────────────── */
.{$td}-header { position: fixed; top: 0; left: 0; right: 0; z-index: 50; }
.{$td}-header__inner {
    max-width: 1280px; margin: 0 auto; padding: 14px 32px;
    display: flex; align-items: center; justify-content: space-between; gap: 28px;
}
.{$td}-header__brand { flex-shrink: 0; }
.{$td}-logo__img { height: 56px; width: auto; display: block; }
.{$td}-nav__menu {
    list-style: none; margin: 0; padding: 0;
    display: flex; align-items: center; gap: 28px;
}
.{$td}-nav__item { list-style: none; }
.{$td}-nav__link { text-decoration: none; display: inline-block; }
.{$td}-nav__item--cta .{$td}-nav__cta {
    padding: 8px 20px; border-radius: 2px; font-weight: 600;
}
.{$td}-nav__toggle { display: none; background: none; border: 0; cursor: pointer; color: inherit; }
.{$td}-nav__mobile { list-style: none; margin: 0; padding: 16px 32px; }
.{$td}-nav__mobile .{$td}-nav__link { display: block; padding: 10px 0; }
@media (max-width: 1023px) {
    .{$td}-nav { display: none; }
    .{$td}-nav__toggle { display: inline-flex; }
    .{$td}-nav__mobile[hidden] { display: none; }
    .{$td}-nav__mobile:not([hidden]) { display: block; }
}

CSS;

        // ── v2.2.0 layout guards ─────────────────────────────────────────────
        // Nav spacing: uppercase nav links with letter-spacing collide when the
        // item gap is smaller than the trailing letter-space (letter-spacing
        // also renders after the LAST glyph). Stats contrast: stat/counter
        // sections take the scanned dark section treatment (original shows
        // stats on dark) and are never left invisible by reveal animations.
        $dark  = (self::hexLuma($text) < 0.5) ? $text : '#111111';
        $light = (self::hexLuma($bg) >= 0.5) ? $bg : '#ffffff';
        $guard_css = <<<CSS

/* ── Nav spacing guard (v2.2.0) ────────────────────────────────────── */
header nav,
header nav ul,
.{$td}-nav,
.{$td}-nav ul {
    column-gap: 1.5em !important;
}
header nav li { list-style: none; }
header nav li > a { display: inline-block; padding: 0.5rem 0.25rem; }

/* ── Stats section visibility guard (v3.1.1, faithful) ─────────────────
   The pre-3.1.1 guard FORCED a dark background + light text on every
   stats/counter section, on the assumption the source always renders stats
   on dark. That is anti-faithful: when the source stats band uses its own
   palette (e.g. a gold band with dark text), the forced dark !important
   override defeated the source's inline background AND made dark-on-dark
   labels invisible. In faithful-copy mode the section already carries the
   correct transcribed background/text colors inline, so we NO LONGER force
   any color. We keep ONLY the reveal-animation safety so stat items are
   never left invisible (opacity:0 / off-screen transform) when JS is off. */
section[class*="stats"] [data-delay],
section[class*="counter"] [data-delay],
.{$td}-stats [data-delay] {
    opacity: 1 !important;
    transform: none !important;
}
CSS;

        return $theme_css . $ground_truth_css . $guard_css;
    }

    // ─── theme.js ────────────────────────────────────────────────────────────

    public function generateThemeJs(): string
    {
        $td = $this->prefix;

        return <<<JS
/**
 * Theme JS — {$this->analysis['theme_name']}
 */
document.addEventListener('DOMContentLoaded', function () {

    // ── Mobile menu toggle ───────────────────────────────────────────────
    var toggle = document.querySelector('.{$td}-nav__toggle, .nav-mobile-btn');
    var mobileMenu = document.querySelector('.{$td}-nav__mobile, .nav-mobile-menu');

    if (toggle && mobileMenu) {
        toggle.addEventListener('click', function () {
            var isOpen = mobileMenu.style.display === 'block' || mobileMenu.classList.contains('is-open');
            mobileMenu.style.display = isOpen ? 'none' : 'block';
            mobileMenu.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', !isOpen);
        });
    }

    // ── Smooth scroll for anchor links ───────────────────────────────────
    document.querySelectorAll('a[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                // Close mobile menu if open
                if (mobileMenu) {
                    mobileMenu.style.display = 'none';
                    mobileMenu.classList.remove('is-open');
                }
            }
        });
    });

    // ── Simple carousel (CSS scroll-snap based) ──────────────────────────
    document.querySelectorAll('.{$td}-carousel').forEach(function (carousel) {
        var track = carousel.querySelector('.{$td}-carousel__track');
        var prev  = carousel.querySelector('.{$td}-carousel__prev');
        var next  = carousel.querySelector('.{$td}-carousel__next');

        if (track && next) {
            var scrollAmount = track.offsetWidth * 0.8;
            next.addEventListener('click', function () {
                track.scrollBy({ left: scrollAmount, behavior: 'smooth' });
            });
        }
        if (track && prev) {
            var scrollAmount = track.offsetWidth * 0.8;
            prev.addEventListener('click', function () {
                track.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
            });
        }
    });

    // ── Hover effects for buttons (replicating Base44 inline handlers) ───
    document.querySelectorAll('.{$td}-btn--primary').forEach(function (btn) {
        btn.addEventListener('mouseenter', function () { this.style.opacity = '0.85'; });
        btn.addEventListener('mouseleave', function () { this.style.opacity = '1'; });
    });
});
JS;
    }

    // ─── Skeleton templates ──────────────────────────────────────────────────

    public function generateSkeletonFiles(): array
    {
        $td = $this->prefix;
        $cp = $this->const_prefix;
        $name = $this->analysis['theme_name'] ?? 'Theme';

        $files = [];

        // index.php
        $files['index.php'] = <<<PHP
<?php get_header(); ?>
<div class="{$td}-container" style="padding: 60px 24px;">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <div class="entry-content"><?php the_excerpt(); ?></div>
            </article>
        <?php endwhile; ?>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p>No content found.</p>
    <?php endif; ?>
</div>
<?php get_footer(); ?>
PHP;

        // page.php
        $files['page.php'] = <<<PHP
<?php get_header(); ?>
<div class="{$td}-container" style="padding: 60px 24px; max-width: 800px;">
    <?php while (have_posts()) : the_post(); ?>
        <h1 style="font-family: var(--{$td}-heading-font); margin-bottom: 24px;"><?php the_title(); ?></h1>
        <div class="entry-content"><?php the_content(); ?></div>
    <?php endwhile; ?>
</div>
<?php get_footer(); ?>
PHP;

        // single.php
        $files['single.php'] = <<<PHP
<?php get_header(); ?>
<div class="{$td}-container" style="padding: 60px 24px; max-width: 800px;">
    <?php while (have_posts()) : the_post(); ?>
        <?php if (has_post_thumbnail()) : ?>
            <div style="margin-bottom: 32px;"><?php the_post_thumbnail('large'); ?></div>
        <?php endif; ?>
        <h1 style="font-family: var(--{$td}-heading-font); margin-bottom: 12px;"><?php the_title(); ?></h1>
        <div style="font-size: 14px; color: #888; margin-bottom: 32px;">
            <?php echo get_the_date(); ?> — <?php the_author(); ?>
        </div>
        <div class="entry-content"><?php the_content(); ?></div>
    <?php endwhile; ?>
</div>
<?php get_footer(); ?>
PHP;

        // 404.php
        $files['404.php'] = <<<PHP
<?php get_header(); ?>
<div class="{$td}-container" style="text-align: center; padding: 100px 24px;">
    <h1 style="font-size: 6rem; opacity: 0.15; margin: 0;">404</h1>
    <h2>Page Not Found</h2>
    <p>Sorry, we couldn't find the page you're looking for.</p>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="{$td}-btn {$td}-btn--primary" style="margin-top: 24px;">Back to Home</a>
</div>
<?php get_footer(); ?>
PHP;

        // woocommerce.php — full-width wrapper for all WooCommerce pages (shop,
        // category, single product). WC uses this file when present, giving full
        // control of the surrounding markup. No sidebar. woocommerce_content()
        // outputs the correct template for the current WC page type.
        $files['woocommerce.php'] = <<<PHP
<?php
/**
 * WooCommerce wrapper — full-width, no sidebar.
 *
 * WooCommerce uses woocommerce.php (when present in the theme root) as the
 * wrapper for ALL WooCommerce pages: shop, product category, single product.
 * get_header() / get_footer() supply the theme chrome; woocommerce_content()
 * outputs the correct WC template part for the current page type.
 *
 * @package {$td}
 */
get_header();
?>
<main class="{$td}-wc-main" style="max-width:1200px;margin:0 auto;padding:40px 20px;">
    <?php woocommerce_content(); ?>
</main>
<?php
get_footer();
PHP;

        return $files;
    }

    // ─── inc/required-plugins.php (from skeleton) ────────────────────────────

    public function generateRequiredPlugins(): string
    {
        $skeleton_path = dirname(__DIR__, 2) . '/wp-skeleton/inc-required-plugins.php.tpl';
        if (file_exists($skeleton_path)) {
            $content = file_get_contents($skeleton_path);
            return str_replace(
                ['{PREFIX}', '{CONST_PREFIX}', '{THEME_NAME}', '{TEXT_DOMAIN}'],
                [$this->php_prefix, $this->const_prefix, $this->analysis['theme_name'] ?? 'Theme', $this->prefix],
                $content
            );
        }

        // Inline fallback
        $p = $this->php_prefix;
        $cp = $this->const_prefix;
        return <<<PHP
<?php
defined('ABSPATH') || exit;
add_action('admin_notices', function () {
    \$missing = [];
    if (!class_exists('ACF')) \$missing[] = 'Advanced Custom Fields';
    if (defined('{$cp}_HAS_WOOCOMMERCE') && {$cp}_HAS_WOOCOMMERCE && !class_exists('WooCommerce'))
        \$missing[] = 'WooCommerce';
    if (empty(\$missing)) return;
    echo '<div class="notice notice-warning"><p><strong>' . esc_html(wp_get_theme()->get('Name'))
       . '</strong> recommends: ' . esc_html(implode(', ', \$missing))
       . '. <a href="' . esc_url(admin_url('plugin-install.php')) . '">Install Plugins</a></p></div>';
});
PHP;
    }

    // ─── inc/demo-content.php ────────────────────────────────────────────────

    public function generateDemoContent(): string
    {
        $p    = $this->php_prefix;
        $cp   = $this->const_prefix;
        $td   = $this->prefix;
        $name = $this->analysis['theme_name'] ?? 'Theme';
        // v4.3.0 Item 5: prefer the visible brand text from the scanned header/logo
        // area (live_scan.site_title is captured from the rendered DOM by the scanner,
        // so it reflects the actual wordmark, not the Base44 app-store document.title).
        // Only fall back to theme_name when no scan title is present.
        $scan_site_title = trim((string) ($this->analysis['live_scan']['site_title'] ?? ''));
        if ($scan_site_title !== '') {
            $name = $scan_site_title;
        }
        $pages   = $this->analysis['pages'] ?? [];
        $nav     = $this->navItems();
        $images   = $this->analysis['images'] ?? [];

        // Build page creation code
        $page_inserts = '';
        foreach ($pages as $page) {
            if ($page['slug'] === 'home') continue;
            if (self::isSkippableSlug($page['slug'])) continue;
            $title   = addslashes($page['title']);
            $slug    = $page['slug'];
            $raw     = $this->page_contents[$slug]['content'] ?? '';
            // Guard: raw React/JSX source leaked into content — treat as empty
            if (self::isJsxSource($raw)) {
                $raw = '';
            }
            $content = addslashes(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw));
            $page_inserts .= <<<PHP

    if (!get_page_by_path('{$slug}')) {
        wp_insert_post([
            'post_title'   => '{$title}',
            'post_name'    => '{$slug}',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => str_replace('%THEMEURI%', get_template_directory_uri(), '{$content}'),
        ]);
    }
PHP;
        }

        // Build home page content from analysis
        $home_raw   = $this->page_contents['home']['content'] ?? '';
        if (self::isJsxSource($home_raw)) {
            $home_raw = '';
        }
        if (empty($home_raw)) {
            $site_desc  = strip_tags($this->analysis['description'] ?? '');
            $home_raw   = $site_desc ? '<p>' . htmlspecialchars($site_desc) . '</p>' : '';
            if (!empty($this->analysis['has_woocommerce'])) {
                $home_raw .= '[products limit="12" columns="3"]';
            }
        }
        $home_content = addslashes(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $home_raw));

        // Build nav items code STRICTLY from the faithful scanned nav model
        // (v3.1.0). Verbatim labels, kind-aware targets. The full nav is
        // emitted in DOM order — including the first item — so nothing is
        // dropped or renamed. No page-list fallback.
        $nav_items_code = '';
        foreach ($nav as $item) {
            $label = addslashes($item['label']);
            $url   = trim($item['href']);
            if ($url === '') $url = '#';
            $url_expr = (preg_match('#^(https?://|mailto:|tel:)#i', $url))
                ? addslashes($url)
                : "' . home_url('" . addslashes($url) . "') . '";
            $nav_items_code .= <<<PHP

        wp_update_nav_menu_item(\$menu_id, 0, [
            'menu-item-title'  => '{$label}',
            'menu-item-url'    => '{$url_expr}',
            'menu-item-status' => 'publish',
            'menu-item-type'   => 'custom',
        ]);
PHP;
        }

        // v4.3.0 Item 5 (supersedes v4.2.4): blogname is set from the visible brand
        // text in the scanned header/logo area (live_scan.site_title, captured from
        // the rendered DOM by the Playwright scanner), falling back to the
        // human-passed theme_name only when no scan title is present. Both are
        // already resolved above into $name. blogdescription is always empty —
        // never sourced from the meta description tag (which carries app-store copy,
        // not a real site tagline).
        $blogname_php = "    update_option('blogname', '" . addslashes((string) $name) . "');\n";
        $blogname_php .= "    update_option('blogdescription', '');\n";

        // v2.3.0: products are no longer emitted as hardcoded WC_Product_Simple
        // blocks. demo-content.php now reads inc/product-import.csv at runtime
        // (single source of truth — same junk-filtered, real-price CSV used for
        // manual WooCommerce CSV import). See the runtime block in the template.

        // Build image import code
        $image_imports = '';
        foreach (array_slice($images, 0, 500) as $img) {
            $fn = addslashes($img['filename'] ?? '');
            if (empty($fn)) continue;
            $image_imports .= <<<PHP

    {$p}_import_theme_image('{$fn}');
PHP;
        }

        return <<<PHP
<?php
/**
 * Demo Content Importer — creates pages, nav menu, products, and imports media.
 * @package {$td}
 */
defined('ABSPATH') || exit;

// ── Auto-import on theme activation (runs via WP-CLI wp theme activate) ────────
add_action('after_switch_theme', function () {
    if (get_option('{$p}_demo_imported')) return;
    {$p}_run_import();
    update_option('{$p}_demo_imported', true);
});

add_action('admin_menu', function () {
    add_theme_page(
        'Import Demo Content',
        'Import Demo Content',
        'manage_options',
        '{$p}_demo_import',
        '{$p}_demo_import_page'
    );
});

function {$p}_demo_import_page() {
    if (isset(\$_POST['{$p}_import_nonce']) && wp_verify_nonce(\$_POST['{$p}_import_nonce'], '{$p}_demo_import')) {
        {$p}_run_import();
        echo '<div class="notice notice-success"><p>Demo content imported successfully!</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Import Demo Content</h1>
        <p>This will create pages, navigation menu, import images to the media library, and seed sample products.</p>
        <form method="post">
            <?php wp_nonce_field('{$p}_demo_import', '{$p}_import_nonce'); ?>
            <p><input type="submit" class="button button-primary" value="Import Demo Content"></p>
        </form>
    </div>
    <?php
}

function {$p}_run_import() {
    // 1. Create pages
{$page_inserts}

    // 2. Create front page
    \$home = get_page_by_path('home');
    if (!\$home) {
        \$home_id = wp_insert_post([
            'post_title'  => 'Home',
            'post_name'   => 'home',
            'post_status' => 'publish',
            'post_type'   => 'page',
            'post_content' => str_replace('%THEMEURI%', get_template_directory_uri(), '{$home_content}'),
        ]);
    } else {
        \$home_id = \$home->ID;
    }
    update_option('show_on_front', 'page');
    update_option('page_on_front', \$home_id);

    // 3. Set scanned site identity (browser tab + bloginfo reflect the copy)
{$blogname_php}
    // 3b. v4.3.0 Item 7: set permalink structure so pretty URLs work immediately
    //     after theme activation (avoids 404s until an admin visits Settings → Permalinks).
    update_option('permalink_structure', '/%postname%/');
    flush_rewrite_rules(true);

    // 4. Create nav menu — verbatim scanned nav model, full DOM order
    \$menu_name = '{$name} Menu';
    \$existing_menu = wp_get_nav_menu_object(\$menu_name);
    if (\$existing_menu) { wp_delete_nav_menu(\$existing_menu->term_id); }
    \$menu_id = wp_create_nav_menu(\$menu_name);
    if (!is_wp_error(\$menu_id)) {
{$nav_items_code}
        \$locations = get_theme_mod('nav_menu_locations', []);
        \$locations['primary'] = \$menu_id;
        set_theme_mod('nav_menu_locations', \$locations);
    }

    // 5. Import images to media library
{$image_imports}

    // 6. Seed WooCommerce products from the bundled product import CSV.
    //    inc/product-import.csv is the single source of truth (junk-filtered,
    //    real prices) — the same file used for manual WooCommerce CSV import.
    \$count = 0;
    \$csv_file = get_template_directory() . '/inc/product-import.csv';
    if (class_exists('WC_Product_Simple') && file_exists(\$csv_file)) {
        \$fh = fopen(\$csv_file, 'r');
        if (\$fh !== false) {
            \$header = fgetcsv(\$fh);
            \$col = is_array(\$header) ? array_flip(\$header) : [];
            while ((\$row = fgetcsv(\$fh)) !== false && \$count < 250) {
                \$pname = trim((string) (\$row[\$col['Name'] ?? 2] ?? ''));
                if (\$pname === '') continue;
                \$sku = trim((string) (\$row[\$col['SKU'] ?? 1] ?? ''));
                if (\$sku !== '' && function_exists('wc_get_product_id_by_sku') && wc_get_product_id_by_sku(\$sku)) {
                    continue; // already imported
                }
                \$prod = new WC_Product_Simple();
                \$prod->set_name(\$pname);
                \$pdesc = trim((string) (\$row[\$col['Description'] ?? 6] ?? ''));
                if (\$pdesc !== '') {
                    \$prod->set_description(\$pdesc);
                }
                \$pshort = trim((string) (\$row[\$col['Short description'] ?? 5] ?? ''));
                if (\$pshort !== '') {
                    \$prod->set_short_description(\$pshort);
                }
                \$price = trim((string) (\$row[\$col['Regular price'] ?? 4] ?? ''));
                if (\$price !== '' && is_numeric(\$price)) {
                    \$prod->set_regular_price(\$price);
                }
                if (\$sku !== '') {
                    try { \$prod->set_sku(\$sku); } catch (Exception \$e) { /* duplicate SKU — keep without */ }
                }
                \$prod->set_status('publish');
                \$prod->set_catalog_visibility('visible');
                \$pid = \$prod->save();
                \$pcat = trim((string) (\$row[\$col['Categories'] ?? 7] ?? ''));
                if (\$pid && \$pcat !== '') {
                    wp_set_object_terms(\$pid, array_map('trim', explode(',', \$pcat)), 'product_cat');
                }
                // Sideload featured image from the Images column (URL or local asset).
                if (\$pid && !has_post_thumbnail(\$pid)) {
                    \$pimg = trim((string) (\$row[\$col['Images'] ?? 8] ?? ''));
                    if (\$pimg !== '') {
                        // If it looks like a relative path (no scheme), try the bundled theme asset.
                        if (!preg_match('#^https?://#i', \$pimg)) {
                            \$pimg = get_template_directory_uri() . '/assets/images/' . ltrim(\$pimg, '/');
                        }
                        try {
                            if (!function_exists('media_sideload_image')) {
                                require_once ABSPATH . 'wp-admin/includes/media.php';
                                require_once ABSPATH . 'wp-admin/includes/file.php';
                                require_once ABSPATH . 'wp-admin/includes/image.php';
                            }
                            \$attach_id = media_sideload_image(\$pimg, \$pid, null, 'id');
                            if (!\$attach_id instanceof WP_Error && is_int(\$attach_id) && \$attach_id > 0) {
                                set_post_thumbnail(\$pid, \$attach_id);
                            }
                        } catch (Exception \$e) {
                            error_log("[{$td}] Featured image sideload failed for product {\$pid}: " . \$e->getMessage());
                        }
                    }
                }
                \$count++;
            }
            fclose(\$fh);
        }
    }
    if (\$count > 0) {
        error_log("[{$td}] Created {\$count} products from product-import.csv");
    }
}

function {$p}_import_theme_image(\$filename) {
    \$theme_dir = get_template_directory() . '/assets/images/' . \$filename;
    if (!file_exists(\$theme_dir)) return;

    \$upload_dir = wp_upload_dir();
    \$dest = \$upload_dir['path'] . '/' . \$filename;

    if (!file_exists(\$dest)) {
        copy(\$theme_dir, \$dest);
    }

    \$existing = get_posts([
        'post_type'   => 'attachment',
        'post_status' => 'inherit',
        'meta_key'    => '_b442wp_original_file',
        'meta_value'  => \$filename,
        'numberposts' => 1,
    ]);

    if (!empty(\$existing)) return;

    \$filetype = wp_check_filetype(\$filename, null);
    \$attachment = [
        'guid'           => \$upload_dir['url'] . '/' . \$filename,
        'post_mime_type' => \$filetype['type'],
        'post_title'     => sanitize_file_name(pathinfo(\$filename, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    \$attach_id = wp_insert_attachment(\$attachment, \$dest);
    if (!\is_wp_error(\$attach_id)) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        \$meta = wp_generate_attachment_metadata(\$attach_id, \$dest);
        wp_update_attachment_metadata(\$attach_id, \$meta);
        update_post_meta(\$attach_id, '_b442wp_original_file', \$filename);
    }
}
PHP;
    }
}
