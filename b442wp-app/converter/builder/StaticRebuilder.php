<?php
/**
 * StaticRebuilder — HTML-to-HTML static page rebuilder using Claude.
 *
 * Replaces SectionGen for the WordPress-theme converter when the target
 * is a static website (not WordPress). Its job: ask Claude to emit FAITHFUL
 * semantic HTML for a whole page (NOT PHP, NOT WordPress functions) that
 * reuses the original site's class names so the existing scraped CSS bundle
 * styles it unchanged.
 *
 * Follows the same token accounting, caching, and ClaudeClient integration
 * patterns as SectionGen v5, but optimized for static HTML output.
 */
declare(strict_types=1);

class StaticRebuilder
{
    private ClaudeClient $claude;
    private string $prefix;
    private array $analysis;
    private string $css_bundle;
    private string $ground_truth = '';
    private string $cache_prefix = '';
    private int $calls = 0;

    /**
     * System prompt: the EXACT rules for faithful HTML transcription.
     * This constant is embedded in every conversion call.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a faithful HTML transcription engine. Output ONLY the semantic HTML for the page body content — the main page sections, hero, features, content areas, etc. Preserve the EXACT class names, text content, and structure from the source DOM so the existing stylesheet applies unchanged. Do NOT write PHP, WordPress functions, template tags, <script>, <style>, <html>, <head>, or <!doctype>. Do NOT include the site header, site navigation/nav bar, or site footer — those are generated separately as WordPress template parts. Do NOT invent, add, summarize, or remove sections or copy. Transcribe only the main page content that is in the DOM.
PROMPT;

    /**
     * Single source of truth for the single-call page-body cap. Used both as
     * the default threshold in needsChunking() (decides whether a page routes
     * to the fast single-call batch path or the chunked path) and as the cap
     * preparePageJob() passes to pageBodyForPrompt() on that single-call path.
     * Deriving both from one constant means a page is always either chunked
     * or guaranteed to fit under the cap, with no size window in between
     * where content would be silently truncated by neither path.
     */
    public const SINGLE_CALL_CAP = 45000;

    /**
     * Slugs of pages where at least one chunk in rebuildChunked() failed or
     * came back empty even after retries, and had to be dropped from the
     * reassembled page. Keyed by slug, value is the list of 1-based chunk
     * indices that were lost. Reset at the start of every rebuildChunked()
     * call for that slug, so this always reflects only the most recent call.
     * ThemeBuilder reads this right after each rebuildChunked() call and
     * folds any hit into its own degraded[] list, the same mechanism already
     * used for visual-gate failures.
     */
    public array $incompleteChunkSlugs = [];

    public function __construct(ClaudeClient $claude, string $prefix, array $analysis, string $css_bundle = '')
    {
        $this->claude       = $claude;
        $this->prefix       = $prefix;
        $this->analysis     = $analysis;
        $this->css_bundle   = $css_bundle;
        $this->ground_truth = $this->buildGroundTruth($analysis['live_scan'] ?? $analysis['site_scan'] ?? []);
    }

    /**
     * Compact GROUND TRUTH block from the Playwright live scan (v2.1.0).
     * Embedded in the shared cache prefix so every conversion uses
     * real measured fonts/colors instead of guessing from the CSS bundle.
     * Ported verbatim from SectionGen::buildGroundTruth().
     */
    private function buildGroundTruth(array $scan): string
    {
        if (empty($scan['computed'])) return '';
        $lines = ['GROUND TRUTH COMPUTED STYLES (measured on the live site in a real browser — use these EXACT font-family, weight, text-transform, letter-spacing and color values; never substitute different fonts):'];
        foreach ($scan['computed'] as $key => $props) {
            if (!is_array($props)) continue;
            unset($props['_sample_text']);
            $pairs = [];
            foreach ($props as $k => $v) $pairs[] = "{$k}:{$v}";
            if ($pairs) $lines[] = strtoupper($key) . ' { ' . implode('; ', $pairs) . ' }';
        }
        $fams = [];
        foreach (($scan['fonts']['document_fonts'] ?? []) as $df) {
            if (!empty($df['family'])) $fams[$df['family']] = true;
        }
        if ($fams) $lines[] = 'LOADED FONTS: ' . implode(', ', array_slice(array_keys($fams), 0, 60));
        foreach (array_slice($scan['fonts']['google_links'] ?? [], 0, 20) as $gl) {
            $lines[] = 'GOOGLE FONTS URL: ' . $gl;
        }
        $pal = [];
        foreach (($scan['palette'] ?? []) as $k => $v) {
            if ((string) $v !== '') $pal[] = "{$k}:{$v}";
        }
        if ($pal) $lines[] = 'PALETTE: ' . implode('; ', $pal);

        // v2.2.0 layout rules (nav overlap + stats contrast)
        $lines[] = 'LAYOUT RULES: nav items must have >=1.25em gap (use column-gap on the nav flex container); '
                 . 'uppercase letter-spacing must never cause adjacent nav items to visually overlap '
                 . '(letter-spacing also renders after the LAST glyph — compensate with gap). '
                 . 'Stats/counter sections must keep >=4.5:1 text contrast against their section background; '
                 . 'if the original site shows stats on a dark band, use the dark background with light text — '
                 . 'never light-on-light or dark-on-dark.';

        $block = implode("\n", $lines);
        return strlen($block) > 3072 ? substr($block, 0, 3072) : $block;
    }

