# B442 Converter Engine Rewrite — Design Spec

**Date:** 2026-06-18
**Anchor:** the manual Pacific Glasses conversion method — scan the live site → rebuild as clean static HTML/CSS → mechanically convert that into a classic PHP WordPress theme.
**Scope:** conversion engine only. The customer-facing website (auth, dashboard, upload/pay/download, Stripe + PayPal, coupons) is **frozen** — touch only for dead-code removal, never payment logic.
**Target version:** `BUILDER_VERSION = '4.0.0'`.

---

## 1. Goal

Replace the "AI emits WordPress PHP section-by-section, then three post-processors try to repair it" pipeline with: **AI rebuilds each page as clean static HTML/CSS (grounded in captured computed styles) → a hard pixel-diff gate verifies it against the original → deterministic code mechanically templatizes the validated HTML into a classic PHP theme.**

The defining rule: **no AI output reaches the customer ZIP without passing the visual gate, and PHP is generated only by deterministic transform of validated HTML.** This eliminates the entire class of codegen defects (`bloginfo('name')` leaks, undefined `Walker_Nav_Menu`, `{$nav_items_code}` tokens, `hero` double-render, mid-file token truncation) by construction, because Claude never writes PHP.

## 2. Current state (ground truth from `_prod-snapshot/`)

Production engine `BUILDER_VERSION = 3.2.4`. All builds run on Fred VPS; the app server only queues jobs and stores results. The website ↔ engine contract is three HTTP endpoints (frozen):

- `GET /api/fred-claim` → returns next `fred_queue` job as JSON `{id, uuid, live_url, theme_name, has_woocommerce, analysis}` (sections ≤12, sub_pages ≤25).
- `GET /api/fred-bundle?id=&type=css|js` → streams scraped CSS/JS bundle.
- `POST /api/fred-complete` (multipart) → `conversion_id, ai_calls, tokens_input, tokens_output, zip_file | error`.

Builder files (Fred = authoritative; mirrored on server `converter/builder/`):

| File | Lines | Fate in rewrite |
|---|---|---|
| `ThemeBuilder.php` | 1738 | **Rewrite** → thin orchestrator of the 5 stages (target <600 lines) |
| `DeterministicGen.php` | 1547 | **Keep + extend** → becomes the Stage-3 templatizer (already does header/footer/front-page/functions/css) |
| `SectionGen.php` | 545 | **Replace** → becomes `StaticRebuilder` (AI emits clean HTML+CSS, not PHP) |
| `ClaudeClient.php` | 606 | **Keep** (HTTP wrapper, batch support, 64k ceiling) |
| `MediaImporter.php` | 449 | **Keep** (Stage 0 asset download) |
| `CodeReviewer.php` | 369 | **Delete** — crutch for AI-written PHP; unnecessary once PHP is deterministic |
| `FaithfulPostProcess.php` | 537 | **Delete** — crutch for AI-written sections |
| `GuardrailChecker.php` | 233 | **Delete** — crutch for AI-written sections |
| `scanner/site-scan.js` | 839 | **Keep + extend** → Stage 0 capture (per-page DOM, computed styles, screenshots) |
| `scanner/live-scan.js` | 232 | **Keep** (single-page fallback) |
| `scanner/visual-compare.js` | 197 | **Keep + promote** → Stage-2 gate engine (currently standalone) |
| `scanner/qa-compare.sh` | 61 | **Delete** — replaced by the in-pipeline gate |
| `worker.php` | 362 | **Modify** — call new orchestrator, run the gate loop |

Net deletion target: `CodeReviewer` + `FaithfulPostProcess` + `GuardrailChecker` + `qa-compare.sh` ≈ 1,200 lines of repair machinery removed.

## 3. Target architecture — 5 stages on Fred

