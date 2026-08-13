# B442 Converter Engine Rewrite — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement task-by-task. Steps use checkbox (`- [ ]`) syntax.
>
> **Domain note:** No local unit-test runner. "Verification" = `php -l` lint + Playwright stage runs against sample URLs on Fred + installing the ZIP on `test.euherbs.co`. Every step still has an explicit pass/fail check before moving on.

**Goal:** Replace AI-emits-PHP-sections + 3 repair post-processors with: AI emits faithful semantic HTML per page → hard pixel-diff gate → deterministic templatize into a classic PHP theme.

**Architecture:** 5 deterministic-spine stages on Fred (Capture → Rebuild[AI] → VisualGate → Templatize → WooCommerce → Package). AI's only job is faithful whole-page HTML reusing the scraped CSS bundle; PHP is generated only by deterministic transform of gate-validated HTML.

**Tech Stack:** PHP 8 (Fred + app server), Node 22 + Playwright + pixelmatch (scanner), Claude API via ClaudeClient, SQLite (app server only), DirectAdmin multipart deploy.

**Snapshot of live code to read:** `_prod-snapshot/fred/` (authoritative) and `_prod-snapshot/server/`.

---

## File Structure

**New (Fred + server `converter/builder/`):**
- `StaticRebuilder.php` — Stage 1. AI emits faithful semantic HTML per page (reuses scraped CSS). Replaces `SectionGen.php`.
- `VisualGate.php` — Stage 2. Shells to `scanner/gate-compare.js`; returns diff%/verdict per page.
- `scanner/gate-compare.js` — renders a local static HTML file at 1440×900, pixel-diffs vs the Stage-0 reference screenshot.

**Modified:**
- `ThemeBuilder.php` — rewrite `build()` spine to the 5 stages; drop SectionGen/CodeReviewer/FaithfulPostProcess/GuardrailChecker.
- `DeterministicGen.php` — add `generateFrontPageFromHtml()` + `generatePageFromHtml()`; keep all existing helpers.
- `worker.php` — report degraded slugs in fred-complete stats; ensure scan screenshots exist per page.

**Deleted:** `CodeReviewer.php`, `FaithfulPostProcess.php`, `GuardrailChecker.php`, `scanner/qa-compare.sh`, and the local dead V1 (`converter/Converter.php`, `Analyzer.php`, `Assembler.php`, `Parser.php`, `LiveScanner.php`, `converter/prompts/*` except `learnings.md`).

---

## Task 1: Add the local-file visual gate renderer

**Files:**
- Create: `scanner/gate-compare.js`
- Reference: `_prod-snapshot/fred/scanner/visual-compare.js` (reuse its pixelmatch + banding code)

- [ ] **Step 1: Write `gate-compare.js`** — same VIEWPORT (1440×900) and pixelmatch THRESHOLD (0.1) as visual-compare.js. CLI: `node gate-compare.js --ref=<reference.png> --html=<page.html> --out=<dir>`. It must: launch chromium with `--no-sandbox --disable-dev-shm-usage`, `page.goto('file://'+htmlPath, {waitUntil:'networkidle'})`, set viewport, full-page screenshot to `<out>/rebuilt.png`, then pixelmatch vs `--ref`, normalizing both to the same width and the shorter height (port the band logic). Write `<out>/report.json`:

```json
{ "diff_pct": 0.0, "verdict": "pass|review|fail", "width": 1440, "height": 0, "diff_pixels": 0 }
```
Verdict: `pass` if diff_pct < 10, `review` if < 25, else `fail`. Exit 0 always (verdict is in JSON).

- [ ] **Step 2: Lint** — `node --check scanner/gate-compare.js` → Expected: no output (valid).

- [ ] **Step 3: Smoke test on Fred** — copy a known scan screenshot to a ref, render a trivial HTML, run gate-compare. Expected: `report.json` with a numeric `diff_pct` and a verdict string.

```bash
# on Fred, against any existing storage/cache scan screenshot
node scanner/gate-compare.js --ref=/tmp/ref.png --html=/tmp/test.html --out=/tmp/gateout && cat /tmp/gateout/report.json
```
Expected: JSON prints with `diff_pct` and `verdict`.

---

## Task 2: Build `StaticRebuilder.php` (Stage 1, replaces SectionGen)

