# Builder v3.0.0 — Full multi-page conversion (cap 25 pages)

Principle: ALL capture moves to Fred (Playwright is the only reliable renderer for Base44 SPAs). App server keeps queueing/UX/payment.

## Components (build order)
- A. scanner/site-scan.js — multi-page scan: home + nav + routes, cap 25, ranked nav-first. Per page: rendered DOM (autoscrolled), computed styles, full-page screenshot, image URL list w/ bounding boxes. Output scan/{slug}.html|.png + manifest.json. ~10s/page.
- B. ClaudeClient messageBatch(jobs, pool=6-8) via curl_multi + prompt caching (cache_control on shared system+ground-truth prefix).
- C. MediaImporter.php (Fred): download union of analysis images + scanned per-page images (browser UA, dedupe, cap 150, skip >5MB) → theme assets/images/ + url→local map for prompts.
- D. App server: fred-claim raise cap to 25 + rank by scanned-nav membership (keep skip-slugs); claim-requeue for stranded fred_claimed rows.
- E. Integration: ThemeBuilder v3 — per page: 1 segmentation call → 1 call per section → template-parts/pages/{slug}/{section}.php; page-{slug}.php = deterministic loader; ground truth + media map + relevant CSS in every call; WXR/demo-content from rendered DOM (not JSX); CodeReviewer per section (unchanged).
- F. QA: per-page text-coverage scorer; visual-compare --all post-deploy; full liveloud test build → deploy euherbs → judge in Kimi.

## Economics: ~175 AI calls, ~1.1M in / 410K out (~$8-10/build with caching), ~20 min/conversion with 8-way concurrency.

## Source: full reassessment in session 2026-06-10 (agent aa0055220f4028322). Audited copies in /tmp/b442-audit/.
