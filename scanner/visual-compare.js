#!/usr/bin/env node
/**
 * visual-compare.js — base44towordpress QA visual-compare tool (v1.0.0)
 *
 * Compares a deployed converted site against the original site's reference
 * screenshot (data/original-home.png from the conversion ZIP, captured by
 * scanner/live-scan.js at 1440x900 viewport, full-page).
 *
 * Usage:
 *   node visual-compare.js <deployed_url> <reference_png> <outdir>
 *
 * Outputs in <outdir>:
 *   deployed.png  — fresh full-page screenshot of deployed_url (same viewport)
 *   diff.png      — pixel diff, divergent pixels highlighted red
 *   report.json   — { width, height_ref, height_deployed, diff_pixels,
 *                     diff_pct, bands: [{band, from_y, to_y, diff_pct}],
 *                     verdict: pass|review|fail }
 *
 * Verdict thresholds: pass < 10% | review 10-25% | fail > 25%
 */

'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const { PNG } = require('pngjs');

const VIEWPORT = { width: 1440, height: 900 }; // must match live-scan.js
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
const BANDS = 10;
const THRESHOLD = 0.1; // pixelmatch per-pixel sensitivity

async function screenshotDeployed(url, outPath) {
    const browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    try {
        const ctx = await browser.newContext({ userAgent: UA, viewport: VIEWPORT });
        const page = await ctx.newPage();
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 })
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
        // Force lazy-loaded images in by scrolling the full page, then return to top
        await page.evaluate(async () => {
            await new Promise((resolve) => {
                let y = 0;
                const step = () => {
                    y += 900;
                    window.scrollTo(0, y);
                    if (y >= document.body.scrollHeight) { window.scrollTo(0, 0); resolve(); }
                    else setTimeout(step, 120);
                };
                step();
            });
        }).catch(() => {});
        await page.waitForTimeout(2000); // settle after network idle
        await page.screenshot({ path: outPath, fullPage: true, timeout: 30000 })
            .catch(async () => page.screenshot({ path: outPath, fullPage: false }));
    } finally {
        await browser.close();
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

async function main() {
    const [deployedUrl, referencePng, outdir] = process.argv.slice(2);
    if (!deployedUrl || !referencePng || !outdir) {
        console.error('Usage: node visual-compare.js <deployed_url> <reference_png> <outdir>');
        process.exit(2);
    }
    if (!fs.existsSync(referencePng)) {
        console.error(`Reference PNG not found: ${referencePng}`);
        process.exit(2);
    }
    fs.mkdirSync(outdir, { recursive: true });

    const deployedPath = path.join(outdir, 'deployed.png');
    console.error(`[1/3] Screenshotting ${deployedUrl} at ${VIEWPORT.width}x${VIEWPORT.height} full-page ...`);
    await screenshotDeployed(deployedUrl, deployedPath);

    console.error('[2/3] Normalizing + diffing ...');
    const { default: pixelmatch } = await import('pixelmatch'); // v7 is ESM-only

    let ref = loadPng(referencePng);
    let dep = loadPng(deployedPath);
    const heightRefOrig = ref.height;
    const heightDepOrig = dep.height;

    // Normalize widths (scale wider image down to the narrower width)
    const width = Math.min(ref.width, dep.width);
    ref = scaleToWidth(ref, width);
    dep = scaleToWidth(dep, width);

    // Pad to common height
    const height = Math.max(ref.height, dep.height);
    ref = padToHeight(ref, height);
    dep = padToHeight(dep, height);

    const diff = new PNG({ width, height });
    const diffPixels = pixelmatch(ref.data, dep.data, diff.data, width, height, {
        threshold: THRESHOLD,
        includeAA: false,
        diffColor: [255, 0, 0],
        diffColorAlt: [255, 165, 0],
    });

    // Per-band stats: count red/orange diff pixels in 10 horizontal bands
    const bands = [];
    const bandHeight = Math.ceil(height / BANDS);
    for (let b = 0; b < BANDS; b++) {
        const fromY = b * bandHeight;
        const toY = Math.min(height, (b + 1) * bandHeight);
        let bandDiff = 0;
        for (let y = fromY; y < toY; y++) {
            for (let x = 0; x < width; x++) {
                const i = (y * width + x) << 2;
                const r = diff.data[i], g = diff.data[i + 1], bl = diff.data[i + 2];
                if (r === 255 && (g === 0 || g === 165) && bl === 0) bandDiff++;
            }
        }
        const bandPixels = (toY - fromY) * width;
        bands.push({
            band: b + 1,
            from_y: fromY,
            to_y: toY,
            diff_pct: bandPixels ? +(100 * bandDiff / bandPixels).toFixed(2) : 0,
        });
    }

    const diffPct = +(100 * diffPixels / (width * height)).toFixed(2);
    const verdict = diffPct < 10 ? 'pass' : diffPct <= 25 ? 'review' : 'fail';

    console.error('[3/3] Writing outputs ...');
    fs.writeFileSync(path.join(outdir, 'diff.png'), PNG.sync.write(diff));
    const report = {
        tool: 'visual-compare v1.0.0',
        timestamp: new Date().toISOString(),
        deployed_url: deployedUrl,
        reference_png: path.resolve(referencePng),
        viewport: `${VIEWPORT.width}x${VIEWPORT.height} full-page`,
        width,
        height_ref: heightRefOrig,
        height_deployed: heightDepOrig,
        height_compared: height,
        diff_pixels: diffPixels,
        diff_pct: diffPct,
        bands,
        verdict,
        thresholds: { pass: '<10%', review: '10-25%', fail: '>25%' },
    };
    fs.writeFileSync(path.join(outdir, 'report.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
    process.exit(verdict === 'fail' ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(2); });