**Files:**
- Create: `StaticRebuilder.php`
- Reference: `_prod-snapshot/fred/SectionGen.php` (port `buildGroundTruth()`, `cachePrefix()`, token accounting), `ClaudeClient.php` (`message()` / `messageBatch()` signatures).

- [ ] **Step 1: Write the class.** Constructor `__construct(ClaudeClient $claude, string $prefix, array $analysis, string $css_bundle = '')`. Port `buildGroundTruth(array $scan)` verbatim from SectionGen (computed styles + palette + fonts, ≤3072 chars). Public methods:

```php
public function getCalls(): int;
public function getTokenUsage(): array; // ['input'=>int,'output'=>int]

// Build one Claude job (for messageBatch) that returns faithful semantic HTML
// for a whole page. NOT PHP. Reuses original class names so the scraped CSS
// bundle styles it. system prompt = FAITHFUL TRANSCRIPTION (whole page, HTML only).
public function preparePageJob(string $slug, string $renderedHtml, array $computed = []): array;

// Parse the model response into clean HTML body (strip code fences, <html>/<head>,
// scripts). Returns inner-body HTML ready for templatizing.
public function cleanHtml(string $response, string $slug): string;
```

System prompt rules (embed verbatim in the class): "You are a faithful HTML transcription engine. Output ONLY the semantic HTML for the page body. Preserve the exact class names, text, and structure from the source DOM so the existing stylesheet applies. Do NOT write PHP, WordPress functions, `<script>`, `<style>`, or invent content. Do NOT add or remove sections." Prepend the ground-truth block + `cachePrefix()` as a cached system block (port from SectionGen lines 44–150).

- [ ] **Step 2: Lint** — `php -l StaticRebuilder.php` → Expected: `No syntax errors detected`.

- [ ] **Step 3: Isolated run on Fred** — small harness that loads StaticRebuilder against one captured page's `rendered_html`, runs one Claude `message()`, prints `cleanHtml()` output length + first 400 chars. Expected: returns HTML containing the page's headings/text, no `<?php`, no `<script>`.

---

## Task 3: Build `VisualGate.php` (Stage 2)

**Files:**
- Create: `VisualGate.php`
- Reference: Task 1 `scanner/gate-compare.js`.

- [ ] **Step 1: Write the class.**

```php
class VisualGate {
    public function __construct(string $scannerDir, string $workDir);
    // Writes $bodyHtml into a full HTML doc that links the theme CSS bundle,
    // runs gate-compare.js vs $referencePng, returns the parsed report.
    // verdict ∈ pass|review|fail; null referencePng → ['verdict'=>'ungated'].
    public function check(string $slug, string $bodyHtml, string $cssHref, ?string $referencePng): array;
}
```
`check()` builds `<!doctype html><html><head><link rel=stylesheet href="$cssHref">…</head><body>$bodyHtml</body></html>` to `$workDir/$slug.html`, shells `exec('node '.$scannerDir.'/gate-compare.js --ref=… --html=… --out=…')` (Fred has exec; guard with `function_exists('exec')`), reads `report.json`. Returns `['diff_pct'=>float,'verdict'=>string]`.

- [ ] **Step 2: Lint** — `php -l VisualGate.php` → `No syntax errors detected`.

- [ ] **Step 3: Run on Fred** — feed Task 2's rebuilt HTML + the page's scan screenshot. Expected: report with numeric `diff_pct`; on a faithful rebuild of a simple page, `verdict` is `pass` or `review`.

---

## Task 4: Add validated-HTML templatize methods to `DeterministicGen.php`

**Files:**
- Modify: `DeterministicGen.php` (read staged copy first)

- [ ] **Step 1: Add two public methods** that mirror existing `generateFrontPage()`/`generatePageLoader()` output shape but inline gate-validated HTML instead of `get_template_part`/section includes:

```php
// front-page.php: get_header(); <validated home body>; get_footer();
public function generateFrontPageFromHtml(string $validatedBodyHtml): string;

// page-{slug}.php: <?php /* Template Name: {Title} */ get_header(); <validated body>; get_footer();
public function generatePageFromHtml(string $slug, string $title, string $validatedBodyHtml): string;
```
Both wrap the body with the theme's existing header/footer calls and the standard PHP template docblock. Strip any stray `<?php`/`?>` from the body (defense-in-depth; Stage-1 already forbids PHP). Reuse the prefix + existing escaping conventions in the file.