```
STAGE 0  CAPTURE  (deterministic, site-scan.js + MediaImporter)
  in:  live_url (+ seed routes from analysis.routes/pages/nav for Base44 & Lovable)
  out: per-page { url, slug, rendered_html, computed{selector→props},
                  screenshot_file, images[] }, palette, fonts, root_vars,
                  nav[], logo, site_title, has_woocommerce signals
        → assets downloaded to assets/images/, media_map built

STAGE 1  REBUILD  (AI — StaticRebuilder, the ONLY creative step)
  per page: Claude produces ONE clean semantic static HTML file + appends to a
  single consolidated stylesheet. Prompt is fed the page's captured computed
  styles + palette + fonts as HARD GROUND TRUTH (colors/spacing/font-family/
  weight cannot drift). Output is plain HTML/CSS — NO PHP, NO WP functions.

STAGE 2  VISUAL GATE  (deterministic — visual-compare.js)
  render the rebuilt static page headless at the captured viewport →
  pixelmatch diff vs Stage-0 screenshot.
    diff < PASS_THRESHOLD (10%)         → accept page
    PASS ≤ diff < HARD_FAIL (25%)       → feed annotated diff back to Stage 1,
                                          retry (max REBUILD_RETRIES = 2)
    diff ≥ HARD_FAIL after retries      → mark page degraded; record in job
                                          report; DO NOT silently ship a broken
                                          page (job still completes with a flag)

STAGE 3  TEMPLATIZE  (deterministic — DeterministicGen, NO AI)
  consume validated static HTML:
   - split shared chrome → header.php / footer.php (nav from captured nav[])
   - home → front-page.php (sections inlined from validated home HTML)
   - each inner page → page-{slug}.php (+ _wp_page_template meta in WXR)
   - functions.php enqueues the ONE consolidated stylesheet + discovered Google
     Fonts; site title/identity hardcoded from captured site_title
   - style.css header block, theme.js, skeleton files

STAGE 4  WOOCOMMERCE  (deterministic + 1 light AI call, only if store detected)
   - product extraction (reuse extractProductsWithClaude) → products[]
   - WXR product items + product_cat terms + CSV backup
   - shop/cart/checkout/single-product template overrides from captured store
     pages (cart/checkout remain WC-managed; we ship overrides, not page content)

STAGE 5  PACKAGE  (deterministic)
   - assemble theme dir, generate content-import.xml (WXR), install instructions
   - ZipArchive → {theme-slug}-theme-download.zip → POST /api/fred-complete
```

## 4. Base44 + Lovable parity (hard requirement)

The engine is **source-agnostic at capture time** — Stages 0–3 operate on the *rendered live site*, not on the React source, so a Base44 app and a Lovable app are converted identically once scanned. Source type only affects intake/seeding, which is preserved:

- `ZipExtractor::detectSourceBuilder()` (server, unchanged) sets `analysis.source_builder ∈ {base44, lovable}`.
- `worker.php` seeds the scanner with `analysis.routes/pages/nav_items/sub_pages` (Lovable TanStack code-split exports expose routes only via the zip; Base44 `pages/*.jsx` expose them too). This seeding stays.
- Stage 1 ground-truth rebuild and Stage 3 templatize never branch on source_builder.
- **Test gate:** the rewrite is not "done" until one Base44 export and one Lovable export both convert and pass the visual gate.

## 5. Error handling & fallbacks

- **No live URL reachable:** primary source is the live scan (confirmed). If the scan returns zero usable pages, fall back to the zip `rendered_html` / `section_content` already in `analysis` for Stage 1, and skip the visual gate for pages with no screenshot (record as "ungated" in the report). Never hard-crash a job.
- **Stage 1 malformed output** (not valid HTML): one reparse attempt, then treat as a gate failure → retry path.
- **Visual gate degraded pages:** job completes, ZIP ships, but the job report (returned in fred-complete stats / logged) lists degraded slugs. This is honest partial success, not silent breakage — aligns with RULES.md "test before done."
- **Claude token ceiling:** keep ClaudeClient's 64k ceiling + escalation; a single static HTML page is far smaller than the old multi-section PHP, so truncation risk drops sharply.
- **Fred 300s limit:** does not apply (Fred has no kill). App server is never used to build.
- **Rollback:** old builder files preserved as `*.bak-20260618` on Fred + server; `worker.php` switch is a one-line revert. New orchestrator ships behind `BUILDER_VERSION=4.0.0` so cron.log identifies which engine built each job.

