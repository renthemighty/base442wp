# B442WP Builder Changelog

All behavioural changes to the builder (ThemeBuilder / DeterministicGen /
StaticRebuilder / VisualGate / worker) are recorded here. The running version is
`ThemeBuilder::BUILDER_VERSION` and is echoed at the start of every build so
cron.log records which version built each job.

## 4.4.0 - 2026-08-11 - Output-quality caps removed: page cap, product extraction paid-tier fix, media cap, WXR truncation

Product owner's rule for this converter is accuracy is the only target; cost and time are explicitly not constraints. This release strips out or raises every fixed-cost cap in ThemeBuilder.php that was clipping output fidelity, while keeping the genuine correctness rules (skippable login/admin/route-param slugs, per-item detail and product-slug handling) exactly as they were: those produce correct output and were never fidelity caps. Other agents changed the scanner page timeout/pool sizes and StaticRebuilder chunking in the same release; this entry covers ThemeBuilder.php only.

### Paid-tier under-delivery bug (highest-value fix)
`extractProductsWithClaude()` hardcapped every job at 25 products and truncated the combined input HTML to a flat 150,000 chars before Claude ever saw it, regardless of what the customer paid for. `$this->product_limit` was already being set correctly from the paid tier in the constructor (`conversion_tier_from_product_count()` in bootstrap.php supports tiers up to woo250 = 250 products), but the extraction call never read it, so every customer on woo100 or woo250 received at most 25 products, silently. Fixed:
- The cap is now driven by `$this->product_limit` (falls back to `self::DEFAULT_PRODUCT_LIMIT` = 250, never 25, if unset or ≤ 0).
- The single 150,000-char truncation is replaced with chunked extraction: the combined HTML is split into ~120,000-char chunks on a tag boundary (`splitHtmlIntoChunks()`, breaks on `>` rather than mid-tag), each chunk is sent through its own Claude call with the same system prompt, and results are merged. Duplicate product names (case-insensitive, trimmed) are deduplicated, keeping whichever entry has more populated fields (`countPopulatedProductFields()`). Extraction stops early once the merged count reaches the effective limit.
- The system prompt now states the job's real maximum (`This job's maximum is {limit} products`) instead of the literal "Maximum 25 products".
- `max_tokens` on the extraction call raised from 12000 to 32000.
- Every run now logs chunks sent, products returned per chunk, and the final merged count, so under-delivery can never again go unnoticed.
- The constructor's own fallback (`$this->product_limit = 25` when `has_woocommerce` is true but the scan's product count resolved the tier to 0) also hardcoded 25, raised to `self::DEFAULT_PRODUCT_LIMIT` (250) for the same reason.
- A second, smaller truncation upstream of the extractor was also clipping input before it ever reached the fix above: each scanned page's body was capped at 60,000 chars before being appended into the combined extraction HTML. Raised to `self::MAX_TEXT_CHARS` (2,000,000): at 60,000 chars/page, products deep in a large scanned page could be invisible to the extractor no matter how the extractor itself was fixed.

### Page cap: 25 → 200
`resolveScanPageSet()`'s sub-page cap raised from a bare `25` literal to `self::MAX_PAGES = 200`. The stale docblock ("cap 24 sub-pages (+ home = 25 total)") is corrected to describe the real cap and value. The `_dropped_slugs` tracking and nav-404 stub safety net (v4.3.0 Item 6) are unchanged: they still fire if the new, much higher ceiling is ever hit.

### Media import cap: 200 → 2000
The Stage-2.5 image import total cap raised from a bare `200` literal to `self::MAX_IMAGES = 2000`.

### Silent WXR truncation: 150,000 → 2,000,000, now logged
The per-page WXR content truncation (`$wxr_content`) was the only cap in the file that fired with zero logging. Threshold raised to `self::MAX_TEXT_CHARS` (2,000,000) and a warning is now logged naming the page slug and both the original and truncated lengths whenever it fires, matching the logging style used elsewhere in the file.

### Other caps found and raised (same audit pass)
Two `pageBodyForPrompt()` fallback truncations (used when Stage 1's Claude rebuild fails outright and the raw scanned HTML becomes the actual page/WXR content) were capped at 100,000 chars, raised to `self::MAX_TEXT_CHARS` (2,000,000) so an emergency fallback page is not clipped more tightly than the WXR safety net.

### New class constants (single place to change each ceiling)
```
public const MAX_PAGES = 200;
public const MAX_IMAGES = 2000;
public const MAX_TEXT_CHARS = 2000000;
public const DEFAULT_PRODUCT_LIMIT = 250;
```

### Changed
- `ThemeBuilder.php`: BUILDER_VERSION = 4.4.0. New constants `MAX_PAGES`, `MAX_IMAGES`, `MAX_TEXT_CHARS`, `DEFAULT_PRODUCT_LIMIT`. `resolveScanPageSet()` uses `self::MAX_PAGES`, docblock corrected. `extractProductsWithClaude()` rewritten: tier-driven limit, chunked extraction, dedup-and-merge, updated prompt, `max_tokens` 32000, per-chunk logging. New helper methods `splitHtmlIntoChunks()` and `countPopulatedProductFields()`. Media import cap uses `self::MAX_IMAGES`. WXR truncation uses `self::MAX_TEXT_CHARS` and logs when it fires. Two `pageBodyForPrompt()` fallback caps and the constructor's `product_limit` fallback use `self::MAX_TEXT_CHARS` / `self::DEFAULT_PRODUCT_LIMIT` respectively.

### Follow-up: single-call cap tied to the chunking threshold, lost chunks no longer silent
Two remaining truncation risks in the same StaticRebuilder chunking path (the "other agents" changes in the paragraph above) were closed.

First, `preparePageJob()` was still capping `pageBodyForPrompt()` at a hardcoded `45000`, independent of `needsChunking()`'s own default cap. Audited both call sites: `ThemeBuilder.php` calls `needsChunking()` with no explicit cap, so it always used the 45000 default, which happened to equal the hardcoded 45000 in `preparePageJob()`. So no page was actually being truncated today, the two numbers only matched by coincidence, with no shared source of truth tying them together. Any future change to either number alone would have reopened a real truncation window. Fixed by adding `StaticRebuilder::SINGLE_CALL_CAP = 45000` and deriving both `needsChunking()`'s default parameter and `preparePageJob()`'s `pageBodyForPrompt()` call from it. The hardcoded `45000` literal at the `preparePageJob()` call site is gone. The `pageBodyForPrompt()` warning log stays in place as the alarm that fires if this invariant is ever violated.

Second, in `rebuildChunked()`, a chunk that failed or came back empty from `messageBatch()` was skipped with a bare `continue`, logged only to the console. The reassembled page silently missing a whole section, with nothing downstream aware of it. Fixed:
- A failed or empty chunk is now retried up to twice more (3 attempts total) by resubmitting just the still-failing chunks through `messageBatch()`, the same collect-the-failing-subset-and-resubmit shape ThemeBuilder already uses for its gate retry round. This is on top of `messageBatch()`'s own internal retry of transient HTTP errors.
- A chunk still empty after all retries now logs loudly via `error_log()`, naming the page slug and which chunk index of how many permanently failed, in addition to the console line.
- New `StaticRebuilder::$incompleteChunkSlugs` (slug to list of lost chunk indices) records the outcome of the most recent `rebuildChunked()` call. `ThemeBuilder.php` checks it after both call sites (the initial Stage 1 pass and the gate retry pass) and folds any hit into the existing `$degraded` list, the same mechanism already used for visual-gate failures, so a page missing a section is never reported as a clean success. A small `in_array` guard prevents the same slug being listed twice in `$degraded` if it is later also flagged by the visual gate.

## 4.3.0 - 2026-07-14 - Fidelity fixes: blogname, nav 404 guard, permalinks, CSS cache-bust, frozen widget strip

Five fidelity fixes from browser comparison testing of the discoverblackheath conversion.

### Item 5 — blogname from visible header, not document.title
`DeterministicGen::generateDemoContent()` now resolves the blogname from
`live_scan.site_title` (the visible brand text captured from the rendered DOM by
the Playwright scanner) before falling back to the human-passed `theme_name`. The
old v4.2.4 code always used `theme_name`, which for Base44 SPAs is the app-store
`document.title` string (e.g. "Blackheath Horizon" instead of "Discover
Blackheath"). `blogdescription` remains always-empty — never sourced from the meta
description tag (which carries app-store copy, not a real site tagline). The
resolution order is now: `live_scan.site_title` → `theme_name`.

### Item 6 — Nav 404 guard for cap-dropped pages
`resolveScanPageSet()` now tracks real content pages dropped when the 25-page cap
is hit and stores them in `$this->analysis['_dropped_slugs']`. After inner page
templates are written, `build()` cross-references the dropped slugs against the
scanned primary nav (`live_scan.nav`). Any dropped slug that appears as a nav
target gets a minimal stub `page-{slug}.php` (title h1 + "coming soon" notice) so
the nav link lands on an actual WP page instead of a 404. Logged as:
`→ page-{slug}.php stub created (nav link guard — page was cap-dropped)`.
A warning is also logged listing all dropped slugs when the cap fires.

### Item 7 — Permalink structure set on theme activation
`generateDemoContent()` now adds `update_option('permalink_structure',
'/%postname%/'); flush_rewrite_rules(true);` inside `{prefix}_run_import()`, which
runs via the `after_switch_theme` hook (and the demo-content admin page). Pretty
URLs now work immediately after theme activation without an admin needing to visit
Settings → Permalinks first.

### Item 8 — theme.css content-hash version to bust ADC/LiteSpeed cache
The generated `functions.php` now uses a content hash of the theme.css file as the
`wp_enqueue_style` version string for the `{td}-theme` handle:
`substr(md5_file(get_template_directory() . '/assets/css/theme.css'), 0, 8)`.
Falls back to the theme's `{cp}_VERSION` constant when the file is missing. This
ensures the ADC/LiteSpeed edge cache is invalidated automatically on every redeploy
where the CSS content changes, without any manual version bumping.

### Item 9 — Frozen date/time and weather widget stripping
New static method `DeterministicGen::stripFrozenDynamicWidgets(string $html):
string`. Detects and strips inline elements (`span`, `time`, `p`, `div`, `small`,
`em`, `strong`) whose tag-stripped text content consists entirely of a frozen
dynamic value: date strings (e.g. "Tuesday, 14 July 2026", "Monday July 14"),
time strings ("14:32", "2:32 PM"), or weather readings ("23°C Overcast", "Partly
Cloudy 18°C"). Conservative: only removes elements that are wholly the frozen
content, never elements with mixed real copy. Called from:
- `DeterministicGen::rewriteChromeMarkup()` — strips from header/footer chrome
  before it is written to header.php / footer.php.
- `StaticRebuilder::cleanHtml()` — strips from rebuilt page bodies before they
  reach the visual gate and templatization.

### Changed
- `ThemeBuilder.php`: BUILDER_VERSION = 4.3.0. `resolveScanPageSet()` tracks
  `_dropped_slugs`. `build()` creates stub page templates for nav-linked
  cap-dropped pages.
- `DeterministicGen.php`: `generateDemoContent()` resolves blogname from
  `live_scan.site_title` first; adds permalink/flush_rewrite_rules call.
  `generateFunctions()` enqueue uses `md5_file()` content hash for theme.css.
  New `stripFrozenDynamicWidgets()` static method. `rewriteChromeMarkup()` calls
  it before returning.
- `StaticRebuilder.php`: `cleanHtml()` calls
  `DeterministicGen::stripFrozenDynamicWidgets()` after the existing cleanup.

## 4.2.0 - 2026-07-13 - CSS/JS bundle fetch on fred-drive, packaging perms, chunked home rebuild, chrome-faithful header/footer

Live diagnosis of discoverblackheath.co.uk (Base44 Tailwind SPA) found four root causes producing a broken theme, all fixed here.

1. fred-drive.php never fetched the CSS/JS bundles. worker.php fetches them from the app server's cache, but fred-drive.php's standalone path skipped this step entirely, so ThemeBuilder::loadBundle() found nothing and the theme shipped Tailwind utility classes with no compiled CSS behind them. New fetchLiveBundles() (fred-drive.php) parses the scanned home page for <link rel=stylesheet> and <script src> tags, resolves them against live_url with resolveUrl(), downloads each with a browser UA via fetchUrl() (curl, 20s timeout, file_get_contents fallback), and writes the concatenated results to bundles/bundle-0.css and bundle-0.js, mirroring the app-server path so ThemeBuilder needed no changes. Failures are non-fatal.

2. Packaged file permissions. MediaImporter wrote images at 0640, causing a webserver 403 on every image, and ThemeBuilder staged directories at 0750. MediaImporter now creates its directory at 0755 and chmods downloaded images to 0644. ThemeBuilder's directory mkdirs are now 0755 throughout, and a new normalizePermissions() pass chmods every file to 0644 and every directory to 0755 across the staged theme dir right before zipping. zipDirectory() also stamps Unix external attributes (setExternalAttributesName, 0644 for files) so extractors that trust the zip's stored mode still unpack correctly.

3. Home page tail truncation. StaticRebuilder::pageBodyForPrompt capped page bodies at 45,000 chars, a guard against oversized Claude output. On discoverblackheath the home DOM exceeded the cap and the last 7 sections (business carousel, map, trails, publications, newsletter) were silently dropped. pageBodyForPrompt() now delegates to a new uncapped extractBody(), and a new needsChunking() / rebuildChunked() pair handles oversized pages: splitIntoChunks() tokenizes the body by tag depth and splits only at top-level element boundaries into runs under the cap, and rebuildChunked() sends each chunk through its own Claude call (same system prompt, told which part of how many, instructed to output only the transcribed sections with no wrapper), concatenating the cleaned parts in document order. ThemeBuilder now checks needsChunking() per page and routes oversized pages to rebuildChunked() instead of the batch job. The single-call path is unchanged for everything under the cap, and the return shape matches cleanHtml()'s output, so the visual gate and templatize step needed no changes.

4. Header/footer chrome fidelity. DeterministicGen generated a generic WP menu header and a near-black generic footer, nothing like the original site's fixed translucent nav or 3-column dark footer. New scanHomeHtml() reads the home page's scanned rendered HTML. generateHeaderFromScannedChrome() transcribes the original's <nav> (or the first element matching class fixed top-0 when no <nav> tag exists) verbatim via extractFirstBalanced()/extractBalancedFrom(), a depth-scanning tag-balance tokenizer used instead of DOMDocument because scanned HTML can be malformed. generateFooterFromScannedChrome() does the same for the last <footer> via extractLastBalanced(). Both pass the extracted markup through rewriteChromeMarkup(), which strips <script> blocks and inline event handlers, rewrites internal <a href> targets to esc_url(home_url(...)), and rewrites the logo <img src> to the packaged theme asset when MediaImporter downloaded it, matched by exact URL, falling back to basename. Class attributes are kept verbatim since the v4.2.0 bundle fetch, fix 1, now supplies the CSS that backs them. generateHeader()/generateFooter() try the scanned-chrome path first and fall back to the pre-4.2.0 generic generation, renamed generateFooterGeneric(), when no scan HTML or no matching element is found. Companion fix: since header.php now carries the transcribed original nav, StaticRebuilder::cleanHtml gained stripLeadingFixedNav(), which conservatively removes a leading <nav> from the rebuilt page body only when it opens the body content, allowing only leading whitespace or comments before it, and its class attribute contains fixed, so the nav is not duplicated between header.php and the page body.

### Changed
- fred-drive.php: fetchLiveBundles(), fetchAndConcat(), resolveUrl(), fetchUrl() added, running after the site scan and before ThemeBuilder.
- ThemeBuilder: BUILDER_VERSION = 4.2.0. Directory mkdirs changed from 0750 to 0755. New normalizePermissions() called before zipDirectory(); zipDirectory() now stamps 0644 Unix external attributes per file. Stage 1 page loop now splits pages into batch_jobs and chunked_slugs via StaticRebuilder::needsChunking(), running chunked pages through rebuildChunked() after the batch.
- StaticRebuilder: pageBodyForPrompt() now delegates to new extractBody() (uncapped) then applies the cap. New needsChunking(), splitIntoChunks(), rebuildChunked(). cleanHtml() gained stripLeadingFixedNav(), called after the script/style strip and before the site-footer strip.
- DeterministicGen: new scanHomeHtml(), extractBalancedFrom(), extractFirstBalanced(), extractLastBalanced(), rewriteChromeMarkup(), generateHeaderFromScannedChrome(), generateFooterFromScannedChrome(). generateHeader()/generateFooter() try the scanned-chrome path first, falling back to the previous behaviour (generateFooterGeneric() is the renamed pre-4.2.0 generateFooter() body).
- MediaImporter: image directory created at 0755 instead of 0750; downloaded images chmod'd to 0644 instead of 0640.

## 4.1.5 — 2026-06-21 — %THEMEURI% token for image srcs (fixes broken imgs in post_content)

Rewriting img srcs to `<?php echo get_template_directory_uri(); ?>` works in PHP templates but renders as literal text in WP post_content (PHP never runs there) → broken images on imported pages. Fix: Stage-2.5 emits the neutral token `%THEMEURI%/assets/images/X`; template generators (generateFrontPageFromHtml/generatePageFromHtml) expand it to `<?php echo get_template_directory_uri(); ?>`; generateDemoContent expands it to `get_template_directory_uri()` at import time; generateWXR expands to root-relative `/wp-content/themes/<prefix>/assets/images`. Verified: NUE home 0 broken images.

## 4.1.4 — 2026-06-21 — Chrome-strip precision (site-footer-only)

4.1.3's chrome-strip removed ALL `<header>/<footer>` (over-stripped nested content; regressed schedules). Now StaticRebuilder::cleanHtml removes ONLY the last `<footer>` and only if it has copyright signals — never nested content. Header chrome handled by the system prompt.

## 4.1.3 — 2026-06-19 — Strip site chrome + import/rewrite img srcs + sideload product images

QA on the NUE store (Simon: 4/10). Three fixes: (1) StaticRebuilder strips the transcribed site footer (was duplicating theme footer.php); (2) ThemeBuilder Stage-2.5 collects img srcs from validated bodies, imports them locally, rewrites to local theme assets (was keeping root-relative srcs → 404); (3) generateDemoContent sideloads product `_product_image_url` as the featured image (was WC placeholder).

## 4.1.2 — 2026-06-19 — Large-page stall guard

One big page (stacks) produced >32k-token HTML → truncated → 64k retry → CURL_TIMEOUT → backoff loop → messageBatch hung 8+ min. Guards: StaticRebuilder input cap 100k→45k chars (output stays under cap, no truncation); ClaudeClient MAX_JOB_SECONDS=240 per-job wall-clock budget (a job over budget is finalized with best/last output, never re-queued, so one page can't stall the batch).

## 4.1.1 — 2026-06-19 — Reliability: pool cap, skip product/auth pages, batch-gate fault isolation

(1) Pool 16 OOM-killed the worker on the 3.7GB VPS → capped stage1/retry pool to 6. (2) resolveScanPageSet skips `^product[-_/]` per-item product pages (WC renders them) + page cap 24→12; isSkippableSlug adds 'auth'. NUE: 22 Stage-1 pages → 7. (3) gate-compare.js runWithConcurrency had an unguarded `await fn()` — one throw dropped every queued task → all pages `n/a`; fixed with per-task try/catch + sparse-slot recovery so every entry is always reported.

## 4.1.0 — 2026-06-19 — Parallelize gate + scan + wide rebuild pool (speed)

Cut single-job wall-clock by parallelizing the two serial bottlenecks. No behavioural change to output; same ZIP, same degraded contract.

### Changed
- **VisualGate** — new `checkBatch()` renders ALL pages in ONE chromium with a bounded page-pool (concurrency 5) via `gate-compare.js --batch=<manifest>`, instead of one chromium process per page sequentially. ThemeBuilder Stage 2 now: gate all pages in one pass -> collect review/fail -> re-rebuild failures in one messageBatch -> re-gate. (`check()` single-page mode kept for back-compat.)
- **scanner/site-scan.js** — BFS now captures pages in concurrent waves (CONCURRENCY=4, one browser, page-pool) instead of one URL at a time. Ordering/dedup/25-page cap/slug assignment preserved deterministically; pages closed in finally to bound RAM.
- **scanner/gate-compare.js** — added batch mode (`--batch --concurrency`, one browser, page-pool, per-slug + aggregate batch-report.json).
- **ThemeBuilder Stage 1** — rebuild batch pool widened 6 -> min(pages, 16) so all pages rebuild in one API wave (cost is no object; API concurrency only).

### Notes
- Chromium concurrency bounded (4 scan / 5 gate) to protect Fred RAM — physical limit, not cost. API/token concurrency is unconstrained.
- Expected: gate ~6x faster on multi-page sites; scan ~3-4x faster (biggest win on Lovable's ~6min scans); rebuild one wave instead of two.

## 4.0.0 — 2026-06-18 — Static-rebuild + visual-gate architecture (Pacific Glasses method)

Full re-architecture of the build spine to the manual conversion method: scan the
live site -> AI rebuilds each page as faithful semantic HTML reusing the scraped
CSS bundle -> a hard pixel-diff gate verifies each page vs its original screenshot
-> deterministic code templatizes the validated HTML into classic PHP. Claude no
longer writes PHP; PHP is produced only by mechanical transform of gate-validated
HTML. This eliminates the entire class of codegen defects (bloginfo('name') leaks,
undefined Walker_Nav_Menu, {$nav_items_code} tokens, hero double-render, mid-file
truncation) by construction.

### Added
- **StaticRebuilder.php** — Stage 1. Faithful whole-page HTML transcription (no
  PHP/WP tags), grounded in captured computed styles + palette + fonts; reuses the
  original class names so the scraped stylesheet applies. Replaces SectionGen.
- **VisualGate.php** + **scanner/gate-compare.js** — Stage 2. Renders the rebuilt
  static HTML at 1440x900 (file://) and pixelmatch-diffs it against the Stage-0
  screenshot. pass <10% / review <25% / fail >=25%. review|fail -> one retry with a
  diff note appended; still failing -> page kept (best attempt) and flagged in
  `degraded[]`. Never silently ships a broken page; never aborts the job.
- **DeterministicGen::generateFrontPageFromHtml() / generatePageFromHtml()** —
  inline gate-validated HTML into front-page.php / page-{slug}.php (strips stray
  PHP tokens defensively).
- **ClaudeClient::getCalls()** — authoritative API-call counter (single + each
  batch job) so ai_calls is accurate.

### Changed
- **ThemeBuilder::build()** rewritten as a thin 5-stage orchestrator. Inner pages
  now source from the SITE SCAN directly (was: zip sub_pages, which was empty for
  many jobs and silently dropped real pages like shop/about). Home dedup seeds the
  scan home signature so 'home-2'/trailing-slash duplicates drop; per-item detail
  templates (*detail, *article) skipped.
- **worker.php** reports `degraded` slugs to fred-complete + cron.log.

### Removed
- **CodeReviewer.php, FaithfulPostProcess.php, GuardrailChecker.php** and
  **scanner/qa-compare.sh** — the generate-then-repair crutches for AI-written PHP,
  unnecessary once PHP is deterministic (~1,200 lines removed).

### Verified (2026-06-18, real owner jobs on Fred)
- Job #37 The Cigar Box (base44, WooCommerce): home/shop/about gate pass at
  2.3% / 1.5% / 1.9%; WXR 2 pages + 11 products; 4 AI calls / 84k in tokens
  (vs old baseline ~43 calls / 344k). All generated PHP lint-clean, zero artifacts.
- Job #124 Inkwell Co. (lovable, WooCommerce): clean installable theme, 19 PHP
  files lint-clean, zero artifacts. Visual gate flags all pages ~34% (Lovable
  code-split CSS / token-gated SPA / empty nav) -> shipped as degraded. Known
  follow-up: resolve images+fonts in the gate render and capture Lovable runtime
  CSS to raise Lovable fidelity.

## 3.2.3 — 2026-06-11 — Unicode-escape decode pass + guardrail single-char regex fix

Second v3.2.2 Inkwell build (job 124) raised two guardrail flags. One was a
real generation defect, one a checker false positive; both fixed at the
converter (output never hand-edited).

### Fixed
- **FaithfulPostProcess — PASS 3 `decodeUnicodeEscapes()` (real defect)**:
  the model transcribed source copy carrying typographic characters (curly
  quotes U+2018/U+2019/U+201C/U+201D, word joiner U+2060) as literal
  JSON-style `\uXXXX` escapes inside PHP single-quoted strings
  (testimonials.php: `'...That’s all I needed to know.⁠”'`).
  PHP single quotes do not interpret `\u`, so the page would render literal
  "u2019" text. The new pass decodes `\uXXXX` sequences to real UTF-8
  characters — surrogate pairs decoded pairwise, lone surrogates left, and
  ASCII-range escapes (quote/backslash/control) left untouched so PHP
  string syntax can never be altered. No mbstring dependency (2.3.1 rule).
  Faithful by construction: the scanned source DOM contains the actual
  typographic characters. Runs on every section template via process().
- **GuardrailChecker — defaults regex `{1,200}` (false positive)**: a
  single-character literal like `esc_html( '8' )` (review counts) could not
  satisfy the old `{2,200}` minimum; the regex engine backtracked past the
  real closing quote and captured a junk markup span (close-php-tag +
  span/div markup) which was then flagged as an unsourced string
  (shop/product-grid.php). One-char captures are now allowed and are
  filtered by the existing alnum-skip / isCheckable() length gate.
- **ThemeBuilder** — `BUILDER_VERSION = '3.2.3'`.

### Verified (unit, Fred + relay)
- New extractor on the real generated product-grid.php: zero captures
  containing close-php-tag fragments.
- decodeUnicodeEscapes on the real flagged string: 6 sequences decoded to
  “⁠…’…⁠”; `'` left as-is; `😀` → 😀.