- [ ] **Step 2: Confirm `generateFunctions()` enqueues the consolidated stylesheet.** Read the existing `generateFunctions()`/`generateThemeCss()`; the new flow ships ONE `assets/css/theme.css` (cleaned bundle + fonts). If the enqueue handle/path differs, align it. No new mechanism — reuse `generateThemeCss()`.

- [ ] **Step 3: Lint** — `php -l DeterministicGen.php` → `No syntax errors detected`.

---

## Task 5: Rewrite `ThemeBuilder::build()` spine

**Files:**
- Modify: `ThemeBuilder.php` (read staged copy first — current build() is lines 86–553)

- [ ] **Step 1: Bump version** — `public const BUILDER_VERSION = '4.0.0';`

- [ ] **Step 2: Replace the section/page generation block** (current lines ~126–495: SectionGen, CodeReviewer, segmentation, `convertScanPages`, `convertPageWithClaude`, `finalReviewGate`, `runGuardrail`) with the new spine:

```
1. setStage('media'); importMedia();   // unchanged
2. Build the page set: home + resolveScanPageSet() (existing helper, dedup, skip auth/detail slugs).
3. $rebuilder = new StaticRebuilder($claude, $prefix, $analysis, $css_bundle);
   $gate = new VisualGate($scannerDir, $workDir);
   For each page: prepare job → messageBatch (pool 6) → cleanHtml.
4. For each rebuilt page: $gate->check(slug, html, 'assets/css/theme.css', page.screenshot_file).
     verdict pass → accept
     verdict review/fail → ONE retry (re-run job with the diff note appended), re-check
     still fail → keep best attempt, push slug to $degraded[]
   Accumulate $validated[slug] = ['title'=>…, 'html'=>…].
5. $det = new DeterministicGen($prefix, $analysis);
   write header.php (generateHeaderFromNav(nav)), footer.php (generateFooter()),
   functions.php, assets/css/theme.css (generateThemeCss()), theme.js, style.css,
   skeleton, required-plugins, demo-content.
6. front-page.php = $det->generateFrontPageFromHtml($validated['home']['html']).
   page-{slug}.php = $det->generatePageFromHtml(slug,title,html) for each non-home validated page.
7. setStage('woocommerce'); if has_woo → extractProductsWithClaude() (unchanged) + WC templates.
8. setStage('assembling'); generateWXR(pages, products); generateInstallInstructions().
9. setStage('packaging'); package(); return stats incl. 'degraded'=>$degraded.
```

- [ ] **Step 3: Remove now-dead members** — delete `convertScanPages()`, `parseSegmentation()`, `extractScanBody()`, `convertPageWithClaude()`, `extractPageBody()`, `finalReviewGate()`, `runGuardrail()`, `stripFailingFile()`, and all `new SectionGen/CodeReviewer/GuardrailChecker/FaithfulPostProcess`. Keep: `importMedia`, `loadBundle`, `cleanCssBundle`, `resolveScanPageSet`, `readScanHtml`, `extractProductsWithClaude`, `filterProducts`, `isJunkProduct`, `generateWXR`, `generateProductCSV`, `generateInstallInstructions`, `package`, `zip*`, `writeDataExports`, `copyImagesToData`, `createScreenshot`, `cleanUtf8`, `setStage`.

- [ ] **Step 4: Lint** — `php -l ThemeBuilder.php` → `No syntax errors detected`.

- [ ] **Step 5: grep guard** — `grep -nE 'SectionGen|CodeReviewer|FaithfulPostProcess|GuardrailChecker' ThemeBuilder.php` → Expected: no matches.

---

## Task 6: Update `worker.php`

**Files:**
- Modify: `worker.php` (read staged copy)

- [ ] **Step 1:** Confirm the scan it runs (`site-scan.js`) writes a `screenshot_file` per page at 1440×900. If site-scan uses a different viewport, set it to 1440×900 to match the gate. Quote/adjust the viewport const.

- [ ] **Step 2:** Pass the scanner dir + a work tmp dir into ThemeBuilder (constructor or build arg) so VisualGate can shell to gate-compare.js.

- [ ] **Step 3:** Include `$stats['degraded']` (slug list) in the fred-complete POST body as a new field `degraded` (JSON). Server side: `api-fred-complete.php` may ignore unknown fields — confirm it does (it reads named fields), so this is additive and safe. Log degraded slugs to cron.log.

