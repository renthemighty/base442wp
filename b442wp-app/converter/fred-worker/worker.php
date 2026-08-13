#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Fred B442 Worker — polls base44towordpress.com for fred_queue jobs,
 * runs ThemeBuilder locally (no time limit), and POSTs the ZIP back.
 *
 * Run via cron every minute or systemd timer:
 *   * * * * * /usr/bin/php /home/ubuntu/b442-worker/worker.php >> /home/ubuntu/b442-worker/cron.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/DeterministicGen.php';
require_once __DIR__ . '/ThemeBuilder.php';

$server_url = rtrim((string) config('server_url'), '/');
$fred_token = (string) config('fred_token');
$log_file   = __DIR__ . '/worker.log';

function wlog(string $msg): void
{
    global $log_file;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
    file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND);
}

function fred_curl(string $url, array $opts = []): array
{
    global $fred_token;
    $ch = curl_init($url);
    curl_setopt_array($ch, array_replace([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['X-Fred-Token: ' . $fred_token, 'Host: app.base44towordpress.com'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ], $opts));
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['body' => $body, 'http' => $http, 'err' => $err];
}

// ── v4.4.1 live-bundle fallback (ported from fred-drive.php's fetchLiveBundles,
// fetchAndConcat, resolveUrl, fetchUrl — copied in rather than required, since
// fred-drive.php is a standalone driver with its own argv handling and
// top-level code that must not execute during a worker run) ─────────────────
//
// Before this, a job whose app-server cache had nothing cached for it (older
// jobs, or a fred-bundle miss) built with NO original stylesheet at all:
// Tailwind utility classes with nothing compiled behind them, the worst
// fidelity failure this converter has had. fred-drive.php solved this in
// v4.2.0 for its own standalone path; this ports the same fix into worker.php,
// the path every customer job actually runs through.

/**
 * When the app-server cache had nothing for a bundle type, download the
 * scanned home page's <link rel=stylesheet> / <script src> assets straight
 * from the live site and concatenate them into the same bundles/bundle-0.css
 * and bundles/bundle-0.js files ThemeBuilder::loadBundle() already reads, so
 * ThemeBuilder needs no change. Only fetches the types listed in $need.
 * Failures here are non-fatal — the build proceeds bundle-less, exactly as
 * it did before this fallback existed.
 */
function fetchLiveBundlesFallback(string $bundle_dir, string $scan_dir, array $manifest, string $live_url, array $need): void
{
    if (empty($need['css']) && empty($need['js'])) return;

    $pages = $manifest['pages'] ?? [];
    if (empty($pages)) { wlog('Live-bundle fallback: no pages in manifest, skipping'); return; }

    $home = $pages[0];
    foreach ($pages as $pg) { if (($pg['slug'] ?? '') === 'home') { $home = $pg; break; } }
    $html_file = (string) ($home['html_file'] ?? '');
    if ($html_file === '') { wlog('Live-bundle fallback: no home html_file in manifest, skipping'); return; }

    $home_path = $scan_dir . '/' . $html_file;
    if (!is_file($home_path)) { wlog('Live-bundle fallback: home scan file missing, skipping'); return; }

    $home_html = (string) file_get_contents($home_path);
    if ($home_html === '') { wlog('Live-bundle fallback: home scan file empty, skipping'); return; }

    if (!is_dir($bundle_dir)) mkdir($bundle_dir, 0750, true);

    if (!empty($need['css'])) {
        $css_urls = [];
        if (preg_match_all('/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $home_html, $links)) {
            foreach ($links[0] as $tag) {
                if (preg_match('/href=["\']([^"\']+)["\']/i', $tag, $m)) $css_urls[] = $m[1];
            }
        }
        $css_bundle = fetchAndConcat($css_urls, $live_url);
        if ($css_bundle['content'] !== '') {
            file_put_contents($bundle_dir . '/bundle-0.css', $css_bundle['content']);
            wlog('css bundle fetched from live site: ' . strlen($css_bundle['content']) . ' bytes (' . $css_bundle['ok'] . ' files)');
        } else {
            wlog('css bundle: live-site fallback also found nothing (' . count($css_urls) . ' candidate urls)');
        }
    }

    if (!empty($need['js'])) {
        $js_urls = [];
        if (preg_match_all('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $home_html, $scripts)) {
            $js_urls = $scripts[1];
        }
        $js_bundle = fetchAndConcat($js_urls, $live_url);
        if ($js_bundle['content'] !== '') {
            file_put_contents($bundle_dir . '/bundle-0.js', $js_bundle['content']);
            wlog('js bundle fetched from live site: ' . strlen($js_bundle['content']) . ' bytes (' . $js_bundle['ok'] . ' files)');
        } else {
            wlog('js bundle: live-site fallback also found nothing (' . count($js_urls) . ' candidate urls)');
        }
    }
}

/** Resolve each URL against $base_url and download it, concatenating bodies. */
function fetchAndConcat(array $urls, string $base_url): array
{
    $content = '';
    $ok = 0;
    foreach (array_values(array_unique($urls)) as $url) {
        $abs = resolveUrl($url, $base_url);
        if ($abs === '') continue;
        $body = fetchUrl($abs);
        if ($body === '') continue;
        $content .= "/* " . $abs . " */\n" . $body . "\n";
        $ok++;
    }
    return ['content' => $content, 'ok' => $ok];
}

/** Resolve a possibly-relative URL against the live page's origin. */
function resolveUrl(string $url, string $base_url): string
{
    $url = trim($url);
    if ($url === '' || strpos($url, 'data:') === 0) return '';
    if (preg_match('#^https?://#i', $url)) return $url;

    $parts = parse_url($base_url);
    if (!$parts || empty($parts['host'])) return '';
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'];
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    $origin = $scheme . '://' . $host . $port;

    if (strpos($url, '//') === 0) return $scheme . ':' . $url;
    if ($url[0] === '/') return $origin . $url;

    // relative to the base path
    $base_path = rtrim(dirname($parts['path'] ?? '/'), '/');
    return $origin . $base_path . '/' . $url;
}

/** Download a URL with a browser UA and a 20s timeout. '' on any failure. */
function fetchUrl(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $http >= 200 && $http < 300) return (string) $body;
        return '';
    }

    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 20,
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
            . "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? (string) $body : '';
}

// ── Prevent parallel runs ────────────────────────────────────────────────────
$lock_file = __DIR__ . '/.worker.lock';
$lock = fopen($lock_file, 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Another instance is running
    exit(0);
}

// ── Claim a job ──────────────────────────────────────────────────────────────
$claim = fred_curl($server_url . '/api/fred-claim');
if ($claim['http'] === 204 || empty($claim['body'])) {
    exit(0); // no jobs
}
if ($claim['http'] !== 200) {
    wlog("ERROR: fred-claim returned HTTP {$claim['http']}: {$claim['body']}");
    exit(1);
}

$job = json_decode($claim['body'], true);
if (empty($job['id'])) {
    wlog("ERROR: bad job payload: " . $claim['body']);
    exit(1);
}

wlog("Claimed job #{$job['id']} ({$job['uuid']}) — " . ($job['live_url'] ?? ''));

// ── Fetch scraped asset bundles from app server ─────────────────────────────
// v4.4.1: the app-server cache stays the FIRST choice. $bundle_from_cache
// records which types it actually delivered; anything still missing gets a
// second chance from fetchLiveBundlesFallback() once the site scan below has
// produced a manifest to read the home page's <link>/<script> tags from.
$bundle_dir = rtrim((string) config('cache_dir', ''), '/') . '/scraped/' . $job['uuid'] . '/bundles';
if (!is_dir($bundle_dir)) {
    mkdir($bundle_dir, 0750, true);
}
$bundle_from_cache = ['css' => false, 'js' => false];
foreach (['css', 'js'] as $bt) {
    $resp = fred_curl($server_url . '/api/fred-bundle?id=' . (int) $job['id'] . '&type=' . $bt, [
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($resp['http'] === 200 && !empty($resp['body'])) {
        $bundle_path = $bundle_dir . '/bundle-0.' . $bt;
        file_put_contents($bundle_path, $resp['body']);
        wlog("{$bt} bundle from app cache: " . strlen($resp['body']) . ' bytes');
        $bundle_from_cache[$bt] = true;
    } else {
        wlog("No {$bt} bundle available (HTTP {$resp['http']})");
    }
}

// ── Run ThemeBuilder ─────────────────────────────────────────────────────────
ini_set('memory_limit', '-1');
set_time_limit(0);

$output_dir = (string) config('output_dir');
if (!is_dir($output_dir)) mkdir($output_dir, 0750, true);

$conversion = [
    'id'              => (int) $job['id'],
    'uuid'            => $job['uuid'],
    'theme_name'      => $job['theme_name'] ?? '',
    'live_url'        => $job['live_url'] ?? '',
    'has_woocommerce' => $job['has_woocommerce'] ? 1 : 0,
    'source_zip_path' => null,
    'scraped_data'    => null,
];

$analysis = $job['analysis'] ?? [];
if (!empty($conversion['theme_name'])) {
    $analysis['theme_name'] = $conversion['theme_name'];
}

// ── Multi-page Playwright site scan — v3.0.1 ─────────────────────────────────
// Runs scanner/site-scan.js (up to 25 pages: rendered DOM + screenshots +
// per-page computed styles + image inventory). The manifest becomes
// $analysis['site_scan'] (+ $analysis['site_scan_dir'] so ThemeBuilder can read
// the scan/{slug}.html DOM files), and the home-page entry is mapped onto
// $analysis['live_scan'] so every v2.x ground-truth consumer keeps working.
// On scan failure we fall back to the v2 single-page live-scan.js.
// Scans NEVER fail the build.
$live_url = trim((string) ($job['live_url'] ?? ''));
if ($live_url !== '' && !preg_match('#^https?://(?:www\.)?app\.base44\.com#i', $live_url)) {
    $scan_dir = rtrim((string) config('cache_dir', ''), '/') . '/scraped/' . $job['uuid'] . '/site-scan';
    if (!is_dir($scan_dir)) mkdir($scan_dir, 0750, true);

    // ── v3.0.1: seed the scanner with known routes from analysis_data ───────
    // SPA sites whose nav uses JS click-handlers expose no <a> anchors, so the
    // crawler finds 0 sub-pages — but the analysis already knows the route
    // list. Collect every plausible route (pages[].path, nav_items[].href,
    // sub_pages[*].route|url), dedupe, and hand them to site-scan.js v1.1.0
    // via --seeds. The scanner normalizes/filters them like any discovered
    // anchor (auth/admin slugs, off-origin URLs etc. are still skipped).
    $seed_routes = [];
    foreach (($analysis['pages'] ?? []) as $pg) {
        if (is_array($pg) && !empty($pg['path']) && is_string($pg['path'])) {
            $seed_routes[] = $pg['path'];
        }
    }
    foreach (($analysis['nav_items'] ?? []) as $ni) {
        if (is_array($ni) && !empty($ni['href']) && is_string($ni['href'])) {
            $seed_routes[] = $ni['href'];
        }
    }
    foreach (($analysis['sub_pages'] ?? []) as $sp) {
        if (!is_array($sp)) continue;
        if (!empty($sp['route']) && is_string($sp['route'])) {
            $seed_routes[] = $sp['route'];
        } elseif (!empty($sp['url']) && is_string($sp['url'])) {
            $seed_routes[] = $sp['url'];
        }
    }
    // ── v3.2.0: explicit zip route list (ZipExtractor → Analyzer routes[]) ──
    // Lovable/TanStack exports carry the full route table in the zip while
    // their live bundles are code-split (no route strings in the JS, no
    // anchors in the SPA shell) — the zip routes are the only complete
    // page-set source for those sites. source_builder is logged below.
    foreach (($analysis['routes'] ?? []) as $rt) {
        if (is_string($rt) && trim($rt) !== '') {
            $seed_routes[] = trim($rt);
        }
    }
    // Dynamic route templates can never be scanned: TanStack `$param`,
    // react-router `:param`, wildcards.
    $seed_routes = array_values(array_unique(array_filter(
        array_map('trim', $seed_routes),
        static fn ($r) => $r !== '' && !preg_match('/[:*$]/', (string) $r)
    )));

    $seeds_opt = '';
    if (!empty($seed_routes)) {
        $seeds_file = $scan_dir . '/seeds.json';
        file_put_contents($seeds_file, json_encode($seed_routes));
        $seeds_opt = ' --seeds=' . escapeshellarg($seeds_file);
    }

    $scan_cmd = 'timeout 3600 node ' . escapeshellarg(__DIR__ . '/scanner/site-scan.js') . ' '
              . escapeshellarg($live_url) . ' ' . escapeshellarg($scan_dir) . ' 200' . $seeds_opt
              . ' 2>>' . escapeshellarg(__DIR__ . '/scanner/scan-errors.log');
    wlog('Site scan (v3.2.1, max 200 pages, ' . count($seed_routes) . ' seed routes, source_builder='
         . (string) ($analysis['source_builder'] ?? 'base44') . '): ' . $live_url);
    shell_exec($scan_cmd);

    $manifest_path = $scan_dir . '/scan/manifest.json';
    $manifest = is_file($manifest_path)
        ? json_decode((string) file_get_contents($manifest_path), true)
        : null;

    if (is_array($manifest) && !empty($manifest['pages'])) {
        $analysis['site_scan']     = $manifest;
        $analysis['site_scan_dir'] = $scan_dir;

        // Backward-compat shim: home page entry → $analysis['live_scan']
        // (same shape live-scan.js v3.1.0 emitted) so DeterministicGen /
        // SectionGen v2 ground-truth code paths keep working unchanged.
        $home = $manifest['pages'][0];
        foreach ($manifest['pages'] as $pg) {
            if (($pg['slug'] ?? '') === 'home') { $home = $pg; break; }
        }
        $home_png = !empty($home['screenshot_file']) ? $scan_dir . '/' . $home['screenshot_file'] : '';

        // ── v3.1.0 FAITHFUL NAV: resolve 'action' nav items to discovered ─────
        // pages. Scanner marks JS-CTA buttons (no <a href>, no on-page anchor)
        // as kind=action. If such a label corresponds to a real crawled page
        // (e.g. "Search Homes" → /listings), promote it to kind=page/target.
        // The VISIBLE label text is never changed — only the resolved target.
        $page_slugs = [];   // slug => '/path'
        foreach ($manifest['pages'] as $pg) {
            $slug = (string) ($pg['slug'] ?? '');
            if ($slug === '' || $slug === 'home') continue;
            $path = '/' . ltrim((string) (parse_url((string) ($pg['url'] ?? ''), PHP_URL_PATH) ?: $slug), '/');
            $page_slugs[$slug] = ['path' => rtrim($path, '/') ?: ('/' . $slug), 'h1' => (string) ($pg['h1'] ?? ''), 'title' => (string) ($pg['title'] ?? '')];
        }
        $slugify = function (string $t): string {
            $t = strtolower(trim($t));
            $t = preg_replace('/&/', ' and ', $t);
            $t = preg_replace('/[^a-z0-9]+/', '-', $t);
            return trim((string) $t, '-');
        };
        $nav_model = $manifest['nav'] ?? [];
        foreach ($nav_model as &$ni) {
            if (($ni['kind'] ?? '') !== 'action') continue;
            $label = (string) ($ni['text'] ?? '');
            $lslug = $slugify($label);
            $best = null;
            $label_tokens = array_filter(preg_split('/-/', $lslug), fn($t) => strlen($t) >= 4);
            foreach ($page_slugs as $pslug => $meta) {
                // full searchable text for this page (slug + title + h1), slugified
                $ptext = $slugify($meta['title'] . ' ' . $meta['h1'] . ' ' . $pslug);
                // exact slug or whole-label substring match
                if ($pslug === $lslug
                    || str_contains($pslug, $lslug) || str_contains($lslug, $pslug)
                    || (strlen($lslug) >= 4 && str_contains($ptext, $lslug))) {
                    $best = $meta['path'];
                    break;
                }
                // token-level: any significant label token appears in the page's
                // slug/title/h1 (e.g. "Search Homes" → listings page h1 "Property Search")
                foreach ($label_tokens as $tok) {
                    if (str_contains($ptext, $tok)) { $best = $meta['path']; break 2; }
                }
            }
            if ($best !== null) {
                $ni['kind'] = 'page';
                $ni['target'] = $best;
            }
        }
        unset($ni);

        $analysis['live_scan'] = [
            'scanner_version' => 'site-scan ' . ($manifest['scanner_version'] ?? '?'),
            'url'      => $home['url'] ?? $live_url,
            'computed' => $home['computed'] ?? [],
            'fonts'    => [
                'document_fonts' => $manifest['fonts']['document_fonts'] ?? [],
                'google_links'   => $manifest['google_font_links'] ?? [],
                'font_faces'     => $manifest['fonts']['font_faces'] ?? [],
            ],
            'palette'      => $manifest['palette'] ?? [],
            'root_vars'    => $manifest['root_vars'] ?? [],
            'nav'          => $nav_model,
            'header_ctas'  => $manifest['header_ctas'] ?? [],
            'sections'     => $manifest['sections'] ?? [],
            'logo'         => $manifest['logo'] ?? null,
            'site_title'        => (string) ($manifest['site_title'] ?? ($home['title'] ?? '')),
            'site_description'  => (string) ($manifest['site_description'] ?? ($home['meta_description'] ?? '')),
            'screenshots'  => ['desktop' => ($home_png !== '' && is_file($home_png)) ? $home_png : ''],
        ];
        // Make the nav model resolution visible in the manifest copy too
        $manifest['nav'] = $nav_model;
        $analysis['site_scan'] = $manifest;

        $nav_dump = implode(' | ', array_map(
            fn($n) => ($n['text'] ?? '?') . '→' . ($n['kind'] ?? '?') . ':' . ($n['target'] ?? '∅'),
            $nav_model));
        wlog('Site scan OK: ' . count($manifest['pages']) . ' pages, '
             . count($manifest['errors'] ?? []) . ' errors, '
             . round(($manifest['total_bytes'] ?? 0) / 1048576, 1) . 'MB, '
             . round(($manifest['duration_ms'] ?? 0) / 1000) . 's, nav: '
             . count($nav_model) . ' items');
        wlog('Nav model: ' . $nav_dump);

        // ── v4.4.1: live-bundle fallback, after the scan, before ThemeBuilder ──
        if (!$bundle_from_cache['css'] || !$bundle_from_cache['js']) {
            fetchLiveBundlesFallback($bundle_dir, $scan_dir, $manifest, $live_url, [
                'css' => !$bundle_from_cache['css'],
                'js'  => !$bundle_from_cache['js'],
            ]);
        }
    } else {
        // ── Fallback: v2 single-page live-scan.js (never fail the build) ────
        wlog('Site scan FAILED (non-fatal) — falling back to single-page live-scan');
        $ls_dir = rtrim((string) config('cache_dir', ''), '/') . '/scraped/' . $job['uuid'] . '/live-scan';
        if (!is_dir($ls_dir)) mkdir($ls_dir, 0750, true);
        $ls_cmd = 'timeout 90 node ' . escapeshellarg(__DIR__ . '/scanner/live-scan.js') . ' '
                . escapeshellarg($live_url) . ' ' . escapeshellarg($ls_dir)
                . ' 2>>' . escapeshellarg(__DIR__ . '/scanner/scan-errors.log');
        $scan_json = shell_exec($ls_cmd);
        $scan = is_string($scan_json) ? json_decode($scan_json, true) : null;
        if (is_array($scan) && !empty($scan['computed'])) {
            $analysis['live_scan'] = $scan;
            wlog('Live scan fallback OK: h1 font [' . ($scan['computed']['h1']['font-family'] ?? '?') . ']');
        } else {
            wlog('Live scan fallback FAILED (non-fatal): building without ground truth');
        }
    }
} elseif ($live_url !== '') {
    wlog("Site scan skipped: editor URL ({$live_url})");
}

$zip_path = null;

try {
    $builder  = new ThemeBuilder($conversion, $analysis);
    $result   = $builder->build();
    $zip_path = $result['zip_path'] ?? null;

    if (!$zip_path || !file_exists($zip_path)) {
        throw new RuntimeException('Builder produced no ZIP' . ($zip_path ? " at: {$zip_path}" : ''));
    }

    $zip_kb = round(filesize($zip_path) / 1024, 1);
    wlog("Build OK: {$zip_path} ({$zip_kb} KB), " .
         "{$result['ai_calls']} AI calls, " .
         ($result['tokens_input'] ?? 0) . '+' . ($result['tokens_output'] ?? 0) . ' tokens, cache ' .
         ($result['tokens_cache_creation'] ?? 0) . ' created / ' .
         ($result['tokens_cache_read'] ?? 0) . ' read');

    $degraded = $result['degraded'] ?? [];
    if (!empty($degraded)) {
        wlog('Visual gate degraded pages (' . count($degraded) . '): ' . implode(', ', $degraded));
    }

} catch (Throwable $e) {
    wlog("BUILD ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    fred_curl($server_url . '/api/fred-complete', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => ['conversion_id' => $job['id'], 'error' => $e->getMessage()],
        CURLOPT_TIMEOUT    => 30,
    ]);

    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

// ── Upload ZIP ───────────────────────────────────────────────────────────────
wlog("Uploading ZIP to server...");

$upload = fred_curl($server_url . '/api/fred-complete', [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => [
        'conversion_id' => (string) $job['id'],
        'ai_calls'      => (string) ($result['ai_calls'] ?? 0),
        'tokens_input'  => (string) ($result['tokens_input'] ?? 0),
        'tokens_output' => (string) ($result['tokens_output'] ?? 0),
        'degraded'      => json_encode($result['degraded'] ?? []),
        'zip_file'      => new CURLFile($zip_path, 'application/zip', basename($zip_path)),
    ],
    CURLOPT_TIMEOUT    => 1800,
]);

if ($upload['http'] === 200) {
    wlog("SUCCESS job #{$job['id']}: " . $upload['body']);
    // Clean up local tmp
    $conv_tmp = $output_dir . '/' . $job['uuid'];
    if (is_dir($conv_tmp)) {
        exec('rm -rf ' . escapeshellarg($conv_tmp));
    }
} else {
    wlog("UPLOAD FAIL HTTP {$upload['http']}: " . $upload['body']);
}

flock($lock, LOCK_UN);
fclose($lock);