## 3.2.2 — 2026-06-11 — max_tokens truncation retry: never package a mid-file cut

First Inkwell build (job 124) shipped a TRUNCATED
template-parts/product-list.php — the 6-product section's verbose Tailwind
markup exceeded the section-conversion job's 8,000-token output cap and the
response was cut mid-attribute (`<div class="flex`) at EOF. php -l passes
(the cut is inside HTML, not PHP), the CodeReviewer gate passed, and only
the GuardrailChecker caught it (the dangling unclosed tag fragment survives
tag-stripping and became an "unsourced string"). Root cause is in the
client, not the reviewer: stop_reason "max_tokens" was silently accepted.

### Added
- **ClaudeClient** — `MAX_TOKEN_RAISES = 2`, `MAX_TOKENS_CEILING = 32000`
  (non-streaming requests must stay inside CURL_TIMEOUT=300s).
  - `message()`: when the response's stop_reason is "max_tokens", the
    identical request is retried with a doubled max_tokens cap, up to 2
    raises / the 32K ceiling. Logged
    `[Claude] output truncated at max_tokens — retrying with cap N`.
  - `messageBatch()`: a harvested 2xx response with stop_reason
    "max_tokens" is NOT accepted — the job is requeued into the live pool
    with a doubled cap (`mt_raises` counter on the queue item, same 2-raise
    /32K bounds). Truncated-response usage is still accounted (it was
    billed). Logged per job key.
