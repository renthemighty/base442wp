<?php
/**
 * ThemeBuilder v4 — Orchestrates WordPress theme generation.
 *
 * KEY CHANGES from v3 (v4.0.0):
 * - Stage 1: StaticRebuilder (Claude emits faithful HTML per page, batched)
 * - Stage 2: VisualGate (pixel-diff each page against reference screenshot;
 *   auto-retry on review/fail; degraded[] list for pages that still fail)
 * - Stage 3: Deterministic templatization (DeterministicGen only — no PHP
 *   sections from AI, no CodeReviewer, no FaithfulPostProcess, no GuardrailChecker)
 * - Removes: SectionGen, CodeReviewer, GuardrailChecker, FaithfulPostProcess,
 *   convertScanPages, parseSegmentation, extractScanBody, convertPageWithClaude,
 *   extractPageBody, finalReviewGate, runGuardrail, stripFailingFile
 */
declare(strict_types=1);

require_once __DIR__ . '/ClaudeClient.php';
require_once __DIR__ . '/DeterministicGen.php';
require_once __DIR__ . '/StaticRebuilder.php';
require_once __DIR__ . '/VisualGate.php';
require_once __DIR__ . '/MediaImporter.php';

class ThemeBuilder
{
    /**
     * Builder version — bump on every behavioural change and document it in
     * BUILDER-CHANGELOG.md. Echoed at build start so cron.log records which
     * version built each job.
     */
    public const BUILDER_VERSION = '4.4.1';  // worker.php bundle fallback: when the app-server cache has no CSS/JS bundle for a job, fetch it straight from the live site instead of building with no stylesheet. Ported from fred-drive.php v4.2.0. See BUILDER-CHANGELOG.md.

    /**
     * v4.4.0: output-fidelity ceilings. Accuracy is the only target for this
     * converter; cost and time are explicitly not constraints. These are
     * generous safety nets against runaway input, not budget caps. Named
     * constants so each has exactly one place to change.
     */
    public const MAX_PAGES = 200;              // resolveScanPageSet() sub-page cap. Was 25.
    public const MAX_IMAGES = 2000;            // media import total cap. Was 200.
    public const MAX_TEXT_CHARS = 2000000;     // WXR content / prompt-body truncation safety net. Was 150000/100000/60000 in different spots.
    public const DEFAULT_PRODUCT_LIMIT = 250;  // product_limit fallback when no paid tier is resolved. Was 25.

    private array $conversion;
    private array $analysis;
    private string $prefix;
    private string $theme_name;
    private string $theme_slug;
    private string $output_base;
    private string $js_bundle;
    private string $css_bundle;
    private array $files = [];
    private int $product_limit = 0;
    private string $scannerDir;
    private string $workDir;

    /** MediaImporter results (url → assets/images/<file>). */
    private array $media_by_url = [];
    /** misc single AI calls made directly by ThemeBuilder (products). */
    private int $misc_calls = 0;

    public function __construct(array $conversion, array $analysis, ?string $scannerDir = null, ?string $workDir = null)
    {
        $this->conversion = $conversion;
        $this->analysis   = $analysis;
        $this->theme_name = $analysis['theme_name'] ?? 'Theme';
        // Fallback: derive theme name from the conversion's live URL when missing/generic
        if (trim((string) $this->theme_name) === '' || $this->theme_name === 'Theme') {
            $url   = $conversion['live_url'] ?? ($analysis['url'] ?? '');
            $host  = parse_url((string) $url, PHP_URL_HOST);
            $parts = explode('.', (string) $host);
            if (!empty($parts[0]) && strtolower($parts[0]) === 'www' && !empty($parts[1])) {
                array_shift($parts);
            }
            if (!empty($parts[0]) && strtolower($parts[0]) !== 'www') {
                $this->theme_name = ucwords(str_replace('-', ' ', $parts[0]));
            }
        }
        $this->theme_slug = strtolower(trim(
            preg_replace('/[^a-z0-9]+/i', '-', $this->theme_name), '-'
        )) ?: 'theme';
        $this->prefix      = $this->theme_slug;
        $this->output_base = rtrim((string) config('output_dir'), '/');
        $analysis_products = count($analysis['products'] ?? []);
        $this->product_limit = (int) (conversion_tier_from_product_count($analysis_products)['max_products'] ?? 0);
        if ($this->product_limit <= 0 && !empty($analysis['has_woocommerce'])) {
            // v4.4.0: was hardcoded to 25, silently under-delivered every
            // paid tier above woo25 whenever the scan's product count came
            // back 0 at construction time. Default to the same generous
            // ceiling used everywhere else in this file instead.
            $this->product_limit = self::DEFAULT_PRODUCT_LIMIT;
        }

        $this->scannerDir = $scannerDir ?: (__DIR__ . '/scanner');
        $this->workDir    = $workDir ?: (sys_get_temp_dir() . '/b442-gate-' . ($conversion['uuid'] ?? 'job'));

        // Load the scraped bundles
        $this->js_bundle  = $this->loadBundle('js');
        $this->css_bundle = $this->loadBundle('css');
    }