## 6. Data contract (unchanged)

The `$analysis` array, the three fred-* endpoints, the DB schema, and the ZIP delivery are all **unchanged**. The rewrite consumes the same `analysis.site_scan` / `analysis.live_scan` / `analysis.products` keys and emits the same ZIP shape. The website requires zero changes.

## 7. Cleanup (the "remove anything not necessary" mandate)

**Delete from engine (Fred + server `converter/builder/`):**
- `CodeReviewer.php`, `FaithfulPostProcess.php`, `GuardrailChecker.php`, `scanner/qa-compare.sh`.

**Delete from local repo (dead V1 pipeline never used in production):**
- `b442wp-app/converter/Converter.php`, `Analyzer.php`, `Assembler.php`, `Parser.php`
- `b442wp-app/converter/prompts/` (V1 prompt files: css-gen, functions-gen, home-section, homepage-gen, inner-pages-gen, layout, system, woocommerce-gen) — **keep `learnings.md`** (still injected) and migrate it into the new engine.
- `b442wp-app/converter/LiveScanner.php` (exec() disabled on server; superseded by Fred scanner).
- Root scratch: `screwed-theme/`, `screwed-theme.zip`, `engine-update-prompt.md` (consumed), `CONTINUATION_PROMPT`, `CHANGES` (fold into BUILDER-CHANGELOG.md).
- `_prod-snapshot/` (recon staging) — removed at end of build per Repository Cleanup Rule.

**Keep:** everything the website needs (pages/, includes/, database/, templates/, emails/, assets/, router.php, webhook-*, api-fred-*), the Fred scanner, ClaudeClient, MediaImporter, DeterministicGen.

Every deletion is verified unused by grep before removal (no guessing).

## 8. Testing plan

1. **Unit-ish:** `php -l` every changed/new PHP file before deploy.
2. **Stage 0:** run site-scan.js against PG live URL + a Base44 sample + a Lovable sample; confirm per-page screenshots + computed styles captured.
3. **Stage 1+2 loop:** rebuild → gate on each; confirm pass <10% on at least the home page of all three samples.
4. **Stage 3–5:** build full ZIP on Fred for each sample; install on `test.euherbs.co` (WP+WC) via token-gated probe; load front-end; visual-check against original.
5. **Regression:** re-run an existing completed job (e.g. PG / job #97) through v4.0.0; compare ZIP output quality to the 3.2.4 output.
6. **Done gate:** Base44 sample AND Lovable sample both produce installable themes that pass the visual gate and render correctly in a browser.

## 9. Deployment & rollback

1. Build + lint locally (engine code committed to repo under `b442wp-app/converter/builder/` so it's finally version-controlled).
2. Back up live builder files → `*.bak-20260618` on Fred + server.
3. Deploy new builder to **both** Fred `/home/ubuntu/b442-worker/` and server `converter/builder/` (HARD RULE — never one without the other). Multipart upload on uilpwnms needs `-H 'Expect:'`.
4. Bump `BUILDER_VERSION='4.0.0'`, append BUILDER-CHANGELOG.md entry.
5. Run the test plan against test infra before any real customer job hits v4.0.0.
6. Rollback = restore `*.bak-20260618` + revert worker.php one-liner.

## 10. Out of scope

- No website/UX/flow changes. No payment changes. No DB schema changes.
- No new intake modes (live-URL-primary stays; zip remains fallback).
- The phantom-requeue bug and large-DOM segmentation issues are noted but not part of this rewrite unless the re-architecture resolves them incidentally.