- **ThemeBuilder** — `BUILDER_VERSION = '3.2.2'` (no builder logic change;
  fix is entirely in the API client).

### Notes
- Section jobs keep their 8,000 starting cap — the raise is reactive, so
  normal sections pay nothing extra; only oversized sections re-run at
  16K/32K.
- Guardrail value confirmed: "markup-fragment-as-string" hallucination
  flags are a reliable truncation signature worth investigating, not noise.

## 3.2.1 — 2026-06-11 — Auth query passthrough: token-gated previews (unpublished Lovable)

First Lovable end-to-end (job 124, Inkwell Co.) targets an UNPUBLISHED
project served only at id-preview--{project}.lovable.app behind a
`?__lovable_token=<JWT>` gate (token valid ~1 week). Verified behaviour of
the gate: any request carrying the token returns 200 AND sets an HttpOnly
`lovable-auth` cookie (Path=/, SameSite=None, Partitioned); any request
without token or cookie 302s to lovable's login. Page navigations are
gated; the `/__l5e/assets-v1/` image assets on the same origin answered
200 without the token on this project — passthrough still applied to them
as belt-and-braces (other builders/projects may gate assets).

### Added
- **scanner/site-scan.js v1.4.0 (Fred only)** — AUTH QUERY PASSTHROUGH.
  The start URL's query params are stored once and re-applied at goto time
  (loadPage) to every same-origin navigation — seeded routes and discovered
  anchors alike. Canonical URLs everywhere else (crawl queue, dedupe,
  manifest, slugs) remain token-free: normalizeUrl still strips u.search,
  so downstream consumers never see the token. The Playwright context also
  keeps the lovable-auth cookie from the first tokened load; the per-request
  query is the recovery path if that cookie fails/expires mid-crawl.
  Stderr logs `Auth passthrough: N start-URL query param(s) …` when active.
  (The v1.4.0 SCANNER_VERSION constant was pre-bumped by an interrupted
  session; this entry is the feature that version number now denotes.)
- **MediaImporter** — `setAuthPassthrough(string $start_url)` stores the
  start URL's origin + query params; `applyAuthQuery()` appends them (never
  overwriting an existing key) to same-origin download URLs at curl time
  only. by_url keys, dedupe and filenames keep the original token-free URLs.
  Foreign-origin CDN URLs untouched.
- **ThemeBuilder::importMedia()** — when the conversion live_url carries a
  query string, enables the importer passthrough and logs
  `[Media] Auth query passthrough enabled for {host}`.
- **ThemeBuilder** — `BUILDER_VERSION = '3.2.1'`; worker scan log line
  reports v3.2.1.

### Verified (standalone scanner, Inkwell Co. preview)
16 pages, 0 errors in 89s: home/shop/bundles/about/wholesale/
order-confirmation/phases/testimonials/faq/contact + 6 /product/NN detail
pages (discovered via anchors). Nav captured verbatim from rendered DOM:
Inkwell Co. / Home / Shop / Bundles / About / Wholesale. site_title
"Inkwell Co. — Heals the work. Preserves the line.". 14 unique same-origin
image URLs collected. Seeds: 11 supplied, 1 enqueued (the rest already
discovered via rendered anchors or skipped: cart/checkout per SKIP_SLUG_RE).

