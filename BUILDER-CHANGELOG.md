# B442WP Builder Changelog

All behavioural changes to the V2 builder (ThemeBuilder / DeterministicGen /
SectionGen / CodeReviewer / worker) are recorded here. The running version is
`ThemeBuilder::BUILDER_VERSION` and is echoed at the start of every build so
cron.log records which version built each job.

## 3.2.4 — 2026-06-16 — Hardcoded site title in header/footer + prefix-stub section filter

Fixes two visual defects confirmed on job #129 (SEO-SIB, Russian SEO agency):
(1) "Coast Realty Solutions" leaking into the nav logo and footer because the
generated PHP used `bloginfo('name')`, which reflects the WP DB blogname option —
stale from a prior theme if demo-content.php's `after_switch_theme` hook never
fired (e.g. direct DB activation on a reused test domain). (2) A thin AI-invented
`<header id="hero">` stub rendered above the actual hero section because the
segmenter produced both a `hero` and a `hero-section` slug and the `isChromeOrJunkSection`
filter had no concept of prefix-duplicate sections.

### Fixed
- **DeterministicGen `generateHeaderFromNav()`** — `$scan_title_html` computed
  from `analysis['live_scan']['site_title']` (fallback: `theme_name`) and hardcoded
  into the generated header.php. The no-logo text fallback now emits the literal
  scanned site name instead of `<?php bloginfo('name'); ?>`. A correctly named site
  no longer depends on demo-content.php having fired.
- **DeterministicGen `generateFooter()`** — same `$scan_title_html` applied to the
  footer logo span AND the copyright line (`© YEAR Name`). Both `bloginfo('name')`
  calls removed.
- **DeterministicGen `generateFrontPage()`** — prefix-stub detection added to the
  section loop (after `isChromeOrJunkSection`): if slug `X` exists in the sections
  list AND another slug starting with `X-` also exists (e.g. `hero` + `hero-section`),
  `X` is treated as a structural stub and skipped. This prevents the AI's thin
  `<header id="hero">` wrapper from rendering alongside the real `hero-section`.
- **ThemeBuilder** — `BUILDER_VERSION = '3.2.4'`.

### Notes
- The remaining `bloginfo('name')` in functions.php is inside the contact form
  email subject — not visible to site visitors, left as-is.
- `bloginfo('charset')` in header.php shell is for document encoding, unaffected.
- The prefix-stub rule is intentionally conservative: only exact prefix + `-` match
  (`hero-` not `herog`). Standalone `hero` sections with no `hero-*` sibling are
  rendered normally.

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