    public function getCalls(): int
    {
        return $this->calls;
    }

    public function getTokenUsage(): array
    {
        return $this->claude->getTokenUsage();
    }

    /**
     * Build the shared cached system prefix for all conversions.
     * Ground truth + site CSS context, matching SectionGen v3.0.0 pattern.
     */
    public function cachePrefix(): string
    {
        if ($this->cache_prefix !== '') return $this->cache_prefix;

        $parts = ["SHARED CONVERSION CONTEXT for the '{$this->prefix}' static HTML site (applies to every page conversion in this build):"];

        if ($this->ground_truth !== '') {
            $parts[] = "=== GROUND TRUTH ===\n" . $this->ground_truth;
        }

        // Inject learnings.md if it exists
        $learnings_path = dirname(__FILE__) . '/../prompts/learnings.md';
        if (@file_exists($learnings_path)) {
            $learnings = @file_get_contents($learnings_path);
            if ($learnings !== false && $learnings !== '') {
                $parts[] = "=== LEARNED PATTERNS ===\n" . $learnings;
            }
        }

        $css_slice = substr($this->css_bundle, 0, 200000);
        if ($css_slice !== '') {
            $parts[] = "=== SITE CSS CONTEXT (compiled CSS from the original site; preserve matching class names exactly) ===\n" . $css_slice;
        }

        $prefix = implode("\n\n", $parts);

        // Cache-floor guard: prompt caching needs >1024 tokens (~4KB+ text).
        // This is intentionally minimal for static rebuilds (no rendered_html reference).

        $this->cache_prefix = $prefix;
        return $this->cache_prefix;
    }

    /**
     * Prepare a messageBatch job for one static page conversion.
     * Returns the job array with the same shape as SectionGen::prepareSectionJob(),
     * ready for ClaudeClient::messageBatch().
     *
     * @param string $slug         Page slug (e.g., 'homepage', 'about', 'contact')
     * @param string $renderedHtml The full rendered HTML of the page (will be capped)
     * @param array  $computed     Optional: computed styles map for this specific page
     * @return array Job array: ['system' => string, 'user' => string, 'max_tokens' => int, 'meta' => array]
     */
    public function preparePageJob(string $slug, string $renderedHtml, array $computed = []): array
    {
        // Cap the HTML to prevent token explosion (matching SectionGen pattern).
        // Uses self::SINGLE_CALL_CAP, the same constant needsChunking() checks
        // against to decide whether a page ever reaches this method at all.
        // See the constant's docblock. Do not hardcode a number here again.
        $body = $this->pageBodyForPrompt($renderedHtml, self::SINGLE_CALL_CAP);

        // Relevant CSS extraction
        $css_snippet = $this->extractRelevantCss($body, 8000);

        // Build the user message
        $user = "Convert this page to faithful semantic HTML (no PHP, no WordPress functions, no <script>, no <style>, no <html>, no <head>, no <!doctype>).\n\n";

        if ($css_snippet) {
            $user .= "Relevant CSS (keep these class names exactly):\n<style>\n{$css_snippet}\n</style>\n\n";
        }

        if (!empty($computed)) {
            $compact = [];
            foreach (['h1', 'h2', 'p', 'a', 'btn'] as $k) {
                if (!empty($computed[$k]) && is_array($computed[$k])) $compact[$k] = $computed[$k];
            }
            if ($compact) {
                $user .= "THIS PAGE'S MEASURED COMPUTED STYLES:\n"
                       . json_encode($compact, JSON_UNESCAPED_SLASHES) . "\n\n";
            }
        }

        $user .= "HTML:\n" . $body;

        return [
            'system'     => self::SYSTEM_PROMPT,
            'user'       => $user,
            'max_tokens' => 32000,
            'meta'       => ['kind' => 'static-page', 'slug' => $slug],
        ];
    }

