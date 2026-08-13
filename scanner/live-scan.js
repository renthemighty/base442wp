#!/usr/bin/env node
/**
 * B442WP Live Scanner v3.1.0 (Fred build-time edition)
 *
 * Visits a live URL with headless Chromium (Playwright) and extracts
 * ground-truth design data the zip/CSS-bundle parser cannot see:
 *   - getComputedStyle() for key selectors (body, h1, h2, h3, p, a, nav, button)
 *     including font-family, weight, size, letter-spacing, text-transform
 *   - All loaded fonts (document.fonts entries)
 *   - Google Fonts / font stylesheet <link> URLs
 *   - :root CSS custom properties
 *   - Color palette (computed background / text / accent)
 *   - Desktop 1440x900 full-page screenshot of the home page (PNG)
 *
 * Single page only (home). Runtime target < 60s. JSON to stdout,
 * screenshot to <outDir>/desktop.png. Errors to stderr; on fatal error
 * still emits JSON with scan_error set.
 *
 * Usage: node live-scan.js <url> [outDir]
 *
 * v3.0.0 2026-06-10: replaces the never-deployed v2 reference scanner
 * (app server public_html/live-scan.js). Removes multi-page crawl and
 * mobile screenshot (build only needs home-page ground truth), adds
 * document.fonts capture, text-transform/font-style props, palette.
 * v3.1.0 2026-06-10: captures the original site's nav menu — unique
 * {text, href(path)} anchors from header/nav in DOM order (cap 12) as
 * the `nav` key. This is the authoritative nav-menu source for the
 * builder (replaces the imported-page-list menu).
 */

'use strict';

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const [,, targetUrl, outDirArg] = process.argv;

if (!targetUrl) {
    process.stderr.write('Usage: node live-scan.js <url> [outDir]\n');
    process.exit(1);
}

const OUTPUT_DIR = outDirArg || ('/tmp/b442wp-scan-' + Date.now());
if (!fs.existsSync(OUTPUT_DIR)) fs.mkdirSync(OUTPUT_DIR, { recursive: true });

const SCAN_PROPS = [
    'font-family', 'font-size', 'font-weight', 'font-style',
    'line-height', 'letter-spacing', 'text-transform',
    'color', 'background-color',
];

const SCAN_SELECTORS = {
    body:  'body',
    h1:    'h1',
    h2:    'h2',
    h3:    'h3',
    p:     'p',
    a:     'a[href]',
    nav:   'nav, header nav, [class*="nav"]',
    nav_a: 'nav a, header a',
    btn:   'button, a[class*="btn"], .btn, [class*="button"], [role="button"]',
};

