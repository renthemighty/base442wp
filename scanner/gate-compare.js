#!/usr/bin/env node
/**
 * gate-compare.js — base44towordpress Stage-2 visual gate (v1.0.0)
 *
 * Compares locally-rendered converted static HTML files against reference screenshots.
 * Renders via Chromium at 1440x900 viewport, full-page, then pixel-diffs.
 *
 * Single-page mode:
 *   node gate-compare.js --ref=<reference.png> --html=<page.html> --out=<dir>
 *
 * Batch mode:
 *   node gate-compare.js --batch=<manifest.json> --out=<dir> [--concurrency=5]
 *
 * Single-page outputs in <dir>:
 *   rebuilt.png  — fresh full-page screenshot of page.html (same viewport)
 *   report.json  — { diff_pct, verdict: pass|review|fail, width, height, diff_pixels }
 *
 * Batch outputs in <dir>:
 *   <slug>.png           — screenshot for each entry
 *   <slug>.report.json   — per-page report
 *   batch-report.json    — aggregate { "<slug>": { diff_pct, verdict }, ... }
 *
 * Verdict thresholds: pass < 10% | review 10-25% | fail > 25%
 * Ungated pages (no ref or ref not found): verdict = "ungated"
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { PNG } = require('pngjs');

const VIEWPORT = { width: 1440, height: 900 }; // must match visual-compare.js
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const THRESHOLD = 0.1; // pixelmatch per-pixel sensitivity

async function screenshotHtml(htmlPath, outPath) {
    const absolutePath = path.resolve(htmlPath);
    if (!fs.existsSync(absolutePath)) {
        throw new Error(`HTML file not found: ${absolutePath}`);
    }

    const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    try {
        return await screenshotHtmlWithBrowser(browser, htmlPath, outPath);
    } finally {
        await browser.close();
    }
}

async function screenshotHtmlWithBrowser(browser, htmlPath, outPath) {
    const absolutePath = path.resolve(htmlPath);
    if (!fs.existsSync(absolutePath)) {
        throw new Error(`HTML file not found: ${absolutePath}`);
    }

    const ctx = await browser.newContext({ userAgent: UA, viewport: VIEWPORT });
    try {
        const page = await ctx.newPage();
        await page.emulateMedia({ reducedMotion: 'reduce' });
        const fileUrl = 'file://' + absolutePath;
        await page.goto(fileUrl, { waitUntil: 'networkidle', timeout: 45000 })
            .catch(() => page.waitForTimeout(5000)); // proceed if networkidle never settles
        // Kill CSS animations/transitions so moving elements don't register as diffs
        await page.addStyleTag({ content: `
            *, *::before, *::after {
                animation: none !important;
                animation-play-state: paused !important;
                transition: none !important;
                caret-color: transparent !important;
            }
        ` }).catch(() => {});
        // Force lazy-loaded images in by scrolling the full page in viewport-height
        // steps with a pause between each, then return to top.
        await page.evaluate(async () => {
            await new Promise((resolve) => {
                let y = 0;
                const step = () => {
                    y += 900;
                    window.scrollTo(0, y);
                    if (y >= document.body.scrollHeight) { window.scrollTo(0, 0); resolve(); }
                    else setTimeout(step, 300);
                };
                step();
            });
        }).catch(() => {});
        // Wait for any still-loading images to finish, capped at 20s so a single
        // stuck image can never hang the render indefinitely.
        await Promise.race([
            page.evaluate(() => Promise.all(
                [...document.images]
                    .filter((i) => !i.complete)
                    .map((i) => new Promise((r) => { i.onload = i.onerror = r; }))
            )),
            page.waitForTimeout(20000),
        ]).catch(() => {});
        await page.waitForTimeout(800); // let layout settle after images load
        let usedFallback = false;
        await page.screenshot({ path: outPath, fullPage: true, timeout: 90000 })
            .catch(async () => {
                usedFallback = true;
                await page.screenshot({ path: outPath, fullPage: false });
            });
        return { fallback: usedFallback };
    } finally {
        await ctx.close();
    }
}

function loadPng(p) {
    return PNG.sync.read(fs.readFileSync(p));
}

// Nearest-neighbour scale to target width, preserving aspect ratio
function scaleToWidth(img, targetWidth) {
    if (img.width === targetWidth) return img;
    const ratio = targetWidth / img.width;
    const targetHeight = Math.round(img.height * ratio);
    const out = new PNG({ width: targetWidth, height: targetHeight });
    for (let y = 0; y < targetHeight; y++) {
        const sy = Math.min(img.height - 1, Math.floor(y / ratio));
        for (let x = 0; x < targetWidth; x++) {
            const sx = Math.min(img.width - 1, Math.floor(x / ratio));
            const si = (sy * img.width + sx) << 2;
            const di = (y * targetWidth + x) << 2;
            out.data[di] = img.data[si];
            out.data[di + 1] = img.data[si + 1];
            out.data[di + 2] = img.data[si + 2];
            out.data[di + 3] = img.data[si + 3];
        }
    }
    return out;
}

// Pad image to target height with white pixels
function padToHeight(img, targetHeight) {
    if (img.height === targetHeight) return img;
    const out = new PNG({ width: img.width, height: targetHeight });
    out.data.fill(255);
    img.data.copy(out.data, 0, 0, img.width * img.height * 4);
    return out;
}

function parseArgs() {
    const args = {};
    for (const arg of process.argv.slice(2)) {
        const [key, val] = arg.split('=');
        if (key && val) {
            args[key.replace(/^--/, '')] = val;
        }
    }
    return args;
}

async function runWithConcurrency(tasks, limit, fn) {
    let i = 0;
    const results = [];
    async function next() {
        if (i >= tasks.length) return;
        const idx = i++;
        try {
            results[idx] = await fn(tasks[idx]);
        } catch (e) {
            // Store the error as a sentinel so this slot is never undefined and the
            // worker chain continues to the next task regardless of what threw.
            results[idx] = { __workerError: true, task: tasks[idx], error: e };
        }
        await next();
    }
    const workers = Array.from({ length: Math.min(limit, tasks.length) }, () => next());
    await Promise.all(workers);
    return results;
}

async function processBatch(manifestPath, outdir, concurrency = 5) {
    if (!fs.existsSync(manifestPath)) {
        console.error(`Manifest not found: ${manifestPath}`);
        process.exit(1);
    }

    const manifestContent = fs.readFileSync(manifestPath, 'utf-8');
    const manifest = JSON.parse(manifestContent);

    if (!Array.isArray(manifest)) {
        console.error('Manifest must be a JSON array');
        process.exit(1);
    }

    fs.mkdirSync(outdir, { recursive: true });

    console.error(`[1/3] Launching browser ...`);
    const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });

    try {
        console.error(`[2/3] Processing ${manifest.length} entries with concurrency=${concurrency} ...`);
        const { default: pixelmatch } = await import('pixelmatch');

        const reports = {};

        const results = await runWithConcurrency(manifest, concurrency, async (entry) => {
            const { slug, ref, html } = entry;
            // slug_safe: filesystem-safe name for screenshot/report files
            const slug_safe = slug.replace(/[^a-z0-9_-]/gi, '_');

            try {
                // Ungated case: no ref or ref file doesn't exist
                if (!ref || !fs.existsSync(ref)) {
                    const report = {
                        diff_pct: null,
                        verdict: 'ungated',
                    };
                    fs.writeFileSync(
                        path.join(outdir, `${slug_safe}.report.json`),
                        JSON.stringify(report, null, 2)
                    );
                    return { slug, report };
                }

                // Gated case: render and compare
                const screenshotPath = path.join(outdir, `${slug_safe}.png`);
                const shotResult = await screenshotHtmlWithBrowser(browser, html, screenshotPath);
                const usedFallback = !!(shotResult && shotResult.fallback);

                let refImg = loadPng(ref);
                let builtImg = loadPng(screenshotPath);

                // Raw heights before any scaling/padding — used to flag a gross
                // size mismatch (e.g. a viewport-fallback sliver vs a full page).
                const rawRefHeight = refImg.height;
                const rawBuiltHeight = builtImg.height;
                const heightMismatch = Math.abs(rawRefHeight - rawBuiltHeight)
                    / Math.max(rawRefHeight, rawBuiltHeight) > 0.2;

                // Normalize widths
                const width = Math.min(refImg.width, builtImg.width);
                refImg = scaleToWidth(refImg, width);
                builtImg = scaleToWidth(builtImg, width);

                // Normalize heights
                const height = Math.min(refImg.height, builtImg.height);
                refImg = padToHeight(refImg, height);
                builtImg = padToHeight(builtImg, height);

                const diff = new PNG({ width, height });
                const diffPixels = pixelmatch(refImg.data, builtImg.data, diff.data, width, height, {
                    threshold: THRESHOLD,
                    includeAA: false,
                    diffColor: [255, 0, 0],
                    diffColorAlt: [255, 165, 0],
                });

                const diffPct = +(100 * diffPixels / (width * height)).toFixed(2);
                const verdict = diffPct < 10 ? 'pass' : diffPct <= 25 ? 'review' : 'fail';

                const report = {
                    diff_pct: diffPct,
                    verdict,
                    width,
                    height,
                    diff_pixels: diffPixels,
                    render: usedFallback ? 'viewport' : 'full',
                    height_mismatch: heightMismatch,
                };

                fs.writeFileSync(
                    path.join(outdir, `${slug_safe}.report.json`),
                    JSON.stringify(report, null, 2)
                );

                return { slug, report };
            } catch (e) {
                const report = {
                    diff_pct: null,
                    verdict: 'fail',
                    error: e.message,
                };
                fs.writeFileSync(
                    path.join(outdir, `${slug_safe}.report.json`),
                    JSON.stringify(report, null, 2)
                );
                return { slug, report };
            }
        });

        // Build batch report keyed by original slug (what PHP uses to look up results).
        // Guard against undefined slots (sparse array) and worker-level error sentinels
        // — both mean the task was dropped before producing a {slug, report} pair.
        results.forEach((result, idx) => {
            if (result == null || result.__workerError) {
                // The task at this index never produced a result; record it as failed.
                const rawSlug = (manifest[idx] || {}).slug || `__unknown_${idx}`;
                const errMsg = result && result.error ? result.error.message : 'worker-dropped';
                console.error(`[batch] slot ${idx} (slug="${rawSlug}") dropped: ${errMsg}`);
                reports[rawSlug] = { diff_pct: null, verdict: 'fail', error: errMsg };
                return;
            }
            const { slug, report } = result;
            reports[slug] = {
                diff_pct: report.diff_pct,
                verdict: report.verdict,
                render: report.render || 'full',
                height_mismatch: report.height_mismatch || false,
            };
        });

        console.error('[3/3] Writing batch report ...');
        fs.writeFileSync(
            path.join(outdir, 'batch-report.json'),
            JSON.stringify(reports, null, 2)
        );

        console.log(JSON.stringify(reports, null, 2));
    } finally {
        await browser.close();
    }
}

async function main() {
    const args = parseArgs();
    const outdir = args.out;

    // Batch mode
    if (args.batch) {
        if (!outdir) {
            console.error('Usage: node gate-compare.js --batch=<manifest.json> --out=<dir> [--concurrency=5]');
            process.exit(1);
        }
        const concurrency = parseInt(args.concurrency || '6', 10);
        await processBatch(args.batch, outdir, concurrency);
        process.exit(0);
    }

    // Single-page mode
    const referencePng = args.ref;
    const htmlPath = args.html;

    if (!referencePng || !htmlPath || !outdir) {
        console.error('Usage: node gate-compare.js --ref=<reference.png> --html=<page.html> --out=<dir>');
        process.exit(0);
    }

    if (!fs.existsSync(referencePng)) {
        const report = {
            diff_pct: 100,
            verdict: 'fail',
            width: VIEWPORT.width,
            height: VIEWPORT.height,
            diff_pixels: 0,
            error: `Reference PNG not found: ${referencePng}`,
        };
        fs.mkdirSync(outdir, { recursive: true });
        fs.writeFileSync(path.join(outdir, 'report.json'), JSON.stringify(report, null, 2));
        process.exit(0);
    }

    fs.mkdirSync(outdir, { recursive: true });

    try {
        const rebuiltPath = path.join(outdir, 'rebuilt.png');
        console.error(`[1/3] Rendering ${htmlPath} at ${VIEWPORT.width}x${VIEWPORT.height} full-page ...`);
        const shotResult = await screenshotHtml(htmlPath, rebuiltPath);
        const usedFallback = !!(shotResult && shotResult.fallback);

        console.error('[2/3] Normalizing + diffing ...');
        const { default: pixelmatch } = await import('pixelmatch'); // v7 is ESM-only

        let ref = loadPng(referencePng);
        let rebuilt = loadPng(rebuiltPath);

        // Raw heights before any scaling/padding — used to flag a gross size
        // mismatch (e.g. a viewport-fallback sliver vs a full page).
        const rawRefHeight = ref.height;
        const rawBuiltHeight = rebuilt.height;
        const heightMismatch = Math.abs(rawRefHeight - rawBuiltHeight)
            / Math.max(rawRefHeight, rawBuiltHeight) > 0.2;

        // Normalize widths (scale wider image down to the narrower width)
        const width = Math.min(ref.width, rebuilt.width);
        ref = scaleToWidth(ref, width);
        rebuilt = scaleToWidth(rebuilt, width);

        // Normalize heights to the shorter of the two
        const height = Math.min(ref.height, rebuilt.height);
        ref = padToHeight(ref, height);
        rebuilt = padToHeight(rebuilt, height);

        const diff = new PNG({ width, height });
        const diffPixels = pixelmatch(ref.data, rebuilt.data, diff.data, width, height, {
            threshold: THRESHOLD,
            includeAA: false,
            diffColor: [255, 0, 0],
            diffColorAlt: [255, 165, 0],
        });

        const diffPct = +(100 * diffPixels / (width * height)).toFixed(2);
        const verdict = diffPct < 10 ? 'pass' : diffPct <= 25 ? 'review' : 'fail';

        console.error('[3/3] Writing outputs ...');
        const report = {
            diff_pct: diffPct,
            verdict,
            width,
            height,
            diff_pixels: diffPixels,
            render: usedFallback ? 'viewport' : 'full',
            height_mismatch: heightMismatch,
        };
        fs.writeFileSync(path.join(outdir, 'report.json'), JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report, null, 2));
        process.exit(0);
    } catch (e) {
        const report = {
            diff_pct: 100,
            verdict: 'fail',
            width: VIEWPORT.width,
            height: VIEWPORT.height,
            diff_pixels: 0,
            error: e.message,
        };
        fs.writeFileSync(path.join(outdir, 'report.json'), JSON.stringify(report, null, 2));
        process.exit(0);
    }
}

main().catch((e) => {
    console.error(e);
    process.exit(0);
});