## 3.2.0 — 2026-06-11 — Lovable/TanStack sources: zip route seeds, home segmentation fallback, WC flow-page skip

First non-Base44 source builder. Lovable exports are TanStack-router React
zips whose LIVE bundles are code-split: no route strings in the served JS,
no anchors in the SPA shell, and the app-side Analyzer sees only a thin
shell DOM for the home page. The zip, however, carries the full route table.
(Entry reconstructed 2026-06-11 from the deployed .bak-v320 diffs — the
session that shipped the code was stopped before writing the changelog.)

### Added
- **worker.php** — seed routes now also collected from the claim's
  `analysis.routes[]` (ZipExtractor → Analyzer route table; the only
  complete page-set source for code-split Lovable bundles). Dynamic route
  templates can never be scanned and are filtered from the seed set:
  TanStack `$param`, react-router `:param`, wildcards. Scan log line
  reports `source_builder=` (default base44).
- **ThemeBuilder** — HOME SEGMENTATION FALLBACK (live-URL-first / Lovable).
  When the analyzer's home sections are thin (SPA shell stubs; a section
  counts as rich only when its extracted html exceeds 400 bytes, and the
  fallback fires when rich sections are 0 or under half), the SCANNED home
  DOM is segmented by one AI batch job exactly like sub-pages, and the home
  section model (sections/section_order/section_content/section_classes) is
  rebuilt from real rendered markup; section_source is cleared (scanned DOM
  supersedes zip JSX), and SectionGen is re-instantiated on the new model.
  Base44 builds with rich analyzer sections are untouched.
  `BUILDER_VERSION = '3.2.0'`.
- **DeterministicGen** — dynamic-route skip extended to TanStack `$param`
  templates (previously `:param` and `*` only).
- **pages/api-fred-claim.php (app)** — skip_slugs extended with the
  WooCommerce-managed flow pages: cart, checkout, order-confirmation /
  orderconfirmation, thank-you/thankyou — WC provides live cart/checkout/
  thank-you; static copies must never be built. Sub-page candidate filter
  also drops TanStack `$param` slugs.
- **converter/analyzer/Analyzer.php (app, v3 analyzer)** — emits
  `source_builder` (from the zip parser's detection; "lovable" for
  Lovable/TanStack exports, default "base44") and `routes[]` (the complete
  navigable route set carried in the zip; TanStack
  `src/routes/about.tsx → /about`) in analysis_data; the TanStack/Lovable
  home route's REAL runtime title is used for theme identity.

## 3.1.2 — 2026-06-10 — Scanner text-stability wait: stop baking mid-flight counter values

Fixes wrong numbers baked into converted themes by the multi-page scanner
capturing the DOM while JS count-up animations were still incrementing.
Job 101 (coast-home-link.base44.app → euherbs.co) shipped "370+ Clients
Served / 68% Client Advocacy" where the source settles at "500+ / 100%" —
page.content() ran mid-animation. reducedMotion emulation does not stop
JS counters.

### Added
- **scanner/site-scan.js v1.3.0 (Fred only)** — TEXT-STABILITY WAIT before
  every DOM/screenshot capture. After the existing autoscroll (which
  triggers intersection-based counters) + 1500ms settle, the scanner
  samples `document.body.innerText` every 700ms and captures only once two
  consecutive samples are identical. Hard cap 10s per page — on cap it
  captures anyway and marks `unstable_text: true` plus `text_settle_ms`
  on that page's manifest entry. Generalizes beyond counters (typewriter
  effects etc.). Per-page settle timing logged to stderr
  (`text-stability {slug}: stable after Nms (K samples)`).
- **ThemeBuilder** — `BUILDER_VERSION = '3.1.2'` (no builder logic change;
  fix is entirely in the scanner).

### Verified (standalone, coast-home-link.base44.app)
Scanner v1.3.0 home capture: text stable after 1422ms (3 samples,
unstable_text not set). Captured DOM contains the FINAL stats values —
"20+" (Years of Experience), "500+" (Clients Served), "100%" (Client
Advocacy) — and zero mid-flight partials ("370+"/"68%" absent).

## 3.1.1 — 2026-06-10 — Section-height fidelity + invented-form strip + stats-color fix

Closes the residual visual-fidelity gap on the faithful Coast Realty build
(job 101 → euherbs.co). The v3.1.0 copy was correct on nav/identity/no-invented-
content, but the home page rendered ~1300px too tall (6194 vs ~4993px source)
and the contact form carried AI-invented interactivity. Home visual-compare vs
the job-101 reference scan went 27.5% → 6.0% (PASS, <10%). The only band still
over threshold is the height-tail/footer band (deterministic theme footer is not
source-transcribed — documented floor).

### New: FaithfulPostProcess.php — deterministic post-generation corrections
Run on every section template after the CodeReviewer gate (home sections +
scan-page sections). Two passes; both only REMOVE invented/anti-faithful markup,
never add copy. All visible strings preserved verbatim.

1. stripInventedFormInteractivity()
   - The source contact form is a static <form> with no method/action/handler/
     success state. The model reliably bolts on one of several invented forms:
     a PHP wp_mail handler + nonce + $submitted success branch; a hidden
     (display:none) "Message Received"/"Thank you … will be in touch" success
     block + "Sending…" loading span; and/or an AJAX <script>. GuardrailChecker
     flagged these but could not strip them.
   - The pass removes: the PHP submit-handler / state-init island; the
     if($submitted)/else success branch (keeps the form branch); wp_nonce_field
     + nonce inputs; method/action on the form; form-submit/AJAX <script> blocks;
     and the invented success/loading blocks — using a BALANCED tag walker so
     nested same-tag children don't truncate the match, and matching the block
     by display:none OR by a success id/class OR by the invented copy itself
     (robust to all three observed AI variants: PHP-handler, inline-hidden,
     CSS/JS-hidden).

2. restoreResponsiveGrids()
   - The model keeps the source's Tailwind responsive grid classes
     (md:grid-cols-3, lg:grid-cols-2 — present and working in the bundled
     compiled CSS) but ALSO emits an inline grid-template-columns:repeat(1,…)/
     :1fr that overrides them, collapsing multi-column layouts to one column and
     ~doubling section height (about, contact, services tab panels — the
     dominant source of the height inflation).
   - The pass is DECLARATION-CENTRIC (not element-centric) so it survives inline
     styles that embed PHP echoes with their own quotes (e.g. a display value
     emitted by a php echo). For each inline single-column declaration it
     inspects the enclosing element's opening tag for a multi-column signal — a
     responsive grid class OR a class the same-file <style> promotes to multiple
     columns at a breakpoint — and, when found, drops the inline override so the
     source's own responsive layout wins. CSS inside <style> blocks and genuine
     mobile-only single-column grids are left untouched.

### DeterministicGen — stats section guard made faithful
- The pre-3.1.1 "Stats section contrast guard (v2.2.0)" forced
  `section[class*="stats"] { background:#111111 !important; color:#fff … }` on
  every stats/counter section, assuming stats always render on dark. That is
  anti-faithful: Coast's stats band is a GOLD (#C9A96E) band with dark text, so
  the !important override painted it dark and made the dark labels invisible
  (drove the band-7 65% diff). Replaced with a visibility-only guard that keeps
  the reveal-animation safety (opacity:1/transform:none on [data-delay]) and
  forces NO colors — the section's transcribed inline palette now wins.

### ThemeBuilder
- BUILDER_VERSION → 3.1.1.
- Wires FaithfulPostProcess::process() into the home-section loop and the
  scan-page section loop, logging `[Faithful] <file>: {changes}` per edit.

### Verification (job 101 → euherbs.co)
- Guardrail PASS (0 hallucinations); nav 9 verbatim; no Sign In/View Listings;
  blogname "Coast Realty Solutions"; all 18 PHP files lint clean.
- Form strip: 0 occurrences of Sending / Thank you / will be in touch /
  Message Received / wp_mail / wp_nonce in the package.
- Section heights vs source: services 944 (938), about 1006 (1016), contact 837
  (827), stats band gold rgb(201,169,110) with dark labels. Total 5105 vs ~4993.
- Home visual-compare overall 6.04% — PASS. Bands 1-9 = 0.4-7.4%; band 10
  (26.6%) is the footer/height-tail artifact (deterministic footer, documented
  floor). /listings 200 with "Property Search".


## 3.1.0 — 2026-06-10 — FAITHFUL-COPY mode (verbatim nav, scanned identity, hallucination guardrail)

The converter now COPIES the source site in its entirety and CREATES NOTHING
that is not a copy. Proven by rebuilding Coast Realty Solutions (job 101,
coast-home-link.base44.app) and deploying to euherbs.co. Fixes every violation
in the prior Coast build: dropped nav items, invented "Listings"/"Sign In"/
"View Listings", invented hero search band, leaked WP blogname in the header,
and the browser title showing the host domain instead of the scanned site.

Root cause of the nav miss: coast-home-link is a single-page scroller whose nav
uses JS click-handlers (<button>), not <a href>. The scanner only read <a href>
so it captured 0 nav items and the builder fell back to the page list AND the
AI header invented labels/CTAs.