(async () => {
    const result = {
        scanner_version: '3.1.0',
        url: targetUrl,
        scanned_at: new Date().toISOString(),
        vars: {},
        computed: {},
        fonts: { document_fonts: [], google_links: [], font_stylesheets: [], css_imports: [], font_faces: [] },
        palette: {},
        nav: [],
        screenshots: {},
        scan_error: null,
    };

    let browser;
    const deadline = setTimeout(() => {
        process.stderr.write('LiveScan hard deadline hit (55s) — emitting partial result\n');
        process.stdout.write(JSON.stringify(result));
        process.exit(0);
    }, 55000);

    try {
        browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            viewport: { width: 1440, height: 900 },
            ignoreHTTPSErrors: true,
        });
        const page = await context.newPage();
        page.setDefaultTimeout(25000);

        await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 25000 })
            .catch(() => page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 15000 }));

        // Let webfonts + late JS rendering settle
        await page.evaluate(() => document.fonts.ready.then(() => true)).catch(() => {});
        await page.waitForTimeout(1500);

        const data = await page.evaluate(({ selectors, props }) => {
            const out = { vars: {}, computed: {}, documentFonts: [], googleLinks: [], fontSheets: [], cssImports: [], fontFaces: [], nav: [] };

            // :root custom properties, @import URLs, @font-face sources
            const walkRules = (rules) => {
                for (const rule of rules) {
                    if (rule.selectorText === ':root' || rule.selectorText === 'html') {
                        for (let i = 0; i < rule.style.length; i++) {
                            const k = rule.style[i];
                            if (k.startsWith('--')) out.vars[k] = rule.style.getPropertyValue(k).trim();
                        }
                    } else if (rule instanceof CSSImportRule) {
                        if (rule.href) out.cssImports.push(rule.href);
                        try { if (rule.styleSheet && rule.styleSheet.cssRules) walkRules(rule.styleSheet.cssRules); } catch (e) {}
                    } else if (rule instanceof CSSFontFaceRule) {
                        out.fontFaces.push({
                            family: (rule.style.getPropertyValue('font-family') || '').replace(/["']/g, ''),
                            weight: rule.style.getPropertyValue('font-weight') || '',
                            style:  rule.style.getPropertyValue('font-style') || '',
                            src:    (rule.style.getPropertyValue('src') || '').slice(0, 300),
                        });
                    }
                }
            };
            for (const sheet of document.styleSheets) {
                try { walkRules(sheet.cssRules); } catch (e) { /* cross-origin sheet */ }
            }

            // Computed styles for key selectors — prefer a visible element with text
            const pick = (sel) => {
                const els = document.querySelectorAll(sel);
                for (const el of els) {
                    const r = el.getBoundingClientRect();
                    if (r.width > 0 && r.height > 0 && (el.textContent || '').trim().length > 0) return el;
                }
                return els[0] || null;
            };
            for (const [key, sel] of Object.entries(selectors)) {
                let el = null;
                try { el = pick(sel); } catch (e) {}
                if (!el) continue;
                const s = getComputedStyle(el);
                out.computed[key] = {};
                for (const p of props) {
                    const v = s.getPropertyValue(p).trim();
                    if (v) out.computed[key][p] = v;
                }
                if (key === 'h1' || key === 'h2') {
                    out.computed[key]._sample_text = (el.textContent || '').trim().slice(0, 80);
                }
            }

            // Loaded fonts
            const seen = new Set();
            document.fonts.forEach(f => {
                const k = f.family + '|' + f.weight + '|' + f.style;
                if (seen.has(k)) return;
                seen.add(k);
                out.documentFonts.push({ family: f.family.replace(/^["']|["']$/g, ''), weight: f.weight, style: f.style, status: f.status });
            });

            // Original nav menu (v3.1.0): header/nav anchors in DOM order,
            // unique by text, path-only hrefs for same-origin links, cap 12.
            const navSeen = new Set();
            const navAnchors = document.querySelectorAll('header nav a[href], nav a[href]');
            for (const a of navAnchors) {
                if (out.nav.length >= 12) break;
                const text = (a.textContent || '').trim().replace(/\s+/g, ' ');
                if (!text || text.length > 40) continue;
                let u = null;
                try { u = new URL(a.getAttribute('href'), location.href); } catch (e) { continue; }
                const href = (u.origin === location.origin)
                    ? (u.pathname + (u.hash && u.pathname === '/' ? u.hash : ''))
                    : u.href;
                const key = text.toLowerCase();
                if (navSeen.has(key)) continue;
                navSeen.add(key);
                out.nav.push({ text: text, href: href });
            }

            // Font stylesheet links
            document.querySelectorAll('link[rel="stylesheet"][href]').forEach(l => {
                const h = l.href;
                if (/fonts\.googleapis\.com/.test(h)) out.googleLinks.push(h);
                else if (/font/i.test(h)) out.fontSheets.push(h);
            });

            return out;
        }, { selectors: SCAN_SELECTORS, props: SCAN_PROPS });

        result.vars = data.vars;
        result.computed = data.computed;
        result.fonts.document_fonts = data.documentFonts;
        result.fonts.google_links = [...new Set(data.googleLinks.concat(
            data.cssImports.filter(u => /fonts\.googleapis\.com/.test(u))
        ))];
        result.fonts.font_stylesheets = [...new Set(data.fontSheets)];
        result.fonts.css_imports = [...new Set(data.cssImports)];
        result.fonts.font_faces = data.fontFaces.slice(0, 40);
        result.nav = data.nav || [];

        // Palette from computed values
        const c = result.computed;
        result.palette = {
            background: (c.body && c.body['background-color']) || '',
            text:       (c.body && c.body.color) || '',
            heading:    (c.h1 && c.h1.color) || (c.h2 && c.h2.color) || '',
            link:       (c.a && c.a.color) || '',
            button_bg:  (c.btn && c.btn['background-color']) || '',
            button_text:(c.btn && c.btn.color) || '',
        };

        // Desktop full-page screenshot
        const desktopPath = path.join(OUTPUT_DIR, 'desktop.png');
        await page.screenshot({ path: desktopPath, fullPage: true, timeout: 20000 })
            .catch(async () => page.screenshot({ path: desktopPath, fullPage: false }));
        if (fs.existsSync(desktopPath)) result.screenshots.desktop = desktopPath;

        await context.close();
    } catch (e) {
        result.scan_error = e.message;
        process.stderr.write('LiveScan error: ' + e.message + '\n');
    } finally {
        clearTimeout(deadline);
        if (browser) { try { await browser.close(); } catch (_) {} }
    }

    process.stdout.write(JSON.stringify(result));
    process.exit(0);
})();