    /**
     * Strip rendered page to body, remove scripts/styles, cap at limit.
     * Ported from SectionGen::pageBodyForPrompt().
     */
    public function pageBodyForPrompt(string $html, int $cap = 200000): string
    {
        $body = $this->extractBody($html);
        if (strlen($body) > $cap) {
            $actualLength = strlen($body);
            error_log(sprintf(
                'WARNING: pageBodyForPrompt truncated page body from %d chars to cap %d chars, content beyond the cap was dropped.',
                $actualLength,
                $cap
            ));
            $body = substr($body, 0, $cap);
        }
        return $body;
    }

    /**
     * Strip rendered page to body, remove scripts/styles/noscript. No cap —
     * used by needsChunking()/rebuildChunked() (v4.2.0) which do their own
     * section-boundary splitting instead of a hard substr() cut.
     */
    private function extractBody(string $html): string
    {
        $body = $html;
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
            $body = $m[1];
        }
        $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body);
        $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $body);
        $body = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $body);
        return trim((string) $body);
    }

    /**
     * v4.2.0: true when the page body exceeds the single-call cap and needs
     * rebuildChunked() instead of the old silent substr() truncation (which
     * dropped whole trailing sections on large homepages). Default cap is
     * self::SINGLE_CALL_CAP, the exact same constant preparePageJob() caps
     * its pageBodyForPrompt() call at, so a page is always either routed
     * here to be chunked in full or guaranteed to fit under the single-call
     * cap with nothing dropped. Do not pass a different cap here without
     * also changing SINGLE_CALL_CAP.
     */
    public function needsChunking(string $renderedHtml, int $cap = self::SINGLE_CALL_CAP): bool
    {
        return strlen($this->extractBody($renderedHtml)) > $cap;
    }

    /**
     * v4.2.3: cap passed by rebuildChunked() to splitIntoChunksWithWrapper(),
     * down from 45000. Base44/React pages typically have exactly ONE
     * top-level wrapper (<div id="root"><div class="min-h-screen">...ALL the
     * content...</div></div>), so the old flat top-level split found nothing
     * to split at and emitted the whole ~180KB page as a single chunk —
     * Claude's response for that needed ~32k tokens, which could not finish
     * inside the 240s per-job budget. A smaller cap keeps each chunk's
     * response comfortably inside that budget.
     */
    private const CHUNK_CAP = 30000;

    /**
     * HTML5 void elements: written without a closing tag and, in scraped
     * markup, usually without a self-closing "/>" either (e.g. <img ...>,
     * not <img ... />). Depth tracking must treat these as never opening a
     * level, or the scan runs away to a depth that never returns to 0.
     */
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * True when $tag (a full open-tag match, e.g. "<img src=\"x\">") is a
     * void element: either explicitly self-closed ("/>") or one of the
     * HTML5 void tag names regardless of how it's terminated.
     */
    private function isVoidTag(string $tag): bool
    {
        if (preg_match('/\/>\s*$/', $tag) === 1) {
            return true;
        }
        if (preg_match('/^<\s*([a-zA-Z][a-zA-Z0-9]*)/', $tag, $nm) === 1) {
            return in_array(strtolower($nm[1]), self::VOID_TAGS, true);
        }
        return false;
    }

    /**
     * Split HTML into top-level segments (direct children of the current
     * level), each segment carrying any leading whitespace/comment text that
     * preceded it. A depth-scanning tag tokenizer, not DOMDocument (scanned
     * HTML can be malformed and DOMDocument chokes or silently reflows it).
     *
     * @return list<string>
     */
    private function topLevelSegments(string $html): array
    {
        $len = strlen($html);
        $segments = [];
        $seg_start = 0;
        $pos = 0;
        $depth = 0;

        while ($pos < $len) {
            if (!preg_match('/<\s*\/?\s*[a-zA-Z][a-zA-Z0-9]*\b[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
                break; // no more tags — the remainder is trailing text/whitespace
            }
            $tag    = $m[0][0];
            $tagEnd = $m[0][1] + strlen($tag);
            $is_close = $tag[1] === '/';
            $is_void  = !$is_close && $this->isVoidTag($tag);

            if ($is_close) {
                $depth = max(0, $depth - 1);
            } elseif (!$is_void) {
                $depth++;
            }

            // Only a boundary right after a top-level (depth 0) element closes.
            if ($depth === 0 && ($is_close || $is_void)) {
                $segments[] = substr($html, $seg_start, $tagEnd - $seg_start);
                $seg_start = $tagEnd;
            }
            $pos = $tagEnd;
        }

        if ($seg_start < $len) {
            $segments[] = substr($html, $seg_start);
        }

        return $segments;
    }

    /**
     * True when $segment (as produced by topLevelSegments()) contains an
     * actual element, as opposed to only whitespace/HTML comments.
     */
    private function segmentIsElement(string $segment): bool
    {
        $lead = preg_replace('/^(\s|<!--.*?-->)+/s', '', $segment);
        return preg_match('/^<[a-zA-Z]/', (string) $lead) === 1;
    }

    /**
     * Given a topLevelSegments() entry known to contain exactly one element
     * (segmentIsElement() === true), pull out [openTag, innerHtml, closeTag].
     * Returns null for void/self-closing elements (nothing to descend into)
     * or if the markup does not balance.
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function extractSingleElement(string $segment): ?array
    {
        if (!preg_match('/<\s*[a-zA-Z][a-zA-Z0-9]*\b[^>]*>/', $segment, $om, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $openTag   = $om[0][0];
        $openStart = $om[0][1];
        $openEnd   = $openStart + strlen($openTag);

        if ($this->isVoidTag($openTag)) {
            return null; // void/self-closing element — nothing to descend into
        }

        $len = strlen($segment);
        $pos = $openEnd;
        $depth = 1;

        while ($pos < $len) {
            if (!preg_match('/<\s*\/?\s*[a-zA-Z][a-zA-Z0-9]*\b[^>]*>/', $segment, $m, PREG_OFFSET_CAPTURE, $pos)) {
                return null; // unbalanced
            }
            $tag    = $m[0][0];
            $tagEnd = $m[0][1] + strlen($tag);
            $is_close = $tag[1] === '/';
            $is_void  = !$is_close && $this->isVoidTag($tag);

            if ($is_close) {
                $depth--;
                if ($depth === 0) {
                    $inner    = substr($segment, $openEnd, $m[0][1] - $openEnd);
                    $closeTag = substr($segment, $m[0][1], $tagEnd - $m[0][1]);
                    return [$openTag, $inner, $closeTag];
                }
            } elseif (!$is_void) {
                $depth++;
            }
            $pos = $tagEnd;
        }

        return null; // never balanced — leave untouched
    }

    /**
     * v4.2.3: locate the wrapper open/close tag chain by descending through
     * single-child levels (e.g. React's <div id="root"><div class="min-h-screen
     * bg-white">...ALL the real content...</div></div>), stopping once a
     * level actually has 2+ children to split at, or after $maxDepth levels
     * (whichever comes first — never loops forever on a pathological page).
     *
     * @return array{0: string, 1: string, 2: string} [openChain, closeChain, innerHtml]
     */
    private function resolveWrapperChain(string $html, int $maxDepth = 6): array
    {
        $openChain  = '';
        $closeChain = '';
        $current    = $html;

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $segments = $this->topLevelSegments($current);
            $elements = array_values(array_filter($segments, [$this, 'segmentIsElement']));

            if (count($elements) !== 1) {
                break; // 0 or 2+ children at this level — split here instead
            }

            $extracted = $this->extractSingleElement($elements[0]);
            if ($extracted === null) {
                break; // void element or unbalanced markup — stop descending
            }

            [$openTag, $inner, $closeTag] = $extracted;
            $openChain  .= $openTag;
            $closeChain  = $closeTag . $closeChain;
            $current     = $inner;
        }

        return [$openChain, $closeChain, $current];
    }

    /**
     * Split a page body into chunks of at most $cap chars, breaking only at
     * top-level element boundaries so no element is ever cut in half. Kept
     * for the same signature/contract as before v4.2.3: an array of HTML
     * string chunks, each <= $cap wherever the markup allows it. Delegates to
     * splitIntoChunksWithWrapper(), which also does the single-child-wrapper
     * descent (see resolveWrapperChain()) needed for Base44/React pages that
     * have exactly one top-level wrapper div around all real content.
     *
     * @return list<string> Chunks in document order, each a well-formed run
     *                       of complete top-level elements.
     */
    private function splitIntoChunks(string $body, int $cap): array
    {
        return $this->splitIntoChunksWithWrapper($body, $cap)['chunks'];
    }

    /**
     * Same splitting logic as splitIntoChunks() but also returns the wrapper
     * open/close tag chain discovered while descending, so rebuildChunked()
     * can wrap the reassembled result ONCE (built deterministically here, not
     * by Claude) instead of asking every chunk to re-emit the wrapper tags.
     *
     * @return array{open: string, close: string, chunks: list<string>}
     */
    private function splitIntoChunksWithWrapper(string $body, int $cap): array
    {
        [$open, $close, $inner] = $this->resolveWrapperChain($body);
        $segments     = $this->topLevelSegments($inner);
        $elementCount = count(array_filter($segments, [$this, 'segmentIsElement']));

        if ($elementCount < 2) {
            // Either a childless blob (plain text/leaf content) or max
            // descent depth was hit with a single child still remaining.
            // Return it whole rather than loop or throw — best effort.
            $whole  = trim($inner);
            $chunks = $whole === '' ? [] : [$inner];
            return ['open' => $open, 'close' => $close, 'chunks' => $chunks];
        }

        $chunks = $this->groupChildrenIntoChunks($segments, $cap);
        return ['open' => $open, 'close' => $close, 'chunks' => $chunks];
    }

    /**
     * Group topLevelSegments() children into chunks of at most $cap chars
     * each, packing consecutive children in document order. A single child
     * that alone exceeds $cap is recursively split the same way (descent +
     * grouping) and its sub-chunks spliced into the sequence in its place,
     * preserving order.
     *
     * @param list<string> $segments
     * @return list<string>
     */
    private function groupChildrenIntoChunks(array $segments, int $cap): array
    {
        $chunks  = [];
        $current = '';

        foreach ($segments as $seg) {
            if (trim($seg) === '') continue;

            if (strlen($seg) > $cap) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current  = '';
                }
                foreach ($this->splitOversizedChild($seg, $cap) as $sub) {
                    $chunks[] = $sub;
                }
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($seg) > $cap) {
                $chunks[] = $current;
                $current  = '';
            }
            $current .= $seg;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, static fn ($c) => trim($c) !== ''));
    }

    /**
     * A single top-level child that alone exceeds the cap: split it the same
     * way as the page (wrapper descent + grouping), then re-attach ITS OWN
     * wrapper chain (if any) to the first/last sub-chunk. Unlike the
     * page-level wrapper chain — which rebuildChunked() re-attaches once,
     * around the whole reassembled page — this one has to travel WITH the
     * spliced-in sub-chunks, because they land in the middle of a sibling
     * sequence rather than at the outer edge of the whole page, so there is
     * no single later "wrap once" point that could re-attach it for us.
     *
     * @return list<string>
     */
    private function splitOversizedChild(string $childSegment, int $cap): array
    {
        $result = $this->splitIntoChunksWithWrapper($childSegment, $cap);
        $chunks = $result['chunks'];
        if ($chunks === []) return [];

        if ($result['open'] !== '' || $result['close'] !== '') {
            $last            = count($chunks) - 1;
            $chunks[0]       = $result['open'] . $chunks[0];
            $chunks[$last]  .= $result['close'];
        }

        return $chunks;
    }

    /**
     * v4.2.0: rebuild a page whose body exceeds the 45k single-call cap by
     * splitting it into section-boundary chunks and rebuilding each chunk
     * with its own Claude call (same system prompt, told which part of how
     * many it is), then concatenating the cleaned results in order. Replaces
     * the old silent substr() cap, which dropped the last N sections of any
     * oversized homepage.
     *
     * v4.2.3: splitIntoChunksWithWrapper() now descends through single-child
     * wrapper levels first (Base44/React pages typically have exactly one —
     * <div id="root"><div class="min-h-screen">...</div></div> — so the old
     * flat top-level split found nothing to split at and emitted the whole
     * page as one oversized chunk that could not finish inside the 240s
     * per-job budget). The wrapper open/close chain it finds is never given
     * to Claude to reproduce — it's re-attached ONCE around the whole
     * reassembled page below, built deterministically by code. Chunk cap
     * dropped from 45000 to 30000 chars so each chunk's response comfortably
     * finishes inside the budget.
     *
     * Return shape matches cleanHtml()'s output (a plain HTML string) so
     * ThemeBuilder's visual gate and templatize step need no changes.
     */
    public function rebuildChunked(string $slug, string $renderedHtml, array $computed = [], string $note = ''): string
    {
        // Clear any stale result from a previous call for this slug (e.g. the
        // gate retry round calls rebuildChunked() a second time) so
        // incompleteChunkSlugs always reflects only the most recent attempt.
        unset($this->incompleteChunkSlugs[$slug]);

        $body  = $this->extractBody($renderedHtml);
        $split = $this->splitIntoChunksWithWrapper($body, self::CHUNK_CAP);
        $chunks = $split['chunks'];
        if (empty($chunks)) return '';

        $wrapperOpen  = $split['open'];
        $wrapperClose = $split['close'];
        $hasWrapper   = $wrapperOpen !== '' || $wrapperClose !== '';

        $total = count($chunks);
        $jobs = [];
        foreach ($chunks as $i => $chunk) {
            $n = $i + 1;
            $css_snippet = $this->extractRelevantCss($chunk, 8000);

            $user = "Convert this page to faithful semantic HTML (no PHP, no WordPress functions, no <script>, no <style>, no <html>, no <head>, no <!doctype>).\n\n"
                  . "This is part {$n} of {$total} of the SAME page (the page was too large for one call and was split at section boundaries). ";

            if ($hasWrapper) {
                $user .= "All {$total} parts live inside this ancestor wrapper, which the calling code re-attaches ONCE around the whole reassembled page after every part comes back — opening chain: {$wrapperOpen} — closing chain: {$wrapperClose} — do NOT emit those wrapper tags yourself in any part, only the inner section content. ";
            }

            $user .= "Output ONLY the transcribed sections contained in this part, in order. Do not add a wrapper, a header, a nav, or a footer, "
                  . "and do not summarize or comment on the split; just transcribe this part's sections faithfully.\n\n";

            if ($note !== '') {
                $user .= $note . "\n\n";
            }

            if ($css_snippet) {
                $user .= "Relevant CSS (keep these class names exactly):\n<style>\n{$css_snippet}\n</style>\n\n";
            }

            if ($i === 0 && !empty($computed)) {
                $compact = [];
                foreach (['h1', 'h2', 'p', 'a', 'btn'] as $k) {
                    if (!empty($computed[$k]) && is_array($computed[$k])) $compact[$k] = $computed[$k];
                }
                if ($compact) {
                    $user .= "THIS PAGE'S MEASURED COMPUTED STYLES:\n"
                           . json_encode($compact, JSON_UNESCAPED_SLASHES) . "\n\n";
                }
            }

            $user .= "HTML:\n" . $chunk;

            $jobs["chunk-{$n}"] = [
                'system'     => self::SYSTEM_PROMPT,
                'user'       => $user,
                'max_tokens' => 32000,
                'meta'       => ['kind' => 'static-page-chunk', 'slug' => $slug, 'part' => $n, 'total' => $total],
            ];
        }

        $pool = min($total, 8);
        echo "  [Stage1] {$slug}: chunked rebuild: {$total} parts via messageBatch (pool {$pool})...\n";

        $results = $this->claude->messageBatch($jobs, $pool, ['cache_prefix' => $this->cachePrefix()]);

        // ClaudeClient::messageBatch() already retries transient HTTP errors
        // (429/529/5xx/network) internally up to its own MAX_RETRIES. A chunk
        // that is STILL empty or missing after that used to be dropped here
        // with a bare continue, silently deleting an entire section of the
        // page from the reassembled output, with nothing downstream aware it
        // happened. Retry still-failing chunks up to twice more (3 attempts
        // total per chunk) before giving up: same shape as ThemeBuilder's
        // gate retry round, collect the failing subset and resubmit only
        // that subset via messageBatch.
        $max_chunk_attempts = 3;
        for ($attempt = 2; $attempt <= $max_chunk_attempts; $attempt++) {
            $stillFailing = [];
            foreach ($jobs as $key => $job) {
                $r    = $results[$key] ?? null;
                $text = is_array($r) ? (string) ($r['text'] ?? '') : '';
                if (trim($text) === '') $stillFailing[$key] = $job;
            }
            if (empty($stillFailing)) break;

            echo "  [Stage1] {$slug}: retrying " . count($stillFailing) . " failed/empty chunk(s), attempt {$attempt}/{$max_chunk_attempts}...\n";
            $retry_pool    = min(count($stillFailing), 8);
            $retry_results = $this->claude->messageBatch($stillFailing, $retry_pool, ['cache_prefix' => $this->cachePrefix()]);
            foreach ($retry_results as $key => $r) {
                $results[$key] = $r;
            }
        }

        $parts = [];
        $failed_chunks = [];
        for ($n = 1; $n <= $total; $n++) {
            $key = "chunk-{$n}";
            $r    = $results[$key] ?? null;
            $text = is_array($r) ? (string) ($r['text'] ?? '') : '';
            if (trim($text) === '') {
                $err = is_array($r) ? (string) ($r['error'] ?? 'empty response') : 'missing result';
                echo "  [Stage1] {$slug}: chunk {$n}/{$total} permanently failed after {$max_chunk_attempts} attempts: {$err}\n";
                error_log(sprintf(
                    'ERROR: rebuildChunked() chunk %d of %d permanently failed for page "%s" after %d attempts (%s). That section is MISSING from the reassembled page.',
                    $n,
                    $total,
                    $slug,
                    $max_chunk_attempts,
                    $err
                ));
                $failed_chunks[] = $n;
                continue;
            }
            $parts[] = $this->cleanHtml($text, $slug);
        }

        if (!empty($failed_chunks)) {
            $this->incompleteChunkSlugs[$slug] = $failed_chunks;
        }

        echo "  [Stage1] {$slug}: chunked rebuild: {$total} parts, " . count($parts) . " succeeded"
           . (!empty($failed_chunks) ? ', ' . count($failed_chunks) . ' PERMANENTLY MISSING (chunk ' . implode(', ', $failed_chunks) . ')' : '')
           . "\n";

        $joined = implode("\n", $parts);

        return $hasWrapper ? ($wrapperOpen . $joined . $wrapperClose) : $joined;
    }

    /**
     * Clean Claude's HTML response: strip markdown fences, <!doctype>,
     * <html>, <head>...</head>, <body> wrapper tags. Return inner body HTML.
     * Uses strlen/substr/preg_* only — no mb_* functions.
     *
     * @param string $response The response text from Claude
     * @param string $slug     The page slug (for error reporting)
     * @return string Cleaned HTML (body content only, no wrapper tags)
     */
    public function cleanHtml(string $response, string $slug): string
    {
        $html = $response;

        // Strip markdown fences
        $html = preg_replace('/^```(?:html)?\s*/m', '', $html);
        $html = preg_replace('/\s*```\s*$/m', '', $html);

        // Strip <!doctype>
        $html = preg_replace('/<\s*!DOCTYPE\s+html\s*>/i', '', $html);

        // Strip <html> and </html> tags
        $html = preg_replace('/<\s*html[^>]*>/i', '', $html);
        $html = preg_replace('/<\s*\/html\s*>/i', '', $html);

        // Strip <head>...</head> block entirely
        $html = preg_replace('/<\s*head[^>]*>.*?<\s*\/head\s*>/is', '', $html);

        // Strip <body> opening tag (keep content)
        $html = preg_replace('/<\s*body[^>]*>/i', '', $html);

        // Strip </body> closing tag
        $html = preg_replace('/<\s*\/body\s*>/i', '', $html);

        // Strip <script>...</script> blocks
        $html = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/script\s*>/is', '', $html);

        // Strip <style>...</style> blocks
        $html = preg_replace('/<\s*style\b[^>]*>.*?<\s*\/style\s*>/is', '', $html);

        // v4.2.0: strip the leading fixed-position site nav, if the rebuilt
        // page transcribed it. header.php now carries this exact nav
        // (transcribed verbatim by DeterministicGen), so leaving it in the
        // page body would double it up. Conservative on purpose: only a
        // <nav> that opens the body content (a direct child of the body
        // wrapper, allowing only leading whitespace/comments before it) and
        // whose class attribute contains 'fixed' is removed. A nav used
        // inside page content further down the DOM is never touched.
        $html = $this->stripLeadingFixedNav((string) $html);

        // Strip the SITE FOOTER only — the transcribed page often includes the
        // site's own <footer> (copyright + nav), which would duplicate the theme's
        // footer.php. Remove ONLY the LAST <footer> block, and ONLY if it looks
        // like a real site footer (copyright text). This never touches nested
        // content <footer>/<header> used inside cards/sections (those are page
        // content). Site header/nav exclusion is handled by the system prompt +
        // the theme's header.php; we do NOT regex-strip <header> (too easy to
        // remove a page's own content header).
        if (preg_match_all('/<\s*footer\b[^>]*>.*?<\s*\/footer\s*>/is', (string) $html, $fm, PREG_OFFSET_CAPTURE)) {
            $idx = count($fm[0]) - 1;
            $blk = (string) $fm[0][$idx][0];
            $off = (int) $fm[0][$idx][1];
            if (preg_match('/©|&copy;|copyright|rights reserved|all rights/i', $blk)) {
                $html = substr($html, 0, $off) . substr($html, $off + strlen($blk));
            }
        }

        // v4.3.0 Item 9: strip frozen dynamic widget snapshots baked into the
        // rebuilt HTML (date strings like "Tuesday, 14 July 2026", weather like
        // "23°C Overcast") so the converted theme doesn't carry visibly stale copy.
        $html = DeterministicGen::stripFrozenDynamicWidgets((string) $html);

        $html = trim((string) $html);

        return $html;
    }

    /**
     * v4.2.0: remove a leading <nav class="...fixed...">...</nav> block, but
     * only when it is (a) the first real element in the body content, aside
     * from leading whitespace/HTML comments, and (b) its class attribute
     * contains 'fixed'. This targets exactly the site-wide nav header.php now
     * transcribes verbatim, and avoids touching any <nav> used as page
     * content further down the markup.
     */
    private function stripLeadingFixedNav(string $html): string
    {
        // Skip leading whitespace and HTML comments to find the first tag.
        $lead = preg_replace('/^(\s|<!--.*?-->)+/s', '', $html);
        if (!preg_match('/^<nav\b([^>]*)>/i', (string) $lead, $om, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $attrs = $om[1][0];
        if (!preg_match('/class\s*=\s*["\'][^"\']*\bfixed\b[^"\']*["\']/i', $attrs)) {
            return $html;
        }

        // Depth-count to the matching </nav>, in case of nested <nav> tags.
        $openRe  = '/<nav\b[^>]*>/i';
        $closeRe = '/<\/nav\s*>/i';
        $lead_offset = strlen($html) - strlen((string) $lead);
        $pos   = $lead_offset + strlen($om[0][0]);
        $depth = 1;
        $len   = strlen($html);

        while ($pos < $len) {
            $hasOpen  = preg_match($openRe, $html, $om2, PREG_OFFSET_CAPTURE, $pos);
            $hasClose = preg_match($closeRe, $html, $cm2, PREG_OFFSET_CAPTURE, $pos);
            if (!$hasClose) return $html; // unbalanced — leave untouched

            $openAt  = $hasOpen ? $om2[0][1] : -1;
            $closeAt = $cm2[0][1];

            if ($hasOpen && $openAt < $closeAt) {
                $depth++;
                $pos = $openAt + strlen($om2[0][0]);
                continue;
            }

            $depth--;
            $pos = $closeAt + strlen($cm2[0][0]);
            if ($depth === 0) {
                return substr($html, 0, $lead_offset) . substr($html, $pos);
            }
        }

        return $html; // never balanced — leave untouched
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Extract CSS rules that match classes used in the given HTML.
     * Ported from SectionGen::extractRelevantCss().
     */
    private function extractRelevantCss(string $html, int $cap = 15000): string
    {
        if (empty($this->css_bundle)) return '';

        // Extract class names from the HTML
        $classes = [];
        if (preg_match_all('/class=["\']([^"\']+)["\']/', $html, $m)) {
            foreach ($m[1] as $classList) {
                foreach (preg_split('/\s+/', $classList) as $cls) {
                    $cls = trim($cls);
                    if ($cls) $classes[$cls] = true;
                }
            }
        }

        // Find matching CSS rules (capped)
        $relevant = '';
        $css_rules = preg_split('/(?<=\})/', $this->css_bundle);
        foreach ($css_rules as $rule) {
            foreach (array_keys($classes) as $cls) {
                if (str_contains($rule, '.' . $cls)) {
                    $relevant .= $rule . "\n";
                    break;
                }
            }
            if (strlen($relevant) > $cap) break;
        }

        return $relevant;
    }
}