    public function build(): array
    {
        echo "  [Builder] ThemeBuilder v" . self::BUILDER_VERSION . "\n";
        echo "  [Builder] Building: {$this->theme_name} (prefix: {$this->prefix})\n";
        if (!empty($this->analysis['live_scan']['computed'])) {
            $ls = $this->analysis['live_scan'];
            echo "  [Builder] Live scan ground truth: ON (h1: " . ($ls['computed']['h1']['font-family'] ?? '?')
               . " / body: " . ($ls['computed']['body']['font-family'] ?? '?') . ")\n";
        } else {
            echo "  [Builder] Live scan ground truth: none (CSS-bundle heuristics only)\n";
        }
        $scan_nav = $this->analysis['live_scan']['nav'] ?? [];
        if (!empty($scan_nav)) {
            $texts = array_filter(array_map(fn($n) => trim((string) ($n['text'] ?? '')), $scan_nav));
            echo "  [Builder] Live scan nav (" . count($texts) . " items): " . implode(' | ', $texts) . "\n";
        } else {
            echo "  [Builder] Live scan nav: none — menu falls back to filtered analysis nav_items\n";
        }
        $scan_pages = $this->analysis['site_scan']['pages'] ?? [];
        if (!empty($scan_pages)) {
            echo "  [Builder] Site scan: " . count($scan_pages) . " pages captured ("
               . implode(', ', array_map(fn($p) => (string) ($p['slug'] ?? '?'), $scan_pages)) . ")\n";
        } else {
            echo "  [Builder] Site scan: none\n";
        }
        echo "\n";

        // Prefer the scanned home DOM when richer than the analyzer's rendered_html.
        $scan_home_html = '';
        foreach ($scan_pages as $pg) {
            if (($pg['slug'] ?? '') === 'home') { $scan_home_html = $this->readScanHtml($pg); break; }
        }
        if ($scan_home_html !== '' && strlen($scan_home_html) > strlen((string) ($this->analysis['rendered_html'] ?? ''))) {
            $this->analysis['rendered_html'] = $scan_home_html;
            echo "  [Builder] Home rendered DOM upgraded from site scan (" . strlen($scan_home_html) . " bytes)\n";
        }

        $claude = new ClaudeClient();

        // ── Stage 0: Media import ─────────────────────────────────────────────
        // Must run BEFORE DeterministicGen construction so media_map is populated
        // (faithful header logo path depends on it).
        $this->setStage('media');
        $this->importMedia();

        // ── Stage CSS: Deterministic CSS/JS + style.css ───────────────────────
        // Build these first so the visual gate can reference theme.css by
        // absolute path during the page-rebuild loop.
        $this->setStage('css');
        $det = new DeterministicGen($this->prefix, $this->analysis);

        echo "  [Builder] Generating style.css\n";
        $this->files['style.css'] = $det->generateStyleCss();

        echo "  [Builder] Generating theme.css\n";
        $theme_css = $det->generateThemeCss();
        if (!empty($this->css_bundle)) {
            $cleaned_css = $this->cleanCssBundle($this->css_bundle);
            $this->files['assets/css/theme.css'] = "/* Original site CSS */\n" . $cleaned_css . "\n\n/* Theme additions */\n" . $theme_css;
            echo "  [Builder]   → Included " . round(strlen($cleaned_css) / 1024, 1) . "KB site CSS (cleaned)\n";
        } else {
            $this->files['assets/css/theme.css'] = $theme_css;
        }

        echo "  [Builder] Generating theme.js\n";
        $this->files['assets/js/theme.js'] = $det->generateThemeJs();

        // Write theme.css to disk immediately so VisualGate can point at it.
        $theme_dir = $this->themeDir();
        if (!is_dir($theme_dir . '/assets/css')) mkdir($theme_dir . '/assets/css', 0755, true);
        if (!is_dir($theme_dir . '/assets/js'))  mkdir($theme_dir . '/assets/js',  0755, true);
        file_put_contents($theme_dir . '/assets/css/theme.css', $this->files['assets/css/theme.css']);
        file_put_contents($theme_dir . '/assets/js/theme.js',   $this->files['assets/js/theme.js']);
        $cssAbs = $theme_dir . '/assets/css/theme.css';

        // ── Build the page set ────────────────────────────────────────────────
        // Home: use scanned home DOM. Inner pages: resolveScanPageSet().
        $scan_page_set = $this->resolveScanPageSet();

        // Gather all pages: home first, then inner pages.
        // $page_jobs[slug] = ['title', 'html', 'computed', 'screenshot']
        $page_jobs_input = [];

        // Home page
        $home_html = (string) ($this->analysis['rendered_html'] ?? '');
        if ($home_html !== '') {
            // Find home entry for screenshot
            $home_screenshot = null;
            foreach ($scan_pages as $pg) {
                if (($pg['slug'] ?? '') === 'home') {
                    $home_screenshot = $this->resolveScreenshot($pg);
                    break;
                }
            }
            $home_computed = [];
            foreach ($scan_pages as $pg) {
                if (($pg['slug'] ?? '') === 'home') { $home_computed = $pg['computed'] ?? []; break; }
            }
            $page_jobs_input['home'] = [
                'title'      => (string) ($this->analysis['theme_name'] ?? $this->theme_name),
                'html'       => $home_html,
                'computed'   => $home_computed,
                'screenshot' => $home_screenshot,
            ];
        }

        foreach ($scan_page_set as $slug => $entry) {
            $page_jobs_input[$slug] = [
                'title'      => (string) ($entry['_claim_title'] ?? ucwords(str_replace('-', ' ', $slug))),
                'html'       => (string) ($entry['_rendered_html'] ?? ''),
                'computed'   => $entry['computed'] ?? [],
                'screenshot' => $this->resolveScreenshot($entry),
            ];
        }

        // ── STAGE 1: StaticRebuilder (Claude emits faithful HTML per page) ────
        $this->setStage('pages');
        $rebuilder = new StaticRebuilder($claude, $this->prefix, $this->analysis, $this->css_bundle);

        // v4.2.0: pages whose body exceeds the 45k single-call cap go through
        // StaticRebuilder::rebuildChunked() (section-boundary split + one
        // Claude call per chunk) instead of the batch job, which used to
        // silently substr()-truncate them and drop trailing sections.
        $chunked_slugs = [];
        $batch_jobs = [];
        // v4.4.x: slugs where rebuildChunked() permanently lost a chunk after
        // retries. Folded into $degraded once that array exists below, same
        // mechanism as visual-gate failures, so a page missing a section is
        // never reported as a clean success.
        $chunk_degraded_slugs = [];
        foreach ($page_jobs_input as $slug => $info) {
            if ($rebuilder->needsChunking($info['html'])) {
                $chunked_slugs[$slug] = true;
                continue;
            }
            $batch_jobs[$slug] = $rebuilder->preparePageJob(
                $slug,
                $info['html'],
                $info['computed']
            );
        }

        $stage1_pool = min(count($batch_jobs), 6);
        echo "  [Builder] STAGE 1 — StaticRebuilder: " . count($batch_jobs) . " pages via messageBatch (pool {$stage1_pool})...\n";
        $batch_res = empty($batch_jobs)
            ? []
            : $claude->messageBatch($batch_jobs, $stage1_pool, ['cache_prefix' => $rebuilder->cachePrefix()]);
        $this->logBatchStats('static rebuild', $batch_res);

        // Clean each response
        $rebuilt = [];
        foreach ($page_jobs_input as $slug => $info) {
            if (isset($chunked_slugs[$slug])) continue; // handled below
            $r    = $batch_res[$slug] ?? null;
            $text = is_array($r) ? (string) ($r['text'] ?? '') : '';
            if (trim($text) === '') {
                $err = is_array($r) ? (string) ($r['error'] ?? 'empty response') : 'missing result';
                echo "  [Stage1] {$slug}: FAILED ({$err}) — page will use fallback\n";
                // Keep raw scan HTML as fallback body (stripped). v4.4.0: cap
                // raised from 100000 to self::MAX_TEXT_CHARS. This becomes
                // real page content when Stage 1 fails, so it should not be
                // clipped any more tightly than the WXR safety net is.
                $fallback = $rebuilder->pageBodyForPrompt($info['html'], self::MAX_TEXT_CHARS);
                $rebuilt[$slug] = [
                    'title'      => $info['title'],
                    'html'       => $fallback,
                    'screenshot' => $info['screenshot'],
                    'computed'   => $info['computed'],
                ];
                continue;
            }
            $cleaned = $rebuilder->cleanHtml($text, $slug);
            $rebuilt[$slug] = [
                'title'      => $info['title'],
                'html'       => $cleaned,
                'screenshot' => $info['screenshot'],
                'computed'   => $info['computed'],
            ];
            echo "  [Stage1] {$slug}: OK (" . strlen($cleaned) . " bytes)\n";
        }

        // v4.2.0: chunked pages — one rebuildChunked() call each (its own
        // sequence of per-chunk Claude calls), run after the batch so the
        // fast pages aren't held up waiting on the slow oversized ones.
        foreach ($chunked_slugs as $slug => $_) {
            $info = $page_jobs_input[$slug];
            $cleaned = $rebuilder->rebuildChunked($slug, $info['html'], $info['computed']);
            if (trim($cleaned) === '') {
                echo "  [Stage1] {$slug}: chunked rebuild FAILED — page will use fallback\n";
                $cleaned = $rebuilder->pageBodyForPrompt($info['html'], self::MAX_TEXT_CHARS);
            }
            // v4.4.x: rebuildChunked() retries a failed/empty chunk twice before
            // giving up; if a chunk is still missing after that, the page is
            // real but incomplete, so it must not be reported as a clean pass.
            if (isset($rebuilder->incompleteChunkSlugs[$slug])) {
                $chunk_degraded_slugs[$slug] = true;
            }
            $rebuilt[$slug] = [
                'title'      => $info['title'],
                'html'       => $cleaned,
                'screenshot' => $info['screenshot'],
                'computed'   => $info['computed'],
            ];
        }

        // v4.2.4: QA artifacts — dump every Stage 1 page (batch + chunked, already
        // merged into $rebuilt above) to {workDir}/rebuilt/{slug}.html so the raw
        // pre-gate output can be inspected directly, independent of what the gate
        // later keeps/discards.
        $rebuiltQaDir = rtrim($this->workDir, '/') . '/rebuilt';
        if (!is_dir($rebuiltQaDir)) {
            @mkdir($rebuiltQaDir, 0755, true);
        }
        foreach ($rebuilt as $slug => $info) {
            @file_put_contents($rebuiltQaDir . '/' . $slug . '.html', (string) $info['html']);
        }

        // ── STAGE 2: VisualGate (parallel batch) ─────────────────────────────
        $gate      = new VisualGate($this->scannerDir, $this->workDir);
        $validated = [];
        $degraded  = [];
        // Fold in pages that lost a chunk in Stage 1 (see $chunk_degraded_slugs
        // above) before the gate adds its own visual-fail hits below.
        foreach (array_keys($chunk_degraded_slugs) as $slug) {
            $degraded[] = $slug;
        }

        // Build gate input: slug => ['html' => body, 'ref' => screenshot path or '']
        $gate_in = [];
        foreach ($rebuilt as $slug => $info) {
            $gate_in[$slug] = [
                'html' => $info['html'],
                'ref'  => $info['screenshot'] ?? '',
            ];
        }

        // Round 1: parallel gate check
        $r1 = $gate->checkBatch($gate_in, $cssAbs);
        foreach ($r1 as $slug => $result) {
            $verdict  = $result['verdict']  ?? 'ungated';
            $diff_pct = $result['diff_pct'] ?? null;
            $pct_str  = $diff_pct !== null ? round((float) $diff_pct, 1) . '%' : 'n/a';
            echo "  [Gate] {$slug}: {$verdict} diff={$pct_str}\n";
        }

        // Collect slugs that need retry (review or fail).
        // v4.2.6: a FAIL verdict scored against an unreliable render (viewport
        // fallback or a gross height mismatch — see gate-compare.js) does not
        // reflect the actual page quality, so it must not by itself trigger a
        // retry. Reliable fails and any review verdict still retry as before.
        $retry_slugs = [];
        foreach ($r1 as $slug => $result) {
            $v = $result['verdict'] ?? 'ungated';
            $render1 = $result['render'] ?? 'full';
            $hm1     = $result['height_mismatch'] ?? false;
            $unreliable1 = ($render1 === 'viewport' || $hm1 === true);
            if ($v === 'fail' && $unreliable1) {
                continue;
            }
            if ($v === 'review' || $v === 'fail') {
                $retry_slugs[] = $slug;
            }
        }

        // Retry round: build jobs for all failing/review pages, batch them together.
        // v4.2.4: chunked-page retries must NOT go through preparePageJob (that's the
        // capped single-call path that substr()-truncates oversized pages and drops
        // the tail — which is exactly what silently replaced the complete chunked
        // home page with a truncated one in build 423). Chunked slugs are re-run
        // through rebuildChunked() again instead, with the fidelity note appended to
        // every chunk's prompt. Non-chunked slugs keep the original batch retry path.
        $r2 = [];
        $retry_htmls = [];
        if (!empty($retry_slugs)) {
            $retry_jobs = [];
            $retry_notes = [];
            foreach ($retry_slugs as $slug) {
                $diff_pct = $r1[$slug]['diff_pct'] ?? null;
                $pct_str  = $diff_pct !== null ? round((float) $diff_pct, 1) . '%' : 'n/a';
                $note     = "Your previous transcription differed from the original by {$pct_str}. "
                          . "Transcribe MORE faithfully — exact structure, classes, and text.";
                $retry_notes[$slug] = $note;

                if (isset($chunked_slugs[$slug])) continue; // handled via rebuildChunked below

                $info = $rebuilt[$slug];
                $job  = $rebuilder->preparePageJob($slug, $page_jobs_input[$slug]['html'], $info['computed']);
                $job['user'] .= "\n\n" . $note;
                $retry_jobs[$slug] = $job;
            }

            if (!empty($retry_jobs)) {
                $retry_pool = min(count($retry_jobs), 6);
                $retry_res  = $claude->messageBatch($retry_jobs, $retry_pool, ['cache_prefix' => $rebuilder->cachePrefix()]);
                $this->logBatchStats('gate retry batch', $retry_res);

                foreach (array_keys($retry_jobs) as $slug) {
                    $info       = $rebuilt[$slug];
                    $retry_text = is_array($retry_res[$slug] ?? null) ? (string) ($retry_res[$slug]['text'] ?? '') : '';
                    $retry_htmls[$slug] = $retry_text !== '' ? $rebuilder->cleanHtml($retry_text, $slug) : $info['html'];
                }
            }

            // Chunked slugs: re-run the full chunked rebuild with the fidelity note
            // threaded into every chunk prompt, so the retry keeps full page coverage
            // instead of falling back to the capped single-call path.
            foreach ($retry_slugs as $slug) {
                if (!isset($chunked_slugs[$slug])) continue;
                $info    = $page_jobs_input[$slug];
                $cleaned = $rebuilder->rebuildChunked($slug, $info['html'], $info['computed'], $retry_notes[$slug]);
                $retry_htmls[$slug] = trim($cleaned) !== '' ? $cleaned : $rebuilt[$slug]['html'];
                // v4.4.x: this rebuildChunked() call overwrites
                // incompleteChunkSlugs[$slug] with the retry's own outcome.
                // If the retry recovered the missing chunk the entry is gone
                // and we do not flag degraded; if it is still missing (or
                // missing differently), flag it here too.
                if (isset($rebuilder->incompleteChunkSlugs[$slug]) && !in_array($slug, $degraded, true)) {
                    $degraded[] = $slug;
                }
            }

            // Round 2: parallel gate check on retried pages
            $retry_gate_in = [];
            foreach ($retry_slugs as $slug) {
                $retry_gate_in[$slug] = [
                    'html' => $retry_htmls[$slug],
                    'ref'  => $rebuilt[$slug]['screenshot'] ?? '',
                ];
            }
            $r2 = $gate->checkBatch($retry_gate_in, $cssAbs);
            foreach ($r2 as $slug => $result) {
                $verdict2 = $result['verdict']  ?? 'ungated';
                $diff2    = $result['diff_pct'] ?? null;
                $pct2_str = $diff2 !== null ? round((float) $diff2, 1) . '%' : 'n/a';
                echo "  [Gate] {$slug} retry: {$verdict2} diff={$pct2_str}\n";
            }
        }

        // Resolve best attempt for every page and build $validated
        foreach ($rebuilt as $slug => $info) {
            $v1       = $r1[$slug]['verdict']  ?? 'ungated';
            $diff1    = $r1[$slug]['diff_pct'] ?? null;
            $render1  = $r1[$slug]['render'] ?? 'full';
            $hm1      = $r1[$slug]['height_mismatch'] ?? false;
            $unreliable1 = ($render1 === 'viewport' || $hm1 === true);

            if ($v1 === 'pass' || $v1 === 'ungated') {
                // Passed first time — no retry needed
                $validated[$slug] = ['title' => $info['title'], 'html' => $info['html']];
                continue;
            }

            // Had a retry — pick whichever has lower diff_pct
            $v2       = $r2[$slug]['verdict']  ?? $v1;
            $diff2    = $r2[$slug]['diff_pct'] ?? null;
            $has_r2   = isset($r2[$slug]);
            $render2  = $r2[$slug]['render'] ?? 'full';
            $hm2      = $r2[$slug]['height_mismatch'] ?? false;
            $unreliable2 = $has_r2 ? ($render2 === 'viewport' || $hm2 === true) : $unreliable1;
            $use_retry = ($diff2 !== null && $diff1 !== null && (float) $diff2 < (float) $diff1)
                      || ($diff1 === null && isset($retry_htmls[$slug]));

            // v4.2.6: reliability guard. A viewport-fallback or height-mismatch
            // render (see gate-compare.js) produces a diff_pct that does not
            // reflect real page quality, so it must never be allowed to
            // override or replace a reliable result. A reliable candidate
            // always wins over an unreliable one; if both candidates are
            // unreliable, fall back to whichever HTML is longer as a proxy
            // for completeness.
            if (isset($retry_htmls[$slug])) {
                if ($unreliable1 && !$unreliable2) {
                    $use_retry = true;
                } elseif (!$unreliable1 && $unreliable2) {
                    $use_retry = false;
                } elseif ($unreliable1 && $unreliable2) {
                    $use_retry = strlen($retry_htmls[$slug]) > strlen($info['html']);
                }
            }

            // v4.2.4: completeness guard. A truncated candidate can score a lower
            // gate diff than the complete one purely because it only covers the
            // top of the page (the fail=45.1%/pass=2.3% pattern from build 423).
            // If one candidate is under 60% the length of the other, treat the
            // longer one as the only valid candidate and skip the diff-based
            // choice, unless the longer one is empty.
            // v4.2.6: unchanged — this remains the final backstop check
            // regardless of the reliability guard above.
            if (isset($retry_htmls[$slug])) {
                $round1_html = $info['html'];
                $retry_html  = $retry_htmls[$slug];
                $len1 = strlen($round1_html);
                $len2 = strlen($retry_html);
                $longer_is_round1 = $len1 >= $len2;
                $longer_len  = $longer_is_round1 ? $len1 : $len2;
                $shorter_len = $longer_is_round1 ? $len2 : $len1;
                $longer_html = $longer_is_round1 ? $round1_html : $retry_html;

                if ($longer_len > 0 && $shorter_len < (0.6 * $longer_len)) {
                    $use_retry = !$longer_is_round1;
                    $best_html_override = $longer_html;
                    echo "  [Gate] {$slug}: completeness guard kept the longer candidate\n";
                } else {
                    $best_html_override = null;
                }
            } else {
                $best_html_override = null;
            }

            $best_html    = $best_html_override ?? (($use_retry && isset($retry_htmls[$slug])) ? $retry_htmls[$slug] : $info['html']);
            $best_verdict = $use_retry ? $v2 : $v1;
            $best_unreliable = $use_retry ? $unreliable2 : $unreliable1;

            if ($best_verdict === 'fail' && $best_unreliable) {
                echo "  [Gate] {$slug}: unreliable render (viewport fallback or height mismatch), diff ignored\n";
            } elseif ($best_verdict === 'fail') {
                // Slug may already be flagged degraded above (lost chunk); do
                // not double-list it.
                if (!in_array($slug, $degraded, true)) {
                    $degraded[] = $slug;
                }
                echo "  [Gate] {$slug}: final verdict FAIL — kept best HTML, flagged degraded\n";
            }
            $validated[$slug] = ['title' => $info['title'], 'html' => $best_html];
        }

        if (!empty($degraded)) {
            echo "  [Gate] Degraded pages (" . count($degraded) . "): " . implode(', ', $degraded) . "\n";
        }

        // ── STAGE 2.5: Image src import + rewrite ────────────────────────────
        // Collect all <img> srcs from validated page bodies + the logo, import
        // any not yet in media_map, then rewrite src attributes to use local
        // assets/images/ paths (falling back to absolute URL on import failure).
        {
            $live_url_parsed = @parse_url((string) ($this->conversion['live_url'] ?? ''));
            $live_scheme_host = '';
            if (!empty($live_url_parsed['host'])) {
                $live_scheme_host = (string) ($live_url_parsed['scheme'] ?? 'https') . '://' . $live_url_parsed['host'];
                if (!empty($live_url_parsed['port'])) {
                    $live_scheme_host .= ':' . $live_url_parsed['port'];
                }
            }
            $live_url_base = rtrim((string) ($this->conversion['live_url'] ?? ''), '/');

            // Helper: resolve a src to an absolute URL.
            $resolve = static function (string $src) use ($live_scheme_host, $live_url_base): string {
                $src = trim($src);
                if ($src === '' || strncmp($src, 'data:', 5) === 0) {
                    return '';
                }
                if (strncmp($src, 'http://', 7) === 0 || strncmp($src, 'https://', 8) === 0) {
                    return $src;
                }
                if (strncmp($src, '//', 2) === 0) {
                    return 'https:' . $src;
                }
                if ($src[0] === '/' && $live_scheme_host !== '') {
                    return $live_scheme_host . $src;
                }
                if ($live_url_base !== '') {
                    return $live_url_base . '/' . ltrim($src, '/');
                }
                return '';
            };

            // Collect all src values across every validated page body.
            $all_srcs = []; // original_src => resolved_absolute
            foreach ($validated as $slug => $vdata) {
                if (preg_match_all('/<img\b[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $vdata['html'], $img_matches)) {
                    foreach ($img_matches[1] as $raw_src) {
                        $abs = $resolve($raw_src);
                        if ($abs !== '') {
                            $all_srcs[$raw_src] = $abs;
                        }
                    }
                }
            }
            // Also ensure the logo is included.
            $logo_src_raw = (string) ($this->analysis['live_scan']['logo']['src'] ?? '');
            if ($logo_src_raw !== '') {
                $logo_abs = $resolve($logo_src_raw);
                if ($logo_abs !== '') {
                    $all_srcs[$logo_src_raw] = $logo_abs;
                }
            }

            // Find which resolved URLs are not yet in media_map.
            $existing_map  = $this->analysis['media_map'] ?? [];
            $urls_to_fetch = [];
            foreach ($all_srcs as $raw => $abs) {
                if (!isset($existing_map[$raw]) && !isset($existing_map[$abs])) {
                    $urls_to_fetch[] = $abs;
                }
            }
            $urls_to_fetch = array_values(array_unique($urls_to_fetch));

            // Import missing images (cap at self::MAX_IMAGES total across both passes).
            $remaining_cap = max(0, self::MAX_IMAGES - count($existing_map));
            if (!empty($urls_to_fetch) && $remaining_cap > 0) {
                $importer2 = new MediaImporter($this->themeDir() . '/assets/images');
                $auth_url2 = (string) ($this->conversion['live_url'] ?? '');
                if ($auth_url2 !== '' && (string) parse_url($auth_url2, PHP_URL_QUERY) !== '') {
                    $importer2->setAuthPassthrough($auth_url2);
                }
                $res2 = $importer2->import($urls_to_fetch, $remaining_cap);
                // Merge new results into media_map (keyed by absolute URL).
                foreach ($res2['by_url'] as $abs_url => $local_path) {
                    $this->analysis['media_map'][$abs_url] = $local_path;
                }
                echo "  [Media] Stage-2.5 import: " . count($res2['files']) . " new images, "
                   . count($res2['skipped'] ?? []) . " skipped\n";
            }

            // Rewrite <img src="..."> in every validated page body.
            $map          = $this->analysis['media_map'] ?? [];
            $count_total  = 0;
            $count_local  = 0;
            $count_hotlink = 0;
            foreach ($validated as $slug => $vdata) {
                $body = $vdata['html'];
                $body = (string) preg_replace_callback(
                    '/<img(\b[^>]*)>/i',
                    static function (array $m) use ($all_srcs, $map, $resolve, &$count_total, &$count_local, &$count_hotlink): string {
                        $attrs = $m[1];
                        // Extract src value and its quote char.
                        if (!preg_match('/\bsrc=(["\'])([^"\']+)\1/i', $attrs, $sm)) {
                            return '<img' . $attrs . '>';
                        }
                        $quote   = $sm[1];
                        $raw_src = $sm[2];
                        $abs     = $all_srcs[$raw_src] ?? $resolve($raw_src);
                        $count_total++;

                        // Look up in map by raw src first, then by absolute URL.
                        $local_path = $map[$raw_src] ?? $map[$abs] ?? '';
                        if ($local_path !== '') {
                            $new_src = '%THEMEURI%/assets/images/' . basename($local_path);
                            $count_local++;
                        } else {
                            // Hotlink fallback: use resolved absolute URL.
                            $new_src = ($abs !== '') ? $abs : $raw_src;
                            $count_hotlink++;
                        }
                        $new_attrs = preg_replace('/\bsrc=["\'][^"\']+["\']/', 'src=' . $quote . $new_src . $quote, $attrs, 1);

                        // Best-effort srcset rewrite: replace just the first URL token.
                        if (preg_match('/\bsrcset=(["\'])([^"\']+)\1/i', (string) $new_attrs, $ss)) {
                            $ss_val = $ss[2];
                            $ss_val = (string) preg_replace_callback(
                                '/^(\s*)(\S+)/',
                                static function (array $ssm) use ($map, $resolve): string {
                                    $ss_src = $ssm[2];
                                    $ss_abs = $resolve($ss_src);
                                    $ss_local = $map[$ss_src] ?? $map[$ss_abs] ?? '';
                                    if ($ss_local !== '') {
                                        return $ssm[1] . '%THEMEURI%/assets/images/' . basename($ss_local);
                                    }
                                    return $ssm[1] . ($ss_abs !== '' ? $ss_abs : $ss_src);
                                },
                                $ss_val
                            );
                            $new_attrs = preg_replace('/\bsrcset=["\'][^"\']+["\']/', 'srcset=' . $ss[1] . $ss_val . $ss[1], (string) $new_attrs, 1);
                        }

                        return '<img' . $new_attrs . '>';
                    },
                    $body
                );
                $validated[$slug]['html'] = $body;
            }

            error_log("[Builder] Image rewrite: {$count_total} srcs across pages, {$count_local} imported, {$count_hotlink} hotlinked");
            echo "  [Media] Image rewrite: {$count_total} srcs, {$count_local} local, {$count_hotlink} hotlinked\n";
        }

        // ── STAGE 3: Deterministic templatization ─────────────────────────────
        $this->setStage('layout');

        echo "  [Builder] STAGE 3 — Deterministic templatization\n";

        // header.php — deterministic from scanned nav model
        $scan_nav_model = $this->analysis['live_scan']['nav'] ?? [];
        if (!empty($scan_nav_model)) {
            $labels = array_map(fn($n) => (string) ($n['text'] ?? ''), $scan_nav_model);
            echo "  [Builder] Generating header.php — faithful nav (" . count($labels) . " items): " . implode(' | ', $labels) . "\n";
        } else {
            echo "  [Builder] *** HARD WARNING: scanned nav model is EMPTY — header has no faithful nav source. Emitting shell. ***\n";
        }
        $this->files['header.php'] = $det->generateHeader();

        // footer.php, functions.php
        $this->setStage('functions');
        echo "  [Builder] Generating functions.php\n";
        $this->files['functions.php'] = $det->generateFunctions();
        echo "  [Builder] Generating footer.php\n";
        $this->files['footer.php'] = $det->generateFooter();

        // front-page.php — inline validated home HTML
        if (isset($validated['home'])) {
            echo "  [Builder] Generating front-page.php from validated home HTML\n";
            $this->files['front-page.php'] = $det->generateFrontPageFromHtml($validated['home']['html']);
        } else {
            echo "  [Builder] Generating front-page.php (fallback — no validated home HTML)\n";
            $this->files['front-page.php'] = $det->generateFrontPage();
        }

        // Inner page templates
        $page_contents = []; // slug => ['title', 'content'] for WXR
        foreach ($validated as $slug => $vdata) {
            if ($slug === 'home') continue;
            $title = $vdata['title'];
            $html  = $vdata['html'];
            $this->files["page-{$slug}.php"] = $det->generatePageFromHtml($slug, $title, $html);
            echo "  [Builder]   → page-{$slug}.php created\n";

            // WXR content: use the validated HTML
            $wxr_content = $html;
            if (DeterministicGen::isJsxSource($wxr_content)) $wxr_content = '';
            if (strlen($wxr_content) > self::MAX_TEXT_CHARS) {
                $orig_len = strlen($wxr_content);
                echo "  [Builder]   WARNING: WXR content for page-{$slug} truncated from {$orig_len} to "
                   . self::MAX_TEXT_CHARS . " bytes\n";
                $wxr_content = substr($wxr_content, 0, self::MAX_TEXT_CHARS);
            }
            $page_contents[$slug] = [
                'title'   => $title,
                'content' => trim($wxr_content),
            ];
        }

        // v4.3.0 Item 6: Nav 404 guard. When the page cap dropped real content
        // pages, any primary nav item whose href resolves to a dropped slug would
        // produce a 404. Create a minimal stub page-{slug}.php for each such slug
        // so the nav link lands on an actual WP page instead of a 404. The stub
        // carries the page title as an <h1> and a "coming soon" notice — it is
        // far better than a broken link and cheap to replace with real content later.
        $dropped_slugs = $this->analysis['_dropped_slugs'] ?? [];
        if (!empty($dropped_slugs)) {
            $nav_targets = [];
            foreach (($this->analysis['live_scan']['nav'] ?? []) as $n) {
                $target = trim((string) ($n['target'] ?? ''));
                $target_slug = ltrim(preg_replace('#^/#', '', $target), '/');
                $target_slug = strtolower(trim($target_slug, '/'));
                if ($target_slug !== '') {
                    $nav_targets[$target_slug] = true;
                }
            }
            foreach ($dropped_slugs as $dslug) {
                $norm_dslug = strtolower(trim($dslug, '/'));
                if (isset($nav_targets[$norm_dslug]) && !isset($this->files["page-{$dslug}.php"])) {
                    $stub_title = ucwords(str_replace('-', ' ', $dslug));
                    $stub_html  = "<h1>{$stub_title}</h1><p>This page is coming soon.</p>";
                    $this->files["page-{$dslug}.php"] = $det->generatePageFromHtml($dslug, $stub_title, $stub_html);
                    $page_contents[$dslug] = ['title' => $stub_title, 'content' => $stub_html];
                    echo "  [Builder]   → page-{$dslug}.php stub created (nav link guard — page was cap-dropped)\n";
                }
            }
        }

        // skeleton files
        echo "  [Builder] Generating skeleton files\n";
        $this->files = array_merge($this->files, $det->generateSkeletonFiles());
        $this->files['inc/required-plugins.php'] = $det->generateRequiredPlugins();
        $this->files['inc/demo-content.php']     = $det->generateDemoContent();

        echo "\n  [Builder] Deterministic: " . count($this->files) . " files\n\n";

        // ── STAGE 4: WooCommerce product extraction ───────────────────────────
        $has_woo = !empty($this->analysis['has_woocommerce']);
        $raw_count = count($this->analysis['products'] ?? []);
        $products  = $this->filterProducts($this->analysis['products'] ?? []);
        if ($raw_count !== count($products)) {
            echo "  [Builder] Product junk filter: {$raw_count} candidates → " . count($products) . " kept\n";
        }

        if ($has_woo) {
            $this->setStage('woocommerce');
            echo "  [Builder] WooCommerce detected\n";

            // Combine all rendered HTML for product extraction.
            $all_html = $this->analysis['rendered_html'] ?? '';
            foreach (($this->analysis['sub_pages'] ?? []) as $slug => $page_data) {
                $all_html .= "\n<!-- PAGE: {$slug} -->\n" . ($page_data['html'] ?? '');
            }
            foreach ($scan_page_set as $slug => $entry) {
                // v4.4.0: cap raised from 60000 to self::MAX_TEXT_CHARS. This
                // truncation fed directly into extractProductsWithClaude()'s
                // combined HTML: at 60000 chars per page it could clip
                // products out of large scanned pages before the extractor
                // ever saw them, regardless of the chunked extraction fix.
                $body = $rebuilder->pageBodyForPrompt((string) ($entry['_rendered_html'] ?? ''), self::MAX_TEXT_CHARS);
                $all_html .= "\n<!-- SCANNED PAGE: {$slug} -->\n" . $body;
            }

            if (count($products) < $this->product_limit) {
                echo "  [Builder] Extracting products from rendered HTML via Claude...\n";
                $claude_products = $this->filterProducts($this->extractProductsWithClaude($claude, $all_html));

                $existing_names = array_map(fn($p) => strtolower($p['name'] ?? ''), $products);
                foreach ($claude_products as $cp) {
                    if (count($products) >= $this->product_limit) break;
                    if (!in_array(strtolower($cp['name'] ?? ''), $existing_names)) {
                        $products[] = $cp;
                        $existing_names[] = strtolower($cp['name'] ?? '');
                    }
                }
            }

            if ($this->product_limit > 0 && count($products) > $this->product_limit) {
                $products = array_slice($products, 0, $this->product_limit);
            }
            $this->analysis['products'] = $products;

            if (!empty($products)) {
                echo "  [Builder] Total products: " . count($products) . "\n";
                $det2 = new DeterministicGen($this->prefix, $this->analysis, $page_contents);
                $this->files['inc/demo-content.php'] = $det2->generateDemoContent();
                $csv = $this->generateProductCSV($products);
                $this->files['inc/product-import.csv'] = $csv;
                echo "  [Builder] Generated product-import.csv\n";
            }
        }

        // ── STAGE 5: Assembling ───────────────────────────────────────────────
        $this->setStage('assembling');
        echo "  [Builder] Generating WXR import file...\n";
        $wxr = $this->generateWXR($page_contents, $products);
        $this->files['inc/content-import.xml'] = $wxr;
        echo "  [Builder] WXR import: " . count($page_contents) . " pages, " . count($products) . " products\n";

        $det_final = new DeterministicGen($this->prefix, $this->analysis, $page_contents);
        $this->files['inc/demo-content.php'] = $det_final->generateDemoContent();
        echo "  [Builder] Final demo-content.php regenerated with page content\n";

        $this->files['INSTALL.txt'] = $this->generateInstallInstructions($has_woo, count($page_contents), count($products));

        // ── Stage 6: Package ──────────────────────────────────────────────────
        $this->setStage('packaging');
        echo "  [Builder] Packaging ZIP...\n";
        $zip_path = $this->package();
        echo "  [Builder] ZIP: {$zip_path}\n";

        // Token/call accounting — ClaudeClient is the authoritative counter
        // (every batch page job + retries + product extraction route through it).
        $token_usage    = $claude->getTokenUsage();
        $total_calls    = $claude->getCalls();
        echo "  [Builder] AI calls total: {$total_calls}\n";
        echo "  [Builder] Tokens: in=" . ($token_usage['input'] ?? 0)
           . " out=" . ($token_usage['output'] ?? 0)
           . " cache_creation=" . ($token_usage['cache_creation'] ?? 0)
           . " cache_read=" . ($token_usage['cache_read'] ?? 0) . "\n";

        return [
            'files'                 => $this->files,
            'zip_path'              => $zip_path,
            'ai_calls'              => $total_calls,
            'tokens_input'          => $token_usage['input']          ?? 0,
            'tokens_output'         => $token_usage['output']         ?? 0,
            'tokens_cache_creation' => $token_usage['cache_creation'] ?? 0,
            'tokens_cache_read'     => $token_usage['cache_read']     ?? 0,
            'degraded'              => $degraded,
        ];
    }

    // ─── Bundle loading ──────────────────────────────────────────────────────

    private function loadBundle(string $type): string
    {
        $uuid = $this->conversion['uuid'] ?? '';
        if (empty($uuid)) return '';

        $cache_dir = rtrim((string) config('cache_dir', ''), '/') . '/scraped/' . $uuid;
        $ext = $type === 'js' ? 'js' : 'css';

        // Try bundle-0 first
        $path = $cache_dir . '/bundles/bundle-0.' . $ext;
        if (file_exists($path)) {
            $content = file_get_contents($path);
            if ($content !== false) {
                echo "  [Builder] Loaded {$type} bundle: " . round(strlen($content) / 1024, 1) . "KB\n";
                return $content;
            }
        }

        // Try css/bundle.css
        if ($type === 'css') {
            $path = $cache_dir . '/css/bundle.css';
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) return $content;
            }
        }