- [ ] **Step 4: Lint** — `php -l worker.php` → `No syntax errors detected`.

---

## Task 7: Delete dead engine + repo files

**Files:**
- Delete (Fred + server): `CodeReviewer.php`, `FaithfulPostProcess.php`, `GuardrailChecker.php`, `scanner/qa-compare.sh`
- Delete (local repo only): `b442wp-app/converter/Converter.php`, `Analyzer.php`, `Assembler.php`, `Parser.php`, `LiveScanner.php`, `b442wp-app/converter/prompts/{css-gen,functions-gen,home-section,homepage-gen,inner-pages-gen,layout,system,woocommerce-gen}.php`; root `screwed-theme/`, `screwed-theme.zip`, `CONTINUATION_PROMPT`, `CHANGES`, `engine-update-prompt.md`

- [ ] **Step 1: Verify unused before deleting** — for each deletion target, `grep -rn "ClassNameOrBasename" b442wp-app/ _prod-snapshot/` excluding the file itself. Expected: only self-references (post-Task-5 ThemeBuilder no longer names the 3 post-processors).
- [ ] **Step 2: Preserve `learnings.md`** — move `b442wp-app/converter/prompts/learnings.md` → `b442wp-app/converter/builder/learnings.md` and confirm StaticRebuilder can inject it.
- [ ] **Step 3: Delete.** Local: `rm`. Remote: DA delete on uilpwnms uses `action=multiple&button=delete`; on Fred plain `rm`.
- [ ] **Step 4: Verify** the theme still builds (re-run Task 8 end-to-end) after deletions — proves nothing essential was removed.

---

## Task 8: End-to-end test on Fred (the real gate)

- [ ] **Step 1: Back up live builder** — on Fred + server, copy current builder files to `*.bak-20260618`.
- [ ] **Step 2: Deploy new + changed files to BOTH Fred `/home/ubuntu/b442-worker/` AND server `converter/builder/`** (multipart on uilpwnms needs `-H 'Expect:'`).
- [ ] **Step 3: Run a Base44 sample job** end-to-end on Fred (claim or hand-feed a known Base44 live_url). Expected: ZIP produced; cron.log shows `v4.0.0`; `degraded` empty or small; home page gate `pass`.
- [ ] **Step 4: Run a Lovable sample job** end-to-end. Expected: same.
- [ ] **Step 5: Install both ZIPs on `test.euherbs.co`** via token-gated probe; load front page in browser (Kimi screenshot). Expected: renders faithfully vs original; no PHP errors; no `bloginfo`/walker/`{$…}` artifacts.
- [ ] **Step 6: Regression** — re-run an existing job (PG or #97) through v4.0.0; compare output to 3.2.4. Expected: equal or better fidelity, fewer/no artifacts.

---

## Task 9: Ship + document

- [ ] **Step 1:** Append BUILDER-CHANGELOG.md `4.0.0` entry (what changed, files removed) on Fred + server + local.
- [ ] **Step 2:** Commit new engine into the repo under `b442wp-app/converter/builder/` (finally version-controlled). No AI attribution; author Simon Painter.
- [ ] **Step 3:** Remove `_prod-snapshot/` (recon staging) per Repository Cleanup Rule.
- [ ] **Step 4:** Update memory: `project_base442wp_engine.md` (v4.0.0 architecture), `wrkmate-build-log.md`, `wrkmate-training-master.md`, primer.md.

---

## Self-review (spec coverage)

- Spec §3 stages 0–5 → Tasks 1–6. ✓
- Spec §4 Base44+Lovable parity → Task 8 steps 3–4 (both must pass). ✓
- Spec §5 error handling (degrade, ungated, retry) → Task 5 step 2 + Task 6 step 3. ✓
- Spec §7 cleanup → Task 7 + Task 9 step 3. ✓
- Spec §8 testing → Task 8. ✓
- Spec §9 deploy/rollback → Task 8 step 1–2 (backups) + Task 9. ✓
- No placeholders: each new file has a concrete interface; modifications cite staged files to read. Method names consistent across tasks (`generateFrontPageFromHtml`, `generatePageFromHtml`, `preparePageJob`, `cleanHtml`, `check`). ✓