### Scanner — site-scan.js v1.2.0
- Captures a COMPLETE nav model from the rendered DOM, robust to JS-handler
  navs. For the primary nav/header container it enumerates every clickable
  element (a, button, [role=menuitem], [role=button]) in DOM order and emits
  `nav: [{text, kind, target}]` where kind = page|anchor|external|action.
  Target resolution precedence: real href -> page/external; data-*/aria-controls
  -> anchor; label matched to an on-page section heading/id (incl. a synonym
  map, e.g. Reviews->testimonials, Buyers/Sellers/Homeowners->services "How We
  Serve You") -> anchor; pure JS CTA -> action. Visible label text is NEVER
  changed; only the scroll/route target is resolved.
- Captures `sections: [{slug, heading, anchor}]`, header CTA buttons
  (`header_ctas`), the logo `{src, alt}`, and the scanned `site_title` /
  `site_description`.
- REMOVED the old anchor-only nav block (superseded).
- Coast result (verbatim): Home->#home, Buyers/Sellers/Homeowners->#services,
  About Coast->#about, Reviews->#testimonials, Contact->#contact,
  Search Homes->/listings (resolved in worker), Get Started->action.

### worker.php
- Surfaces the full nav model + header_ctas + sections + logo + site_title/
  site_description into analysis.live_scan and analysis.site_scan.
- Resolves `action` nav items to discovered crawl pages by label-vs-slug/title/
  h1 token match (Search Homes -> /listings). Logs the resolved nav model.

### DeterministicGen — faithful header + verbatim menu + identity
- `generateHeader()` now builds header.php DETERMINISTICALLY from the scanned
  nav model (`generateHeaderFromNav()`): logo image + verbatim nav (kind-aware
  hrefs: page->home_url path, anchor->/#slug, external->URL, action->#) + the
  scanned CTA styled as a button. NO bloginfo('name') visible text leaks into
  the header. Faithful header CSS added.
- `navItems()` rewritten to consume the new model strictly; REMOVED the
  page-list fallback and `mapNavHref()` (superseded). Empty nav model -> hard
  WARNING, never an invented page-list nav.
- `generateDemoContent()` sets blogname/blogdescription from the scanned site
  title/description (browser tab reflects the copied site) and builds the WP
  menu from the full verbatim nav model in DOM order (no hardcoded Home).
- `isChromeOrJunkSection()` — navbar / mobile-nav / seo-snapshot etc. are no
  longer rendered on the front page (the deterministic header is the single
  faithful nav; these only duplicated it and injected invented auth strings).

### ThemeBuilder v3.1.0
- Stage 1 header is the deterministic faithful header whenever a nav model
  exists; the AI header path (which invented Sign In/View Listings and leaked
  blognames) is bypassed. importMedia() imports the logo and exposes the real
  url->local-path map (analysis.media_map); $det is constructed after it.
- Chrome/junk sections are skipped before the section batch.
- New Stage 3g guardrail.

### NEW — GuardrailChecker.php (string provenance gate)
- Extracts every user-visible string from the generated templates (nav labels,
  button/heading/paragraph/link text, visible attribute values, transcription
  defaults) and verifies each non-trivial string appears (normalized,
  substring/word-fuzzy) in the scanned source DOM corpus (per-page first, then
  the union; verbatim nav/CTA/title allow-list). Unsourced strings are logged
  loudly as `[Guardrail] HALLUCINATION: "..."`. AI section/page files with >3
  unsourced strings are DROPPED so invented copy never ships; the deterministic
  header/footer are reported but kept (already verbatim). Coast build result:
  header/footer/identity 0 hallucinations; residual = an AI-invented contact
  form success/loading state inside a display:none block (reported, non-visible
  by default) — flagged for a future pass.

### Prompts — strict TRANSCRIPTION mode
- SectionGen (home-section, page-section, JSX system) + ThemeBuilder page
  conversion prompts rewritten: "Reproduce EXACTLY what appears in the source.
  Every visible string verbatim. Do NOT add/rename/paraphrase/invent any text,
  button, link, section, form, search bar, or widget. If the source has a
  search bar include it; if not, do not add one. Copy ALL of it AND only it."
  Plus an explicit rule against inventing AJAX handlers / "Sending..." /
  success-confirmation blocks for forms.

### Verified (job 101 -> euherbs.co, public, Chrome UA)
- header.php nav = all 9 scanned items verbatim; Search Homes->/listings;
  Get Started CTA; logo image; no blogname text. NO Sign In / View Listings /
  invented search input anywhere. All theme PHP lint clean.
- Public https://www.euherbs.co/ : <title> = "Coast Realty Solutions ..."
  (scanned), primary menu = the 9 verbatim items, /listings loads 200.
- visual-compare home vs job-101 scan: overall 28.53% (header/hero bands
  1-3 = 4.2/0.7/2.8% — faithful; tail bands fail because the deployed page is
  ~1200px taller — section padding/height fidelity needs another pass).

## 2.2.0 — 2026-06-10 — Scanned nav menu source, nav spacing guard, stats contrast guard

Fixes three defects verified live on euherbs.co (job 96 "Liveloud"):
(1) WP nav menu built from the imported PAGE list — detail pages
(Album Detail / Blog Article / Event Detail) became menu items and the
original nav (HOME ABOUT MUSIC EVENTS WATCH GET INVOLVED CONTACT) was lost;
(2) uppercase + letter-spaced nav links visually overlapped (gap smaller
than the trailing letter-space); (3) stats section rendered with no usable
contrast (reveal-animation items stay opacity:0 without JS; original shows
stats on a dark band).

### Added
- **scanner/live-scan.js v3.1.0 (Fred only)** — captures the original site's
  nav menu: unique `{text, href}` from `header nav a, nav a` in DOM order,
  same-origin hrefs reduced to path-only, cap 12, emitted as the `nav` key.
- **DeterministicGen `navItems()`** — single nav-source resolver used by the
  demo-content menu AND the deterministic footer nav. Priority:
  1. `live_scan.nav` (scanned original header), hrefs mapped to imported page
     slugs by path or slugified label (`mapNavHref()`); external URLs pass
     through; 2. `analysis['nav_items']` filtered. Every candidate passes
     `isSkippableSlug()` + new `isDetailNavTarget()`
     (`(album|blog|event|song|product)detail` / `*article` — detail pages are
     never nav items), unique by label, capped at 8.
- **DeterministicGen `generateThemeCss()` v2.2.0 layout guards** appended to
  theme.css additions:
  - Nav spacing guard: `column-gap: 1.5em !important` on `header nav`,
    `header nav ul`, `.{prefix}-nav`, `.{prefix}-nav ul` (+ li list-style
    reset, inline-block padded anchors) — letter-spacing renders after the
    last glyph, so the gap must exceed it.
  - Stats contrast guard: `section[class*="stats"] / [class*="counter"] /
    .{prefix}-stats` forced to the scanned dark color (resolved text color
    when dark, else #111111) with light text/values, 0.65-alpha labels,
    light item borders, and `[data-delay] { opacity:1; transform:none }` so
    reveal-animated stat items are never invisible without JS.
- **DeterministicGen `hexLuma()`** — relative-luminance helper backing the
  dark/light resolution above.
- **SectionGen `headerNavJson()`** — both header conversion paths (rendered
  HTML and JSX) now feed the AI the scanned original nav (live_scan.nav)
  instead of the page-list `nav_items`, with an EXACTLY-these-items
  instruction and a wp_nav_menu('primary')+hardcoded-fallback requirement
  (the AI previously hardcoded a page-list nav and skipped wp_nav_menu).
- **SectionGen `buildGroundTruth()`** — appends LAYOUT RULES to every AI
  prompt: nav items need >=1.25em column-gap / letter-spacing must never
  cause adjacent-item overlap; stats/counter sections need >=4.5:1 contrast
  and follow the original's dark-band treatment. Truncation cap 2048 → 3072.
- **ThemeBuilder** — `BUILDER_VERSION = '2.2.0'`; logs
  `Live scan nav (N items): …` (or the fallback notice) at build start.

### Removed / superseded
- **DeterministicGen `generateDemoContent()`** — the unconditional
  `analysis['nav_items']` menu loop (page-list source, slice 10, inline
  skippable-slug check) REPLACED by the `navItems()` loop (resolver
  pre-filters; loop only skips the hardcoded-Home duplicate).
- **DeterministicGen `generateFooter()`** — raw `analysis['nav_items']`
  footer links replaced by `navItems()` (same filtered source as the menu).
- **DeterministicGen `generateFunctions()`** — dead unused
  `$nav = $this->analysis['nav_items']` assignment deleted.

### Notes
- The scanned nav only exists on Fred builds (scanner is Fred-only); without
  it the menu falls back to filtered `analysis['nav_items']` — detail/auth
  pages are excluded in BOTH paths.

## 2.1.0 — 2026-06-10 — Playwright live-scan ground truth

Fixes wrong-font conversions (job 96 "Liveloud": original uses Barlow
Condensed heavy uppercase display headings; the 2.0.x conversion rendered an
italic serif because fonts were guessed from the scraped CSS bundle alone).

### Added
- **scanner/live-scan.js v3.0.0 (Fred VPS only, `/home/ubuntu/b442-worker/scanner/`)** —
  headless-Chromium (Playwright) scan of the job's live URL at build time:
  - `getComputedStyle()` for body/h1/h2/h3/p/a/nav/nav_a/btn
    (font-family, size, weight, style, line-height, letter-spacing,
    text-transform, color, background-color)
  - `document.fonts` entries (every loaded family/weight/style)
  - Google Fonts URLs from `<link>` tags AND CSS `@import` rules
    (Base44 sites load fonts via `@import` — link-tag-only detection finds nothing)
  - `@font-face` rules, `:root` CSS custom properties, color palette
  - Desktop 1440x900 full-page PNG screenshot
  - Single page (home), <60s budget with a 55s hard deadline that still emits
    partial JSON. JSON to stdout; errors to stderr.
- **worker.php** — after bundle fetch, when `live_url` is set and is not an
  app.base44.com editor URL, runs `timeout 90 node scanner/live-scan.js <url>
  <cache>/scraped/<uuid>/live-scan`, parses the JSON and attaches it as
  `$analysis['live_scan']`. Scan failure is logged and never fails the build.
- **ThemeBuilder** — `BUILDER_VERSION` constant echoed at build start, plus a
  "Live scan ground truth: ON/none" line. Ships `data/original-home.png`
  (live-site screenshot) and `data/live-scan.json` into the output ZIP as QA
  references.
- **DeterministicGen** — `liveScanDesign()` + `cssColorToHex()`:
  - `generateThemeCss()` and `generateFunctions()` set
    `--{prefix}-heading-font` / `--{prefix}-body-font` and the
    bg/text/primary color vars from COMPUTED live values (exact font-family
    strings, double quotes normalized to single).
  - theme.css gains a "Ground truth typography" block emitting the exact
    computed `font-family` / `font-weight` / `font-style` / `text-transform` /
    `letter-spacing` for h1 and h2.
  - functions.php enqueues the discovered Google Fonts stylesheet URL(s) via
    `wp_enqueue_style` (version `null` so CSS2 query strings survive),
    sanitized to `https://fonts.googleapis.com/` URLs only, plus preconnect
    link tags.
- **SectionGen** — `buildGroundTruth()` produces a compact (≤2KB)
  `GROUND TRUTH COMPUTED STYLES` block (computed selector styles, loaded
  fonts, Google Fonts URL, palette) prepended to every section / header /
  page conversion prompt so the AI uses measured values instead of guessing.

### Removed / superseded
- App server `converter/builder/LiveScanner.php` (145-byte stub from the
  2026-06-08 attempt, never functional — exec() is disabled on the app
  server). Deleted.
- App server `public_html/live-scan.js` (v2 reference scanner, never ran
  anywhere). Superseded by `scanner/live-scan.js` v3.0.0 on Fred; deleted.
- Generated functions.php "Google Fonts raw link tag" wp_head block —
  replaced by the sanitized `wp_enqueue_style` implementation above.

### Notes
- The scan only runs on Fred (the app server has exec() disabled). The same
  builder files are deployed to both targets; without `live_scan` in the
  analysis every consumer falls back to the previous CSS-bundle heuristics.

## 2.0.x — 2026-06-08 and earlier

V2 builder baseline: bundle-driven SectionGen v4 (rendered-HTML conversion),
CodeReviewer review/repair gates, deterministic file generation, WXR/CSV
demo-content pipeline, Fred VPS remote build worker. (Untracked before this
changelog existed.)

## QA — 2026-06-10 — QA visual-compare tool (scanner-side, no builder changes)

New automated screenshot-compare QA stage. No ThemeBuilder / DeterministicGen /
SectionGen / worker changes — scanner directory additions only.

### Added
- **scanner/visual-compare.js** (v1.0.0) — `node visual-compare.js
  <deployed_url> <reference_png> <outdir>`. Screenshots the deployed site
  with the exact live-scan.js settings (1440x900 viewport, full-page,
  Chrome UA, networkidle + 2s settle), suppresses animation noise
  (reduced-motion emulation + injected style killing all CSS
  animations/transitions), scrolls the page to force lazy images, then
  pixel-diffs against the conversion ZIP's `data/original-home.png` via
  pixelmatch/pngjs. Widths normalized, heights white-padded. Outputs
  deployed.png, diff.png (red highlights), report.json with overall
  diff_pct, per-band diff_pct (10 horizontal bands), and verdict:
  pass <10% / review 10-25% / fail >25%. Exit code 1 on fail.
- **scanner/qa-compare.sh** — wrapper: pass a conversion uuid (finds a
  local job ZIP, extracts data/original-home.png) or a reference PNG path,
  plus the deployed URL; runs visual-compare.js and prints the report.
- **scanner/README-QA.md** — when to run (after every theme deploy), how
  to read the bands, thresholds, caveats.
- npm (scanner/): pixelmatch 7.2.0, pngjs 7.0.0.

### Notes
- First run: original Liveloud (job 96 reference) vs https://www.euherbs.co/
  → diff_pct 41.09%, verdict FAIL (height 3736 vs 4016; hottest bands 6, 8,
  10). Recorded as the baseline for the 2.2.x fix cycle.
- App-server copy of this changelog not updated (no app-server writes this
  change); will sync on next builder deploy.

## 2.3.0 — 2026-06-10 — Product seeding from CSV, junk filter, real prices, UTF-8 cleaning

Fixes product defects verified live on euherbs.co (job 96 "Liveloud"): the
generated demo-content.php seeded exactly 20 hardcoded products all priced 0,
ignoring the bundled 120-row inc/product-import.csv (which has real prices,
e.g. VIP Experience = 35); scraped page text ("Sarah Mitchell", "Spotify",
testimonial sentences) was seeded as products; mojibake characters could reach
product names.

### Changed
- **ThemeBuilder.php** — BUILDER_VERSION 2.3.0. New private methods
  `cleanUtf8()`, `isJunkProduct()`, `filterProducts()`: every scraped product
  candidate is UTF-8 cleaned (U+FFFD stripped, invalid sequences repaired via
  iconv //IGNORE, control chars removed) and junk-filtered BEFORE reaching the
  CSV, WXR, and demo-content generators. Junk rules: empty/sentinel name,
  name > 80 chars, trailing ellipsis/"...", sentence punctuation . ! ?
  (common abbreviations like "Vol." exempt), em/en-dash clause fragments,
  streaming-platform names (Spotify/Apple Music/Amazon Music/Deezer/YouTube/
  iTunes/Tidal/Pandora/SoundCloud); rows with NO price, image, or category
  additionally drop on: single-word labels, lowercase-tail UI strings
  ("Log in to your account", "Toggle featured"), two-capitalized-word
  person-name shapes ("Sarah Mitchell"), honorific-prefixed names
  ("Pastor David Owens"). Anything with a non-zero price, an image, or a
  category is always kept. Claude-extracted products pass through the same
  filter before merge. The tier cap (conversion_tier_from_product_count →
  product_limit) is now applied to the final merged list. Job 96 data:
  120 candidates → 36 kept (all 6 merch items + VIP Experience @ 35 survive;
  all person names, nav labels, auth strings, sentences, platforms dropped).
- **DeterministicGen.php** — generateDemoContent() no longer emits hardcoded
  `WC_Product_Simple` blocks with `set_regular_price('0')` (block REMOVED).
  The generated demo-content.php now reads inc/product-import.csv at runtime
  via fgetcsv — single source of truth, same junk-filtered file used for
  manual WooCommerce CSV import. Seeds ALL CSV rows (hard cap 250) with the
  real Regular price, SKU (idempotent: skips rows whose SKU already exists
  via wc_get_product_id_by_sku), short/long descriptions, and product_cat
  terms via wp_set_object_terms.

### Verified
- php -l clean on both files and on generated demo-content.php output.
- filterProducts() on real job-96 products.json: 120 → 36; "Sarah Mitchell",
  "Spotify", "Apple Music", sentence rows all dropped; "VIP Experience" (35)
  and "Liveloud Classic Tee" kept. Regenerated CSV row "...VIP Experience,1,35"
  present; CSV + demo-content pass mb_check_encoding UTF-8 with no U+FFFD.

## 2.3.1 — 2026-06-10 — mbstring portability for product cleaning

### Why
Job 96 build crashed on the Fred worker at 15:40:20 UTC with
`Call to undefined function mb_check_encoding()` (ThemeBuilder.php:780,
cleanUtf8() called from filterProducts()). Fred's PHP 8.3.6 CLI had no
mbstring extension; v2.3.0 introduced mb_* calls in the product pipeline.

### Changed
- **Fred worker:** installed `php8.3-mbstring` (apt), `php -m` now lists
  mbstring.
- **ThemeBuilder.php** — cleanUtf8() no longer requires mbstring: mb_*
  path is wrapped in `function_exists('mb_check_encoding')`; the fallback
  uses `iconv('UTF-8','UTF-8//IGNORE')` to drop invalid byte sequences,
  strips any U+FFFD bytes, and keeps the same control-char preg strip.
  Same contract: returns clean valid UTF-8.
- **ThemeBuilder.php** — isJunkProduct() mb_strlen/mb_strtolower calls
  guarded the same way (strlen/strtolower fallbacks).

### Verified
- php -l clean on Fred and on the app-server mirror copy.
- Fallback branch unit-tested without mbstring: invalid bytes dropped,
  U+FFFD removed, control chars stripped, output passes
  mb_check_encoding UTF-8.

## 3.0.0 — 2026-06-10 — Multi-page conversion: site scanner, batched AI pipeline, Fred-side media import, deterministic page loaders

The v3 integration release. Four standalone-tested components (A site-scan,
B ClaudeClient batching/caching, C MediaImporter, D fred-claim v3) wired into
ThemeBuilder/worker. Sub-pages are no longer converted as one monolithic AI
call from claim HTML — every selected page is scanned in a real browser,
segmented into visual sections, and converted section-by-section through a
concurrent, prompt-cached batch pipeline with a downloaded media map.

### Added
- **scanner/site-scan.js v1.0.0 (component A, Fred)** — multi-page headless-
  Chromium crawler: `node site-scan.js <url> <outdir> [maxPages=25]`. BFS from
  the start URL (nav rank > home-body rank > discovery), same-origin only,
  auth/admin/route-param/file-ext slugs skipped. Per page: `scan/{slug}.html`
  (fully rendered DOM), `scan/{slug}.png` (full-page 1440x900), title/h1/meta,
  9-selector × 9-prop computed styles, every `<img>` + CSS background-image
  URL. Home additionally captures document.fonts, Google Fonts links, :root
  vars, palette, nav (cap 12) — everything live-scan v3.1.0 captured. Output:
  `scan/manifest.json`. Per-page try/catch; 7-min global deadline writes a
  partial manifest. 25-page base44 reference scan: 95s / 14MB.
- **ClaudeClient v3 (component B)** — `messageBatch(array $jobs, int $pool = 6,
  array $options = [])`: concurrent curl_multi dispatch, per-request 3×
  retry/backoff on 429/529/5xx (Retry-After honored, pool degrades on
  overload), never throws on single-job failure; result keys mirror job keys
  with text/error/tokens_in/tokens_out/cache_creation/cache_read/meta.
  Prompt caching: `cache_prefix` option emits a two-block system array
  (cached shared prefix + per-job suffix). getTokenUsage() now returns
  {input, output, cache_creation, cache_read}.
- **MediaImporter.php (component C, Fred)** — downloads the URL union of
  every manifest page's images[]+bg_images[] plus the claim's analysis
  images into the theme's `assets/images/` (cap 150 files / 5MB each /
  4-min deadline; content-type + magic-byte guard — base44 hosts return
  200+text/html for dead paths; sanitized names, sha1 collision fallback).
  `promptMap()` emits the url→`assets/images/<file>` block (largest-first,
  cap 60) used in every conversion prompt. Fixes the v2 defect where themes
  shipped with ZERO images (the app-server image cache never exists on Fred).
- **api-fred-claim.php v3 (component D, app server)** — sub_pages cap 5→25,
  ranked nav order > pages order > dict order (normalized-slug matching);
  `claimed_at` stamped on claim; fred_claimed jobs older than 30 min
  requeued; route-param slugs (`:`/`*`) skipped; ranked slug list logged.
- **worker.php** — site-scan.js replaces the single-page live-scan call
  (timeout 480s). manifest → `$analysis['site_scan']` +
  `$analysis['site_scan_dir']`; home entry mapped onto `$analysis['live_scan']`
  (same shape live-scan v3.1.0 emitted) so all v2 ground-truth consumers work
  unchanged. Scan failure falls back to single-page live-scan.js, then to no
  ground truth — never fails the build. Build-OK log line now reports
  cache_creation/cache_read.
- **ThemeBuilder v3.0.0**
  - Stage 0 media import (before any AI call): MediaImporter into
    `themeDir()/assets/images`; promptMap handed to SectionGen.
  - Page set = claim sub_pages ∩ scan manifest pages (claim = paid/selected
    authority, manifest = content authority), matched on normalized slugs.
    Identical detail pages deduped (equal bytes_html + h1 → keep first, log
    drop); effectively-empty pages skipped — <2KB of script/style/chrome-
    stripped body MARKUP (pure text length is the wrong signal on Base44
    sites: a visually rich 10-product shop page carries <1KB of text);
    cap 24 sub-pages + home = 25.
  - Per page: 1 segmentation job (batched) → JSON section list → 1 conversion
    job per section (batched, pool 6, shared cache_prefix) →
    `template-parts/pages/{slug}/{section}.php`, each CodeReviewer-gated
    (reviewAndRepair, unfixable sections dropped).
  - `page-{slug}.php` is a DETERMINISTIC loader from
    DeterministicGen::generatePageLoader() — never AI-generated.
  - Home sections converted through the same messageBatch pipeline
    (paths unchanged: `template-parts/{slug}.php`); placeholder fallback per
    failed/empty job preserved.
  - WXR/demo-content: sub-page post_content sourced from the scanned rendered
    DOM via new `extractScanBody()` (body minus script/style/noscript and
    header/nav/footer elements; JSX guard; 150KB cap). NOT extractPageBody —
    its content-div regex truncates rendered SPA DOMs to a fragment — editable
    backup; the template stays the renderer. Product CSV flow untouched;
    product extraction HTML now also includes scanned page bodies.
  - Token/call accounting: batch jobs + single calls + misc + repairs all
    counted; cache_creation/cache_read returned to worker → fred-complete.
  - data/ exports gain `site-scan-manifest.json`.
- **SectionGen v5** — batch-prep methods instead of N sequential message()
  calls: `prepareSectionJob()` (home, same source-priority logic),
  `prepareSegmentationJob()` (compact adaptation of the app-side Analyzer
  detectSectionsWithClaude prompt), `preparePageSectionJob()` (per-page
  computed styles included). `cachePrefix()`: ground truth + media map +
  24KB site-CSS context, built once per build (>1024-token cache floor
  guarded). Page-conversion prompts now ALWAYS carry ground truth + media
  map (v2 gap: they didn't). Header conversion warms the same cache prefix.
- **DeterministicGen** — `generatePageLoader(slug, title, sections)`:
  Template Name header + get_header + ordered get_template_part calls into
  `template-parts/pages/{slug}/` + get_footer. Nav logic unchanged
  (scanned nav, cap 8; hrefs map to imported page slugs via mapNavHref).

### Removed / superseded
- **worker.php** — the v2.1.0 primary single-page live-scan invocation
  (live-scan.js is now the documented fallback only).
- **SectionGen v4** — sequential `generate()`, `convertFromHtml()`,
  `convertFromJsx()` REMOVED (superseded by prepareSectionJob + messageBatch).
  `cleanResponse()`/`placeholder()` renamed public `cleanGenerated()` /
  `placeholderFor()` (ThemeBuilder consumes batch results directly).
- **ThemeBuilder** — `copyImages()` REMOVED (copied from an app-server image
  cache that never exists on Fred → always zero images). MediaImporter
  supersedes it. `copyImagesToData()` now mirrors the imported theme images
  instead of the nonexistent cache dir. v2 claim-HTML sub-page conversion
  (`convertPageWithClaude`) retained ONLY as the documented fallback when no
  scan page set exists.

### Verified (job 96 full rebuild, Fred, cron disabled)
- v3.0.0 banner; 25-page scan (95s / 13.3MB / 0 errors / 7 nav items);
  37 images imported (2.86MB, 5 skipped of 117 collected URLs).
- Batches: home sections 6 jobs (cache_read 81,546), segmentation 9 jobs
  (cache_read 122,319), page sections 38 jobs (cache_creation 0 /
  cache_read 516,458) — 0 batch errors, 0 AI repairs.
- Page set: about(7) advocacy(6) blog(4) community(4) contact(3) events(3)
  music(5) shop(3) watch(3) = 38 section files under template-parts/pages/
  + 9 deterministic page-{slug}.php loaders. "Liveloud Classic Tee" present
  in template-parts/pages/shop/product-grid.php.
- 69 files packaged; ALL 63 .php files lint clean; WXR = 9 pages (real DOM
  content + _wp_page_template meta) + 38 products; data/ ships
  site-scan-manifest.json + original-home.png.
- Economics: 55 AI calls (1 single + 53 batch jobs + 1 misc), tokens
  in=249,669 out=109,408 cache_creation=75,929 cache_read=720,323;
  wall time 7m23s claim→upload. ZIP 15.5MB, SUCCESS upload to fred-complete.

### Notes
- The spec'd "<2KB body TEXT" empty-page gate was wrong in practice: Base44
  pages are text-sparse (the 10-product shop page carries 718B of text, the
  whole home page 1,004B). First test run skipped 7 of 9 legitimate pages.
  Gate now measures script/style/chrome-stripped body MARKUP (<2KB).
- extractPageBody()'s content-div regex (`(?:content|main|page|app).*?</div>`)
  truncates rendered SPA DOMs at the first closing div — it measured the
  music page at 265B when its body markup is 5.4KB. Scan-sourced content
  paths use extractScanBody(); extractPageBody survives only in the
  no-scan fallback path.

## 3.0.1 — 2026-06-10 — Seeded multi-page scan: known routes from analysis_data

Fixes the multi-page scanner finding 0 sub-pages on SPA sites whose nav
uses JS click-handlers instead of `<a>` anchors. Job 101
(coast-home-link.base44.app) scanned as "1 pages, nav: 0 items" while its
analysis_data already knew 6 sub_pages and a 7-route pages[] list — the
crawler only follows anchors, and the site exposes none.

### Added
- **scanner/site-scan.js v1.1.0 (Fred only)** — optional `--seeds=<path>`
  argument: a JSON array of absolute URLs or paths (known routes from
  analysis_data). Seeds join the crawl queue at rank 0.5 — after the home
  page's nav anchors (rank 0), before its body anchors (rank 1) — and pass
  through the exact same normalize/dedupe/filter pipeline as discovered
  anchors (same-origin only, auth/admin slugs, file extensions, route
  params all still skipped; URLs the home page already discovered keep
  their original rank). Unreadable/invalid seeds files are non-fatal.
  Stderr logs `Seeds: N supplied, M enqueued`.
- **worker.php** — before invoking site-scan.js, collects seed routes from
  the claim's analysis: `pages[].path`, `nav_items[].href`, and
  `sub_pages[*].route` (falling back to `sub_pages[*].url`); trims,
  dedupes, writes `{scan_dir}/seeds.json`, and passes `--seeds` when
  non-empty. Scan log line now reports the seed count:
  `Site scan (v3.0.1, max 25 pages, N seed routes): {url}`.
- **ThemeBuilder** — `BUILDER_VERSION = '3.0.1'`.

### Removed / superseded
- **worker.php** unseeded `$scan_cmd` + `Site scan (v3, max 25 pages)` log
  line REPLACED by the seeded variant above.
- **site-scan.js** header note "Standalone — not yet wired into worker.php
  (v3.0.0 component A)" removed (it has been wired in since 3.0.0); crawl
  ranking doc updated to nav order > seeds > home order > discovery order.

### Verified (standalone, coast-home-link.base44.app)
10 seeds supplied → 3 enqueued (Login/Register/ForgotPassword/ResetPassword
filtered by SKIP_SLUG_RE as designed; `/` deduped as home) → 4 pages
captured: home (92,321 B html, distinct), listings (11,544 B, h1 "Property
Search", distinct screenshot), NotFound (7,749 B, h1 "Page Not Found"),
notfound (case-variant of /NotFound — identical screenshot md5, SPA routes
are case-insensitive). No SPA serve-home-for-unknown-path fallback observed
on this site: seeded routes rendered real per-route content.


## 4.2.1 - 2026-07-14

Chunked page rebuild (StaticRebuilder::rebuildChunked, used for pages whose
body exceeds the 45k single-call cap) now submits all chunks in one
ClaudeClient::messageBatch call instead of looping direct message() calls
one chunk at a time. The old loop had no retry and a hard 300s cURL timeout
per chunk, so a single slow chunk killed the whole build. messageBatch
brings pooling, per-request retry on 429/529/5xx, and a 240s per-job budget
that finalizes with the best available output instead of dying outright.
Failed or empty chunks are logged and skipped, the remaining chunks are
still concatenated in order.

## 4.2.2 - 2026-07-14

Page cap raised from 12 to 25 pages. The 12-page cap was a memory backstop from when the VPS had 3.7GB RAM; the server now has 15GB and was silently dropping nav-linked pages (jobs, gallery, my-favourites, walking-tours) plus all per-shop detail pages in builds that exceeded the old limit.


## 4.2.3 - 2026-07-14

Fixed chunked rebuild producing exactly one oversized chunk on Base44/React
pages: these pages have a single top-level wrapper element around all real
content, for example a root div containing one more div that holds the nav,
main, and toaster. The old top-level split only looked at direct children of
the page body, found one child, and gave up, so the whole page (roughly
180KB) went through as a single chunk. Claude's response for a chunk that
size needed around 32k tokens, which could not finish inside the 240s
per-job budget, and every job logged chunked rebuild: 1 parts followed by
chunk-1 exceeded 240s budget.

- splitIntoChunks() now descends through single-child wrapper levels first,
  down to a maximum depth of 6, stopping as soon as a level actually has two
  or more children to split at. Each level's opening and closing tags are
  captured into a wrapper chain as the descent proceeds.
- Splitting then happens at that level: consecutive children are grouped
  into chunks up to the cap. Any single child that alone exceeds the cap is
  recursed into the same way and its own sub-chunks spliced into the
  sequence in its place, preserving order.
- The wrapper chain is never asked of Claude. Each chunk's prompt carries a
  short note naming the ancestor wrapper the parts live inside and
  instructing Claude not to emit those tags itself, only the inner section
  content. rebuildChunked() then re-attaches the wrapper chain once, built
  deterministically by code, around the whole reassembled page after every
  part comes back.
- Chunk cap dropped from 45000 to 30000 characters so each chunk's response
  comfortably finishes inside the per-job time budget.
- Fixed a related depth-tracking bug uncovered while testing this: HTML5
  void elements such as img, br, and input are not always self-closed with
  a trailing slash in scraped markup, so the tag-depth scanner was treating
  them as opening a level that never closed, running the depth counter into
  the thousands and never returning to zero. Depth tracking now recognizes
  the standard void tag names regardless of how they are terminated.

## 4.2.4 - 2026-07-14

Root cause from rebuild-423.log: on discoverblackheath.co.uk, every chunked
page failed the visual gate on round 1 (home fail diff=45.1%, events
fail diff=58.1%, london-marathon fail diff=62.7%, map fail diff=28.7%,
business-events fail diff=36.4%, estate-agencies fail diff=43.3%,
discover-map fail diff=28.9%), while every non-chunked page passed cleanly
(1 to 6 percent). The gate retry rebuilt each failing page through
preparePageJob, which is the capped single-call path with a 45k input cap
that silently drops the page tail. Several of those truncated retries then
scored a much lower diff, for example home retry pass diff=2.3%, because
the truncated HTML only covers the top of the page and the gate's
overlap-crop happens to compare well there. The existing keep-best logic
picked the lower diff, so it replaced the complete chunked home page with
a truncated one. Chunking itself worked, all 15 parts of home succeeded,
but its output was being discarded by the retry and compare interaction.

- ThemeBuilder.php: the gate retry no longer routes chunked slugs through
  preparePageJob. For any slug in chunked_slugs, the retry now calls
  StaticRebuilder::rebuildChunked() again for that slug, keeping full page
  coverage instead of falling back to the capped single-call path.
  Non-chunked slugs keep the original batch retry path unchanged.
- StaticRebuilder.php: rebuildChunked() takes a new optional note parameter.
  When set, the fidelity note (the same "transcribe more faithfully" note
  already used for the non-chunked retry) is appended to every chunk's
  prompt, not just a single call, so a retried chunked page gets the same
  correction signal on every part.
- ThemeBuilder.php: added a completeness guard to the keep-best logic. When
  choosing between the round 1 and retry HTML for a page, if one candidate
  is less than 60 percent the length of the other, the longer candidate is
  treated as the only valid choice and the diff-based comparison is
  skipped, unless the longer candidate is empty, in which case it never
  wins. This stops a truncated retry from beating a complete page purely
  because a partial screenshot compares better. Logs exactly:
  "  [Gate] {slug}: completeness guard kept the longer candidate"
- ThemeBuilder.php: after Stage 1 completes, meaning both the batch
  (non-chunked) and chunked paths have merged their output into $rebuilt,
  every page's rebuilt HTML is now written unconditionally to
  {workDir}/rebuilt/{slug}.html for QA inspection, independent of what the
  gate later keeps or discards. The rebuilt/ directory is created with
  mode 0755 if missing.
- gate-compare.js reviewed line by line. Both the batch and single-page
  compare paths already normalize width with scaleToWidth() and then call
  padToHeight() with height set to min(ref.height, rebuilt.height) for
  both images. Despite its name, padToHeight() only enlarges when the
  source is shorter than the target; when the source is taller than the
  target, which is always true for one of the two images here since the
  target is the minimum of the two, the underlying Buffer.copy call is
  bounded by the destination buffer's length and truncates the source to
  its first target-height rows. In practice this already functions as an
  overlap-crop to the shorter image's height for both the batch and
  single-page comparison paths. No change was made here: the comparison
  was already height-neutral. The 45.1 percent diff on the complete home
  page was therefore not a sizing or padding artifact, it reflects real
  content and layout drift within the overlapping top region itself, most
  likely introduced during chunk reassembly (for example inconsistent
  section-level markup or spacing at chunk boundaries), separate from the
  retry-discard bug this release fixes directly.
- DeterministicGen.php: generateDemoContent() no longer sets blogname or
  blogdescription from the Base44 SPA's scanned document.title or app
  store meta description. Those carry the SPA's own demo identity, for
  example "Blackheath Horizon" and "Blackheath Horizon manages 5 data
  types...", which is wrong for a real conversion. blogname is now always
  set from the conversion's theme_name (the human-passed brand name, for
  example "Discover Blackheath"). blogdescription is now always set to an
  empty string, no strapline detection is attempted.

## 4.2.5 - 2026-07-14
- DeterministicGen.php: extractBalancedFrom(string $html, string $tagName,
  int $start) counted the anchor's own opening tag as a nested open. The
  scan started at $pos = $start, so the first open match found by the loop
  was the anchor tag itself, which pushed depth to 1. The element's real
  closing tag then only brought depth back to 0, and the loop kept
  searching for a second close that never existed, so the function
  returned an empty string for any element with no nested same-name tags.
  generateHeaderFromScannedChrome and generateFooterFromScannedChrome both
  call this helper on a single header or footer element with no nested
  header or footer tags, so they always returned empty and every build
  silently fell back to generic chrome instead of shipping the
  transcribed header and footer. Fixed by consuming the anchor opening
  tag before the depth loop starts: match $openRe at exactly $start,
  bail with an empty string if it is not found there, and initialize
  $pos past the anchor tag with depth 0. Verified by reflection harness
  against the fred-4cdf78376289 site scan: generateHeaderFromScannedChrome
  went from length 0 to 6061 bytes and generateFooterFromScannedChrome
  from 0 to 3276 bytes, both now containing the transcribed markup
  (fixed top-0 and backdrop-blur-md in the header, bg-foreground in the
  footer), and the public generateHeader() caller now returns the
  transcribed 6061-byte header instead of the 509-byte generic fallback.

## 4.2.6 - 2026-07-14
Gate now forces lazy loads and waits for images before a 90s full page screenshot. Viewport fallback and height mismatch renders are flagged and their diffs treated as unreliable instead of silently condemning complete pages.

## 4.3.1 - 2026-08-11
Fixed a packaging bug where the outer delivery ZIP shipped the same theme
content three times. Confirmed on the discoverblackheath-theme.zip capture
in b442-test (156,632,205 bytes on disk, 447 entries):
- theme/discover-blackheath-theme/ (54,690,631 bytes, 240 files), the
  unpacked theme tree that theme_zip is built from.
- theme/discover-blackheath-theme.zip (50,258,299 bytes), the same theme,
  zipped. This is the actual installable deliverable.
- data/images/ (50,312,015 bytes, 200 files), the same source images again.
- data/original-home.png (6,216,306 bytes), a build/diagnostic screenshot
  used internally by the visual QA gate, not needed to install the theme.

Root cause, in ThemeBuilder.php's package() stage:
- copyImagesToData() copied theme_dir/assets/images into data/images even
  though those images already ship inside theme_zip's own assets/images
  folder.
- writeDataExports() copied the live-site screenshot into
  data/original-home.png, a diagnostic artifact with no install purpose.
- zipOuterPackage() walked the whole conv_dir/theme directory into the
  outer ZIP. That directory holds both the unpacked <slug>-theme/ staging
  tree (themeDir(), the source zipDirectory() builds theme_zip from) and
  theme_zip itself, so the entire theme was added to the outer ZIP twice:
  once unpacked, once zipped.

This 150x regression (v4.1.x builds were 1.1MB) is why uploads of larger
sites failed: the app server's .htaccess caps post_max_size at 55M, well
under the 149.4 MiB the outer ZIP had grown to.

Fix, all in ThemeBuilder.php:
- copyImagesToData() removed entirely, and its call site in package()
  removed. data/images is no longer written.
- writeDataExports() no longer copies the live-site screenshot; the
  data/original-home.png member is no longer written.
- zipOuterPackage() signature changed from
  zipOuterPackage(string $conv_dir, string $outer_zip) to
  zipOuterPackage(string $outer_zip, string $theme_zip, string $data_dir).
  It now adds exactly two things to the outer ZIP: the theme_zip file
  itself (as theme/<slug>-theme.zip) and every file under data_dir (as
  data/...). The unpacked theme staging tree is never touched by the
  outer-zip step, so it can no longer leak into the download regardless
  of where package() stages it.

Kept, deliberately: data/content-import.xml (the WXR, needed for the
Tools -> Import -> WordPress step), data/product-import.csv when present,
and the small JSON manifests (products.json, site-data.json,
site-scan-manifest.json, live-scan.json). content-import.xml is also
present inside theme_zip at inc/content-import.xml (the install
instructions reference that inner path directly), so the data/ copy is a
convenience duplicate, not a bug: it lets a customer grab the WXR without
extracting the inner theme ZIP first, and at 1.2MB it is not a meaningful
contributor to the size problem this release fixes.

Verification: not a full rebuild (that costs real AI-call money and was
explicitly out of scope). Instead, theme/discover-blackheath-theme.zip and
the five data/* files that survive the fix were extracted from the
existing discoverblackheath-theme.zip capture and re-packaged with the
exact ZipArchive code now in zipOuterPackage(). Real, measured result:
50,364,440 bytes (48.03 MiB), 6 entries (1 theme zip + 5 data files, no
duplicates), down from 156,632,205 bytes and 447 entries. This is under
the 55M post_max_size limit with headroom. php -l passed clean on the
edited ThemeBuilder.php.

BUILDER_VERSION bumped to 4.3.1.