        echo "  [Builder] No {$type} bundle found in cache\n";
        return '';
    }

    /**
     * Find Layout.jsx in the zip-parsed data or scraped data.
     */
    private function findLayoutSource(): string
    {
        // Check if we stored scraped data with the conversion
        $scraped_json = $this->conversion['scraped_data'] ?? '';
        if (!empty($scraped_json)) {
            $scraped = json_decode($scraped_json, true);
            // Layout source might be in the analysis
        }

        // Check the analysis for layout component source
        $source = $this->analysis['section_source']['layout'] ?? '';
        if ($source) return $source;

        // Try to read from the zip if it was uploaded
        $zip_path = $this->conversion['source_zip_path'] ?? '';
        if ($zip_path && file_exists($zip_path)) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (preg_match('/Layout\.(?:jsx|tsx)$/i', $name)) {
                        $content = $zip->getFromIndex($i);
                        $zip->close();
                        if ($content !== false) {
                            echo "  [Builder] Found Layout.jsx in zip\n";
                            return $content;
                        }
                    }
                }
                $zip->close();
            }
        }

        return '';
    }

    // ─── v3.0.0: theme output dir ────────────────────────────────────────────

    /** Theme output directory — shared by importMedia() and package(). */
    private function themeDir(): string
    {
        $uuid = $this->conversion['uuid'] ?? 'test';
        return $this->output_base . '/' . $uuid . '/theme/' . $this->theme_slug . '-theme';
    }

    // ─── v3.0.0: Media import (Fred-side image download) ─────────────────────

    /**
     * Collect the URL union of (a) every manifest page's images[] + bg_images[]
     * and (b) the claim's analysis images, download them into the theme's
     * assets/images dir via MediaImporter, and return the prompt map block.
     */
    private function importMedia(): string
    {
        $urls = [];
        foreach (($this->analysis['site_scan']['pages'] ?? []) as $pg) {
            foreach (($pg['images'] ?? []) as $im) {
                if (!empty($im['src'])) $urls[] = (string) $im['src'];
            }
            foreach (($pg['bg_images'] ?? []) as $im) {
                if (!empty($im['url'])) $urls[] = (string) $im['url'];
            }
        }
        foreach (($this->analysis['images'] ?? []) as $im) {
            if (is_array($im) && !empty($im['url'])) $urls[] = (string) $im['url'];
        }
        // v3.1.0: ensure the scanned site logo is imported (header points at it)
        $logo_src = $this->analysis['live_scan']['logo']['src'] ?? '';
        if (is_string($logo_src) && $logo_src !== '') {
            array_unshift($urls, $logo_src);
        }
        if (empty($urls)) {
            echo "  [Media] No image URLs collected — skipping media import\n";
            return '';
        }

        $importer = new MediaImporter($this->themeDir() . '/assets/images');
        // v3.2.1: token-gated preview origins (unpublished Lovable projects) —
        // re-apply the conversion live URL's auth query (?__lovable_token=...)
        // to same-origin asset downloads; foreign-origin CDN URLs untouched.
        $auth_url = (string) ($this->conversion['live_url'] ?? '');
        if ($auth_url !== '' && (string) parse_url($auth_url, PHP_URL_QUERY) !== '') {
            $importer->setAuthPassthrough($auth_url);
            echo "  [Media] Auth query passthrough enabled for "
               . (string) (parse_url($auth_url, PHP_URL_HOST) ?: '?') . "\n";
        }
        $res = $importer->import($urls, 150);
        $this->media_by_url = $res['by_url'];
        // v3.1.0: expose the real url→local-path map to DeterministicGen
        // (faithful header logo + any deterministic asset rewriting).
        $this->analysis['media_map'] = $res['by_url'];
        echo "  [Media] Imported " . count($res['files']) . " images ("
           . round(($res['bytes_total'] ?? 0) / 1048576, 2) . "MB), skipped "
           . count($res['skipped'] ?? []) . " (from " . count($urls) . " collected URLs)\n";

        return count($res['by_url']) > 0 ? $importer->promptMap($res['by_url'], 60) : '';
    }

    // ─── Scan page helpers ────────────────────────────────────────────────────

    /** Read one manifest page's rendered DOM from the scan dir. */
    private function readScanHtml(array $entry): string
    {
        $dir = (string) ($this->analysis['site_scan_dir'] ?? '');
        $rel = (string) ($entry['html_file'] ?? '');
        if ($dir === '' || $rel === '') return '';
        $path = $dir . '/' . $rel;
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * Resolve the absolute path to a manifest page's reference screenshot.
     * Uses the same site_scan_dir resolution as readScanHtml().
     * Returns null if the file does not exist (VisualGate will return 'ungated').
     */
    private function resolveScreenshot(array $entry): ?string
    {
        $dir = (string) ($this->analysis['site_scan_dir'] ?? '');
        $rel = (string) ($entry['screenshot_file'] ?? '');
        if ($dir === '' || $rel === '') return null;
        $path = $dir . '/' . $rel;
        return is_file($path) ? $path : null;
    }

    /**
     * Resolve the sub-page set to convert: manifest pages ∩ claim sub_pages
     * (claim = authority for what was selected/paid; manifest = authority for
     * content). Identical detail pages (same bytes_html + h1) deduped; pages
     * with <2KB of body text after tag-strip skipped; cap self::MAX_PAGES
     * sub-pages (+ home = MAX_PAGES + 1 total, currently 201). This is a
     * generous safety net against pathological input, not a working budget.
     * v4.4.0 raised it from 24 (+home = 25 total). Any slug still dropped by
     * the cap is tracked in $dropped_slugs and gets a nav 404 stub. Returns
     * [claim_slug => manifest entry + _rendered_html + _claim_title].
     */
    private function resolveScanPageSet(): array
    {
        $manifest_pages = $this->analysis['site_scan']['pages'] ?? [];
        if (empty($manifest_pages)) return [];

        $norm = fn(string $s): string => preg_replace('/[^a-z0-9]/', '', strtolower($s));

        // Optional title enrichment from the zip analysis (when present).
        $sub_titles = [];
        foreach (($this->analysis['sub_pages'] ?? []) as $sslug => $pd) {
            $sub_titles[$norm((string) $sslug)] = trim((string) (is_array($pd) ? ($pd['title'] ?? '') : ''));
        }

        // v4.0.0: source inner pages from the SITE SCAN directly (live-scan
        // driven). The old path iterated $analysis['sub_pages'] (zip analysis),
        // which is empty/mismatched for many jobs — so scanned pages like
        // 'shop'/'about' were silently dropped. Seed the home signature first so
        // duplicate home captures ('home-2', trailing-slash variants) dedupe out.
        $set = [];
        $seen_sig = [];
        foreach ($manifest_pages as $pg) {
            if (($pg['slug'] ?? '') === 'home') {
                $home_sig = (int) ($pg['bytes_html'] ?? 0) . '|' . trim((string) ($pg['h1'] ?? ''));
                $seen_sig[$home_sig] = 'home';
                break;
            }
        }

        $dropped_slugs = []; // v4.3.0 Item 6: track slugs dropped by cap for nav 404 guard
        foreach ($manifest_pages as $pg) {
            $slug = (string) ($pg['slug'] ?? '');
            if ($slug === '' || $slug === 'home') continue;
            if (count($set) >= self::MAX_PAGES) {
                // Only track real content pages (not skippable/detail/product slugs)
                if (!DeterministicGen::isSkippableSlug($slug)
                    && !preg_match('/(detail|article)$/', $norm($slug))
                    && !preg_match('#^product[-_/]#i', $slug)
                ) {
                    $dropped_slugs[] = $slug;
                }
                continue;
            }
            if (DeterministicGen::isSkippableSlug($slug)) {
                echo "  [Builder]   Page: {$slug} skipped (auth/utility/route-param page)\n";
                continue;
            }
            // Per-item detail templates (productDetail, songDetail, blogArticle…)
            // are not standalone content pages — WooCommerce/loops render them.
            if (preg_match('/(detail|article)$/', $norm($slug))) {
                echo "  [Builder]   Page: {$slug} skipped (per-item detail template)\n";
                continue;
            }
            // Per-item product pages (product-slug, product_slug, product/slug) must
            // NOT become static page-{slug}.php — WooCommerce renders these from the
            // WXR import + single-product template. The catalogue page 'shop' is kept.
            if (preg_match('#^product[-_/]#i', $slug)) {
                echo "  [Builder]   Page: {$slug} skipped (per-item product page — WooCommerce renders these)\n";
                continue;
            }
            $sig = (int) ($pg['bytes_html'] ?? 0) . '|' . trim((string) ($pg['h1'] ?? ''));
            if (isset($seen_sig[$sig])) {
                echo "  [Builder]   Page: {$slug} dropped (duplicate of '{$seen_sig[$sig]}': same bytes_html + h1)\n";
                continue;
            }
            $html = $this->readScanHtml($pg);
            if ($html === '') {
                echo "  [Builder]   Page: {$slug} skipped (scan DOM file missing)\n";
                continue;
            }
            // Effectively-empty gate: script/style/chrome-stripped body MARKUP
            // < 2KB. (Pure text length is the wrong signal on Base44 sites —
            // visually rich pages like a 10-product shop carry <1KB of text.)
            $body_markup = preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $_bm) ? $_bm[1] : $html;
            $body_markup = (string) preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', '', $body_markup);
            $body_markup = (string) preg_replace('/<(header|nav|footer)\b[^>]*>.*?<\/\1>/is', '', $body_markup);
            $body_markup = trim($body_markup);
            if (strlen($body_markup) < 2048) {
                echo "  [Builder]   Page: {$slug} skipped (effectively empty: " . strlen($body_markup) . "B body markup < 2KB)\n";
                continue;
            }
            $seen_sig[$sig] = $slug;
            $pg['_rendered_html'] = $html;
            $title = $sub_titles[$norm($slug)] ?? '';
            if ($title === '') $title = trim((string) ($pg['h1'] ?? ''));
            if ($title === '') $title = trim((string) ($pg['title'] ?? ''));
            if ($title === '') $title = ucwords(str_replace('-', ' ', $slug));
            $pg['_claim_title'] = $title;
            $set[$slug] = $pg;
        }

        // v4.3.0 Item 6: log a warning when the cap dropped real content pages,
        // and store the dropped slugs so the build can create stub pages for any
        // that appear in the primary nav (prevents 404s on nav links).
        if (!empty($dropped_slugs)) {
            echo "  [Builder]   WARNING: Page cap (" . self::MAX_PAGES . ") dropped " . count($dropped_slugs)
               . " page(s): " . implode(', ', $dropped_slugs) . "\n";
            $this->analysis['_dropped_slugs'] = $dropped_slugs;
        }

        return $set;
    }


    /** Per-batch token/cache/error summary line. */
    private function logBatchStats(string $label, array $results): void
    {
        if (empty($results)) return;
        $in = $out = $cc = $cr = $errs = 0;
        foreach ($results as $r) {
            $in   += (int) ($r['tokens_in'] ?? 0);
            $out  += (int) ($r['tokens_out'] ?? 0);
            $cc   += (int) ($r['cache_creation'] ?? 0);
            $cr   += (int) ($r['cache_read'] ?? 0);
            if (($r['error'] ?? null) !== null) $errs++;
        }
        echo "  [Batch] {$label}: " . count($results) . " jobs, in={$in} out={$out} "
           . "cache_creation={$cc} cache_read={$cr} errors={$errs}\n";
    }

    // ─── CSS Bundle Cleaning ─────────────────────────────────────────────────

    private function cleanCssBundle(string $css): string
    {
        // shadcn/Tailwind compilation produces destructive global * rules:
        //   *{padding-left:1.75rem}  ← destroys all layout
        //   *{border-color:hsl(var(--border));...}  ← adds borders everywhere
        // Strip these but keep the safe reset: *,::before,::after{box-sizing:...}

        // Remove any *{...} that contains padding, margin, or border-color with hsl(var
        $css = preg_replace('/(?<![a-zA-Z0-9_.\-#\[\]>~+: ])\*\s*\{[^}]*?padding[^}]*\}/', '/* stripped */', $css);
        $css = preg_replace('/(?<![a-zA-Z0-9_.\-#\[\]>~+: ])\*\s*\{[^}]*?border-color:\s*hsl\(var\(--border\)[^}]*\}/', '/* stripped */', $css);

        echo "  [Builder] Stripped destructive global * CSS rules\n";

        // Ensure shadcn CSS variables are defined (Base44 uses shadcn/ui)
        $shadcnVars = ':root{'
            . '--background:0 0% 100%;--foreground:240 10% 3.9%;'
            . '--card:0 0% 100%;--card-foreground:240 10% 3.9%;'
            . '--popover:0 0% 100%;--popover-foreground:240 10% 3.9%;'
            . '--primary:240 5.9% 10%;--primary-foreground:0 0% 98%;'
            . '--secondary:240 4.8% 95.9%;--secondary-foreground:240 5.9% 10%;'
            . '--muted:240 4.8% 95.9%;--muted-foreground:240 3.8% 46.1%;'
            . '--accent:240 4.8% 95.9%;--accent-foreground:240 5.9% 10%;'
            . '--destructive:0 84.2% 60.2%;--destructive-foreground:0 0% 98%;'
            . '--border:240 5.9% 90%;--input:240 5.9% 90%;'
            . '--ring:240 5.9% 10%;--radius:.5rem;'
            . '--sidebar-background:0 0% 98%;--sidebar-foreground:240 5.3% 26.1%;'
            . '--sidebar-primary:240 5.9% 10%;--sidebar-primary-foreground:0 0% 98%;'
            . '--sidebar-accent:240 4.8% 95.9%;--sidebar-accent-foreground:240 5.9% 10%;'
            . '--sidebar-border:220 13% 91%;--sidebar-ring:217.2 91.2% 59.8%'
            . '}';

        return $shadcnVars . "\n" . $css;
    }

    // ─── URL resolution helper ────────────────────────────────────────────────

    /**
     * Resolve $src to an absolute URL using the conversion's live_url as base.
     * Mirrors the Stage-2.5 $resolve closure logic (DRY).
     * Returns '' for data: URIs, empty input, or unresolvable relative paths.
     */
    private function resolveUrl(string $src): string
    {
        $src = trim($src);
        if ($src === '' || strncmp($src, 'data:', 5) === 0) {
            return '';
        }
        if (strncmp($src, 'http://', 7) === 0 || strncmp($src, 'https://', 8) === 0) {
            return $src;
        }

        $live_url_parsed = @parse_url((string) ($this->conversion['live_url'] ?? ''));
        $live_scheme_host = '';
        if (!empty($live_url_parsed['host'])) {
            $live_scheme_host = (string) ($live_url_parsed['scheme'] ?? 'https') . '://' . $live_url_parsed['host'];
            if (!empty($live_url_parsed['port'])) {
                $live_scheme_host .= ':' . $live_url_parsed['port'];
            }
        }
        $live_url_base = rtrim((string) ($this->conversion['live_url'] ?? ''), '/');

        if (strncmp($src, '//', 2) === 0) {
            return 'https:' . $src;
        }
        if ($src[0] === '/' && $live_scheme_host !== '') {
            return $live_scheme_host . $src;
        }
        if ($live_url_base !== '') {
            return $live_url_base . '/' . ltrim($src, '/');
        }
        return '';
    }

    // ─── Product Extraction via Claude ────────────────────────────────────────

    /**
     * v4.4.0: paid-tier under-delivery fix. This used to hardcap output at
     * 25 products no matter what tier the customer paid for
     * ($this->product_limit supports up to woo250 = 250, see
     * bootstrap.php conversion_tier_from_product_count()) AND truncated the
     * input HTML to a flat 150,000 chars before Claude ever saw it, so
     * products living past that offset were invisible regardless of tier.
     * Both are fixed here: the cap is driven by the paid tier, and the
     * input is chunked (not truncated) so no product is ever invisible.
     */
    private function extractProductsWithClaude(ClaudeClient $claude, string $html = ''): array
    {
        if (empty($html)) $html = $this->analysis['rendered_html'] ?? '';
        if (empty($html)) return [];

        $limit = $this->product_limit > 0 ? $this->product_limit : self::DEFAULT_PRODUCT_LIMIT;

        $chunk_cap = 120000;
        $chunks = $this->splitHtmlIntoChunks($html, $chunk_cap);
        $total_chunks = count($chunks);

        $system_template = <<<'PROMPT'
You are an expert at extracting product data from rendered HTML.
Find ALL products/items for sale. Look for product cards, grids, listings, menus with prices, catalog items.
This job's maximum is __LIMIT__ products. Extract every real product you find, up to that maximum.

Return ONLY valid JSON, no markdown, no preamble:
{
    "products": [
        {
            "name": "Product Name",
            "price": "29.99",
            "description": "Short description of the product",
            "category": "Category Name",
            "image": "/products/vial-01.png",
            "sku": ""
        }
    ]
}
If no products found, return: {"products": []}
Prices should be numbers only (no $ sign). Extract real descriptions, not placeholder text.

CRITICAL image rules:
- "image": copy the FULL value of the src attribute from the <img> tag inside the product card.
- If the src starts with / (root-relative), return it exactly as-is, do NOT omit it or change it.
- If the src starts with http:// or https://, return the full URL unchanged.
- If the product card has an <img> tag, you MUST populate the "image" field. Never leave it empty when an image is present.
- Only use "" when the product card genuinely has no image at all.
PROMPT;
        $system = str_replace('__LIMIT__', (string) $limit, $system_template);

        $merged = []; // lower(trim(name)) => product row, deduped across chunks
        $chunk_counts = [];

        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            if (count($merged) >= $limit) {
                echo "  [Builder] Product extraction: limit ({$limit}) already reached, skipping remaining "
                   . ($total_chunks - $i) . " chunk(s)\n";
                break;
            }

            $userMsg = "Extract all products (up to {$limit} total across the whole job) from this website HTML.";
            if ($total_chunks > 1) {
                $userMsg .= " This is part {$n} of {$total_chunks} of the combined page HTML. Extract every product"
                          . " visible in this part; parts are merged and deduplicated afterward.";
            }
            $userMsg .= "\n\n" . $chunk;

            $chunk_products = [];
            try {
                $this->misc_calls++;
                $response = $claude->message($system, $userMsg, ['max_tokens' => 32000]);
                $response = preg_replace('/^```(?:json)?\s*/m', '', $response);
                $response = preg_replace('/\s*```\s*$/m', '', $response);
                $data = json_decode(trim($response), true);
                if (!empty($data['products']) && is_array($data['products'])) {
                    $chunk_products = $data['products'];
                }
            } catch (\Throwable $e) {
                echo "  [Builder] Product extraction chunk {$n}/{$total_chunks} failed: {$e->getMessage()}\n";
            }

            $chunk_counts[$n] = count($chunk_products);
            echo "  [Builder] Product extraction chunk {$n}/{$total_chunks}: " . count($chunk_products) . " product(s)\n";

            foreach ($chunk_products as $cp) {
                if (!is_array($cp)) continue;
                $name = trim((string) ($cp['name'] ?? ''));
                if ($name === '') continue;

                // Resolve each product image to an absolute URL so
                // media_sideload_image() in the importer can fetch it from
                // the live site. Root-relative paths like /products/foo.png
                // are rewritten to https://example.com/products/foo.png.
                $raw_img = trim((string) ($cp['image'] ?? ''));
                if ($raw_img !== '') {
                    $abs = $this->resolveUrl($raw_img);
                    $cp['image'] = ($abs !== '') ? $abs : $raw_img;
                }

                $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
                if (!isset($merged[$key]) || $this->countPopulatedProductFields($cp) > $this->countPopulatedProductFields($merged[$key])) {
                    $merged[$key] = $cp;
                }
            }
        }

        $extracted = array_values($merged);
        if (count($extracted) > $limit) {
            $extracted = array_slice($extracted, 0, $limit);
        }

        $per_chunk_summary = [];
        foreach ($chunk_counts as $n => $c) {
            $per_chunk_summary[] = "part {$n}={$c}";
        }
        echo "  [Builder] Product extraction: {$total_chunks} chunk(s) sent ("
           . implode(', ', $per_chunk_summary) . "), merged total = " . count($extracted)
           . " (limit {$limit})\n";

        return $extracted;
    }

    /**
     * v4.4.0: split a combined HTML string into sequential chunks of
     * roughly $cap characters, breaking on a tag boundary ('>' character)
     * where possible instead of mid-tag. Used by extractProductsWithClaude()
     * so oversized combined HTML is chunked, never substr()-truncated away.
     */
    private function splitHtmlIntoChunks(string $html, int $cap): array
    {
        $len = strlen($html);
        if ($len <= $cap) return [$html];

        $chunks = [];
        $pos = 0;
        while ($pos < $len) {
            $end = min($pos + $cap, $len);
            if ($end < $len) {
                $window = substr($html, $pos, $end - $pos);
                $boundary = strrpos($window, '>');
                // Only honor the boundary if it doesn't shrink the chunk by
                // more than half. Sparse-markup regions could otherwise
                // produce a near-empty chunk.
                if ($boundary !== false && $boundary >= (int) ($cap * 0.5)) {
                    $end = $pos + $boundary + 1;
                }
            }
            $chunks[] = substr($html, $pos, $end - $pos);
            $pos = $end;
        }
        return $chunks;
    }

    /** Count non-empty product fields, used to pick the richer row when merging duplicate names across chunks. */
    private function countPopulatedProductFields(array $p): int
    {
        $count = 0;
        foreach (['name', 'price', 'description', 'category', 'image', 'sku'] as $f) {
            if (trim((string) ($p[$f] ?? '')) !== '') $count++;
        }
        return $count;
    }


    // ─── WXR Import File Generation ──────────────────────────────────────────

    private function generateWXR(array $pages, array $products): string
    {
        $name = htmlspecialchars($this->theme_name);
        $prefix = $this->prefix;

        $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
        $xml .= '<rss version="2.0"' . "\n";
        $xml .= '  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"' . "\n";
        $xml .= '  xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
        $xml .= '  xmlns:wfw="http://wellformedweb.org/CommentAPI/"' . "\n";
        $xml .= '  xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n";
        $xml .= '  xmlns:wp="http://wordpress.org/export/1.2/"' . "\n";
        $xml .= '>' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= "  <title>{$name}</title>\n";
        $xml .= "  <link>https://example.com</link>\n";
        $xml .= "  <description>Content import for {$name}</description>\n";
        $xml .= "  <language>en-US</language>\n";
        $xml .= "  <wp:wxr_version>1.2</wp:wxr_version>\n\n";

        // Product categories as terms
        $categories = [];
        foreach ($products as $p) {
            $cat = $p['category'] ?? '';
            if ($cat && !in_array($cat, $categories)) $categories[] = $cat;
        }
        foreach ($categories as $cat) {
            $catSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($cat)));
            $xml .= "  <wp:term>\n";
            $xml .= "    <wp:term_taxonomy>product_cat</wp:term_taxonomy>\n";
            $xml .= "    <wp:term_slug>{$catSlug}</wp:term_slug>\n";
            $xml .= "    <wp:term_name><![CDATA[{$cat}]]></wp:term_name>\n";
            $xml .= "  </wp:term>\n";
        }

        $postId = 100;

        // ── Pages ──
        foreach ($pages as $slug => $page) {
            // Defense in depth: never emit auth/admin/route-param pages or JSX source in WXR
            if (DeterministicGen::isSkippableSlug($slug)) continue;
            $postId++;
            $title = htmlspecialchars($page['title']);
            $content = $page['content'] ?? '';
            if (DeterministicGen::isJsxSource($content)) {
                $content = '';
            }
            // Clean content for WXR: strip PHP tags, keep HTML
            $content = preg_replace('/<\?php.*?\?>/s', '', $content);
            // Replace %THEMEURI% with a root-relative fallback so WXR-imported
            // content has working image URLs (WP's native importer never executes
            // PHP, so the token must become a plain URL here at build time).
            $content = str_replace('%THEMEURI%', '/wp-content/themes/' . $prefix . '/assets/images', $content);

            $xml .= "  <item>\n";
            $xml .= "    <title><![CDATA[{$page['title']}]]></title>\n";
            $xml .= "    <wp:post_id>{$postId}</wp:post_id>\n";
            $xml .= "    <wp:post_date>" . date('Y-m-d H:i:s') . "</wp:post_date>\n";
            $xml .= "    <wp:post_name>{$slug}</wp:post_name>\n";
            $xml .= "    <wp:post_type>page</wp:post_type>\n";
            $xml .= "    <wp:status>publish</wp:status>\n";

            // If we created a page template, reference it
            if (isset($this->files["page-{$slug}.php"])) {
                $xml .= "    <wp:postmeta>\n";
                $xml .= "      <wp:meta_key>_wp_page_template</wp:meta_key>\n";
                $xml .= "      <wp:meta_value>page-{$slug}.php</wp:meta_value>\n";
                $xml .= "    </wp:postmeta>\n";
            }

            $xml .= "    <content:encoded><![CDATA[{$content}]]></content:encoded>\n";
            $xml .= "  </item>\n\n";
        }

        // ── Products ──
        foreach ($products as $i => $product) {
            $postId++;
            $pname = $product['name'] ?? 'Product ' . ($i + 1);
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($pname)));
            $desc = $product['description'] ?? '';
            $price = preg_replace('/[^0-9.]/', '', $product['price'] ?? '');
            $sku = $product['sku'] ?? ('PROD-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT));
            $cat = $product['category'] ?? '';
            $catSlug = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower($cat)));
            $image = $product['image'] ?? '';

            $xml .= "  <item>\n";
            $xml .= "    <title><![CDATA[{$pname}]]></title>\n";
            $xml .= "    <wp:post_id>{$postId}</wp:post_id>\n";
            $xml .= "    <wp:post_date>" . date('Y-m-d H:i:s') . "</wp:post_date>\n";
            $xml .= "    <wp:post_name>{$slug}</wp:post_name>\n";
            $xml .= "    <wp:post_type>product</wp:post_type>\n";
            $xml .= "    <wp:status>publish</wp:status>\n";
            $xml .= "    <content:encoded><![CDATA[{$desc}]]></content:encoded>\n";

            if ($cat) {
                $xml .= "    <category domain=\"product_cat\" nicename=\"{$catSlug}\"><![CDATA[{$cat}]]></category>\n";
            }

            // WooCommerce meta
            $xml .= "    <wp:postmeta><wp:meta_key>_regular_price</wp:meta_key><wp:meta_value><![CDATA[{$price}]]></wp:meta_value></wp:postmeta>\n";
            $xml .= "    <wp:postmeta><wp:meta_key>_price</wp:meta_key><wp:meta_value><![CDATA[{$price}]]></wp:meta_value></wp:postmeta>\n";
            $xml .= "    <wp:postmeta><wp:meta_key>_sku</wp:meta_key><wp:meta_value><![CDATA[{$sku}]]></wp:meta_value></wp:postmeta>\n";
            $xml .= "    <wp:postmeta><wp:meta_key>_stock_status</wp:meta_key><wp:meta_value><![CDATA[instock]]></wp:meta_value></wp:postmeta>\n";
            $xml .= "    <wp:postmeta><wp:meta_key>_visibility</wp:meta_key><wp:meta_value><![CDATA[visible]]></wp:meta_value></wp:postmeta>\n";

            if ($image) {
                $xml .= "    <wp:postmeta><wp:meta_key>_product_image_url</wp:meta_key><wp:meta_value><![CDATA[{$image}]]></wp:meta_value></wp:postmeta>\n";
            }

            $xml .= "  </item>\n\n";
        }

        $xml .= "</channel>\n</rss>\n";
        return $xml;
    }

    // ─── Install Instructions ────────────────────────────────────────────────

    private function generateInstallInstructions(bool $has_woo, int $page_count, int $product_count): string
    {
        $name = $this->theme_name;
        $txt = "{$name} — WordPress Theme\n";
        $txt .= "Generated by base44towordpress.com\n";
        $txt .= str_repeat('=', 50) . "\n\n";

        $txt .= "INSTALLATION\n";
        $txt .= "1. Go to Appearance → Themes → Add New → Upload Theme\n";
        $txt .= "2. Upload this zip file and activate the theme\n\n";

        if ($has_woo) {
            $txt .= "WOOCOMMERCE (Required for products)\n";
            $txt .= "3. Install and activate WooCommerce plugin\n";
            $txt .= "4. Run the WooCommerce setup wizard\n\n";
        }

        $txt .= "IMPORT CONTENT\n";
        $step = $has_woo ? 5 : 3;
        $txt .= "{$step}. Install the 'WordPress Importer' plugin (Tools → Import → WordPress → Install)\n";
        $step++;
        $txt .= "{$step}. Go to Tools → Import → WordPress → Run Importer\n";
        $step++;
        $txt .= "{$step}. Upload the file: inc/content-import.xml\n";
        $txt .= "   This will import:\n";
        if ($page_count > 0) {
            $txt .= "   - {$page_count} page(s) with custom templates\n";
        }
        if ($product_count > 0) {
            $txt .= "   - {$product_count} WooCommerce product(s) with prices and categories\n";
        }
        $txt .= "\n";

        if ($has_woo && $product_count > 0) {
            $txt .= "ALTERNATIVE: CSV Product Import\n";
            $step++;
            $txt .= "{$step}. Go to WooCommerce → Products → Import\n";
            $step++;
            $txt .= "{$step}. Upload: inc/product-import.csv\n\n";
        }

        $txt .= "SET HOMEPAGE\n";
        $step++;
        $txt .= "{$step}. Go to Settings → Reading\n";
        $step++;
        $txt .= "{$step}. Set 'Your homepage displays' to 'A static page'\n";
        $step++;
        $txt .= "{$step}. Select 'Home' as the homepage\n\n";

        $txt .= "CUSTOMIZE\n";
        $txt .= "- Appearance → Customize to toggle sections on/off\n";
        $txt .= "- Each section's text is editable via the Customizer\n";
        $txt .= "- Page templates are in the theme folder as page-{slug}.php\n";

        return $txt;
    }

    // ─── Product cleaning + junk filter (v2.3.0) ─────────────────────────────

    /**
     * Strip U+FFFD replacement chars, repair invalid UTF-8, and remove control
     * characters from a product field. Applied before any product reaches the
     * CSV, WXR, or demo-content generators.
     */
    private function cleanUtf8(string $s): string
    {
        $s = str_replace("\xEF\xBF\xBD", '', $s);
        if (function_exists('mb_check_encoding')) {
            if (!mb_check_encoding($s, 'UTF-8')) {
                $conv = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
                $s = ($conv !== false) ? $conv : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            }
        } else {
            // v2.3.1: mbstring may be absent on the worker CLI — iconv round-trip
            // drops invalid byte sequences; strip any U+FFFD it may emit.
            $conv = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
            if ($conv !== false) {
                $s = $conv;
            }
            $s = str_replace("\xEF\xBF\xBD", '', $s);
        }
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        return trim($s);
    }

    /**
     * UTF-8-clean every product field and drop scraped non-products
     * (page sentences, nav labels, person names, streaming platforms).
     */
    private function filterProducts(array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            if (!is_array($p)) continue;
            foreach (['name', 'description', 'category', 'sku', 'price', 'image'] as $f) {
                if (isset($p[$f]) && is_string($p[$f])) {
                    $p[$f] = $this->cleanUtf8($p[$f]);
                }
            }
            if ($this->isJunkProduct($p)) continue;
            $out[] = $p;
        }
        return array_values($out);
    }

    /**
     * Heuristics tuned against real scrape output: scraped page text masquerading
     * as products (testimonials, auth strings, nav labels, headings) is dropped.
     * Anything with a real price, an image, or a category is always kept.
     */
    private function isJunkProduct(array $p): bool
    {
        $name = trim((string) ($p['name'] ?? ''));
        if ($name === '' || $name === '__base44_products__') return true;
        $nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($nameLen > 80) return true;
        // Truncated / ellipsis names
        if (preg_match('/(…|\.\.\.)\s*$/u', $name)) return true;
        // Sentence punctuation beyond common abbreviations = scraped page text
        $stripped = preg_replace('/\b(Vol|No|St|Mt|Dr|Mr|Mrs|Ms|Jr|Sr|vs|etc|Inc|Ltd|Co|Ft|feat)\./i', '', $name);
        if (preg_match('/[.!?]/u', $stripped)) return true;
        // Em/en-dash clause = sentence fragment, not a product name
        if (preg_match('/\s[—–]/u', $name)) return true;
        // Streaming platforms / external services scraped from listen-on links
        if (preg_match('/^(spotify|apple music|amazon music|amazon|deezer|youtube|youtube music|itunes|tidal|pandora|soundcloud|google play)$/i', $name)) return true;

        // Evidence of a real product: a non-zero price, an image, or a category
        $priceNum = (float) preg_replace('/[^0-9.]/', '', (string) ($p['price'] ?? ''));
        if ($priceNum > 0 || !empty($p['image']) || !empty($p['category'])) return false;

        // No price/image/category — text-shape heuristics for scraped page text
        $words = preg_split('/\s+/u', $name);
        $wc = count($words);
        // Single-word labels (nav items, buttons, tags)
        if ($wc === 1) return true;
        // UI sentence-case strings ("Log in to your account", "Welcome back")
        $stop = ['the', 'of', 'a', 'an', 'and', 'or', 'for', 'in', 'on', 'to', 'with', 'by', 'at', 'from', '&', 'de', 'la'];
        foreach (array_slice($words, 1) as $w) {
            $bare = trim($w, ',:;()"\'');
            $lower = function_exists('mb_strtolower') ? mb_strtolower($bare, 'UTF-8') : strtolower($bare);
            if ($bare === '' || in_array($lower, $stop, true)) continue;
            if (preg_match('/^\p{Ll}/u', $bare)) return true;
        }
        // Person-name-like: exactly two capitalized alpha words, no digits
        if ($wc === 2 && preg_match('/^\p{Lu}[\p{L}\'’-]+\s\p{Lu}[\p{L}\'’-]+$/u', $name)) return true;
        // Honorific-prefixed names ("Pastor David Owens")
        if (preg_match('/^(pastor|rev|reverend|dr|mr|mrs|ms|fr|father|bishop|elder|coach|prof)\.?\s\p{Lu}/iu', $name)) return true;
        return false;
    }

    // ─── Product Import CSV ──────────────────────────────────────────────────

    private function generateProductCSV(array $products): string
    {
        $headers = ['Type', 'SKU', 'Name', 'Published', 'Regular price', 'Short description', 'Description', 'Categories', 'Images'];
        $csv = implode(',', $headers) . "\n";

        foreach ($products as $i => $p) {
            $sku = 'PROD-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
            $row = [
                'simple',
                $this->csvEsc($p['sku'] ?? $sku),
                $this->csvEsc($p['name'] ?? ''),
                '1',
                $this->csvEsc(preg_replace('/[^0-9.]/', '', $p['price'] ?? '')),
                $this->csvEsc($p['description'] ?? ''),
                $this->csvEsc($p['description'] ?? ''),
                $this->csvEsc($p['category'] ?? ''),
                $this->csvEsc($p['image'] ?? ''),
            ];
            $csv .= implode(',', $row) . "\n";
        }

        return $csv;
    }

    private function csvEsc(string $v): string
    {
        if (strpos($v, ',') !== false || strpos($v, '"') !== false || strpos($v, "\n") !== false) {
            return '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    // ─── Packaging ───────────────────────────────────────────────────────────

    private function package(): string
    {
        $uuid = $this->conversion['uuid'] ?? 'test';
        $dir_name = $this->theme_slug . '-theme';
        $conv_dir  = $this->output_base . '/' . $uuid;
        $theme_dir = $this->themeDir();
        $theme_zip = $conv_dir . '/theme/' . $dir_name . '.zip';
        $data_dir  = $conv_dir . '/data';
        $outer_zip = $conv_dir . '/' . $dir_name . '-download.zip';

        if (!is_dir($theme_dir)) mkdir($theme_dir, 0755, true);
        if (!is_dir($data_dir)) mkdir($data_dir, 0755, true);

        foreach ($this->files as $rel => $content) {
            $path = $theme_dir . '/' . $rel;
            $dir = dirname($path);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($path, $content);
        }

        // v3.0.0: images already live in $theme_dir/assets/images — MediaImporter
        // downloaded them in Stage 0 (the v2 copyImages() cache-dir copy is
        // REMOVED: the app-server image cache never exists on Fred).
        // v4.3.1: the data/images mirror is REMOVED. It duplicated the same
        // files already shipped inside theme/<slug>.zip's assets/images, and
        // nothing downstream (worker.php, api-fred-complete.php, download.php)
        // ever reads data/images — the customer only receives the outer ZIP
        // and installs the inner theme ZIP as-is. See copyImagesToData()
        // removal note below.
        $this->writeDataExports($data_dir);
        $this->createScreenshot($theme_dir);
        // v4.2.0: normalize permissions across the staged theme dir before
        // zipping — MediaImporter previously left images at 0640 (webserver
        // 403 on every image); this is a belt-and-braces pass in case any
        // other writer in this staging tree ever leaves a restrictive mode.
        $this->normalizePermissions($theme_dir);
        $this->zipDirectory($theme_dir, $theme_zip);
        // v4.3.1: the outer ZIP now packages the installable theme_zip file
        // plus data_dir only, not the unpacked theme_dir tree that theme_zip
        // was built from. Previously zipOuterPackage() walked conv_dir/theme
        // wholesale, which contains both the unpacked <slug>-theme/ directory
        // and the <slug>-theme.zip built from it, so the whole theme shipped
        // twice inside every outer download.
        $this->zipOuterPackage($outer_zip, $theme_zip, $data_dir);

        return $outer_zip;
    }

    private function createScreenshot(string $theme_dir): void
    {
        if (file_exists($theme_dir . '/screenshot.png')) return;
        if (function_exists('imagecreatetruecolor')) {
            try {
                $im = imagecreatetruecolor(1200, 900);
                $bg = imagecolorallocate($im, 14, 26, 14);
                imagefill($im, 0, 0, $bg);
                imagestring($im, 5, 420, 420, $this->theme_name, imagecolorallocate($im, 240, 234, 214));
                imagestring($im, 3, 380, 460, 'base44towordpress.com', imagecolorallocate($im, 140, 140, 120));
                imagepng($im, $theme_dir . '/screenshot.png');
                imagedestroy($im);
                return;
            } catch (\Throwable $e) {}
        }
        file_put_contents($theme_dir . '/screenshot.png', '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900"><rect width="1200" height="900" fill="#0e1a0e"/><text x="600" y="450" font-size="32" fill="#f0ead6" text-anchor="middle">' . htmlspecialchars($this->theme_name) . '</text></svg>');
    }

    /**
     * v4.2.0: chmod every file to 0644 and every directory to 0755 within
     * the staged theme dir before it gets zipped. Fixes the 640-mode images
     * MediaImporter used to write (webserver 403 on every image) and is a
     * safety net for any other writer in this tree.
     */
    private function normalizePermissions(string $dir): void
    {
        if (!is_dir($dir)) return;
        @chmod($dir, 0755);
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $file) {
            @chmod($file->getRealPath(), $file->isDir() ? 0755 : 0644);
        }
    }

    private function zipDirectory(string $source, string $output): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create ZIP: ' . $output);
            }
            $source = rtrim(realpath($source), '/');
            $parent = dirname($source);
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($iter as $file) {
                if ($file->isFile()) {
                    $local = substr($file->getRealPath(), strlen($parent) + 1);
                    $zip->addFile($file->getRealPath(), $local);
                    // v4.2.0: stamp Unix external attributes so extracted
                    // files inherit 0644 regardless of the archiver used to
                    // unpack (the staged file itself is already 0644 via
                    // normalizePermissions(), but this covers extractors
                    // that trust the zip's stored mode instead).
                    $zip->setExternalAttributesName($local, \ZipArchive::OPSYS_UNIX, (0644 << 16));
                }
            }
            $zip->close();
            return;
        }

        $cwd = getcwd();
        chdir(dirname($source));
        $cmd = 'zip -qr ' . escapeshellarg($output) . ' ' . escapeshellarg(basename($source));
        exec($cmd, $out, $code);
        chdir($cwd ?: '/');
        if ($code !== 0) {
            throw new RuntimeException('Cannot create ZIP via shell: ' . $output);
        }
    }



    // v4.3.1: copyImagesToData() removed. It mirrored the theme's own
    // assets/images into data/images, which duplicated content already
    // shipped inside theme/<slug>.zip and was the largest single contributor
    // to the outer ZIP bloat that broke uploads (post_max_size 55M on the
    // app server's .htaccess). Nothing reads data/images downstream.

    private function writeDataExports(string $data_dir): void
    {
        if (isset($this->files['inc/content-import.xml'])) {
            file_put_contents($data_dir . '/content-import.xml', $this->files['inc/content-import.xml']);
        }
        if (isset($this->files['inc/product-import.csv'])) {
            file_put_contents($data_dir . '/product-import.csv', $this->files['inc/product-import.csv']);
        }
        // Live-scan QA data (v2.1.0): raw scan JSON only.
        // v4.3.1: the original-home.png screenshot copy is REMOVED. It was a
        // build/diagnostic artifact (a rendered screenshot of the live site
        // used internally for the visual QA gate), not something the
        // customer needs to install their theme, and it added several MB to
        // every outer ZIP for no customer-facing purpose.
        $scan = $this->analysis['live_scan'] ?? [];
        if (!empty($scan)) {
            $scan_out = $scan;
            unset($scan_out['screenshots']);
            file_put_contents($data_dir . '/live-scan.json', json_encode($scan_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        // v3.0.0: ship the multi-page scan manifest as a QA artifact
        if (!empty($this->analysis['site_scan'])) {
            file_put_contents($data_dir . '/site-scan-manifest.json', json_encode($this->analysis['site_scan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        file_put_contents($data_dir . '/products.json', json_encode($this->analysis['products'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents($data_dir . '/site-data.json', json_encode([
            'theme_name' => $this->analysis['theme_name'] ?? $this->theme_name,
            'base_url' => $this->analysis['base_url'] ?? '',
            'pages' => $this->analysis['pages'] ?? [],
            'sections' => $this->analysis['sections'] ?? [],
            'nav_items' => $this->analysis['nav_items'] ?? [],
            'colors' => $this->analysis['colors'] ?? [],
            'fonts' => $this->analysis['fonts'] ?? [],
            'images' => $this->analysis['images'] ?? [],
            'products' => $this->analysis['products'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * v4.3.1: builds the customer-facing outer ZIP from exactly two things:
     * the installable theme_zip file (theme/<slug>-theme.zip) and the small
     * data_dir exports (WXR, product CSV, JSON manifests). It previously
     * walked the whole conv_dir/theme directory, which held BOTH the
     * unpacked <slug>-theme/ staging tree (source for theme_zip) AND
     * theme_zip itself, so the entire theme shipped twice in every outer
     * download. Only theme_zip is added now, never the unpacked tree.
     */
    private function zipOuterPackage(string $outer_zip, string $theme_zip, string $data_dir): void
    {
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($outer_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create ZIP: ' . $outer_zip);
            }
            if (is_file($theme_zip)) {
                $local = 'theme/' . basename($theme_zip);
                $zip->addFile($theme_zip, $local);
                // v4.2.0: same external-attributes stamp as zipDirectory().
                $zip->setExternalAttributesName($local, \ZipArchive::OPSYS_UNIX, (0644 << 16));
            }
            if (is_dir($data_dir)) {
                $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($data_dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($iter as $file) {
                    if ($file->isFile()) {
                        $real = $file->getRealPath();
                        $local = 'data/' . substr($real, strlen(rtrim($data_dir, '/')) + 1);
                        $zip->addFile($real, $local);
                        $zip->setExternalAttributesName($local, \ZipArchive::OPSYS_UNIX, (0644 << 16));
                    }
                }
            }
            $zip->close();
            return;
        }

        $conv_dir = dirname(rtrim($data_dir, '/'));
        $cwd = getcwd();
        chdir($conv_dir);
        $rel_theme_zip = 'theme/' . basename($theme_zip);
        $cmd = 'zip -qr ' . escapeshellarg($outer_zip) . ' ' . escapeshellarg($rel_theme_zip) . ' data';
        exec($cmd, $out, $code);
        chdir($cwd ?: '/');
        if ($code !== 0) {
            throw new RuntimeException('Cannot create outer ZIP via shell: ' . $outer_zip);
        }
    }
    private function setStage(string $stage): void
    {
        $id = $this->conversion['id'] ?? null;
        if (!$id) return;
        try { db()->prepare('UPDATE conversions SET conversion_stage = ? WHERE id = ?')->execute([$stage, $id]); } catch (\Throwable $e) {}
    }
}
