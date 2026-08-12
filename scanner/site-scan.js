#!/usr/bin/env node
/**
 * B442WP Site Scanner v1.5.0 (multi-page, Fred build-time edition)
 *
 * v1.5.0 — CONCURRENT PAGE CAPTURE. After the home page is scanned
 * sequentially (it seeds the queue), remaining pages are visited in waves
 * of up to CONCURRENCY (default 6) using one browser with a bounded page
 * pool. Each worker opens its own browser page, captures, closes it.
 * Slug assignment is deterministic (pre-assigned in rank+seq order before
 * each wave fires). All manifest mutations happen after every wave
 * completes, preserving ordering and dedup. No other behaviour changes.
 *
 * v1.4.0 — AUTH QUERY PASSTHROUGH for token-gated previews (unpublished
 * Lovable projects served at id-preview--*.lovable.app behind a
 * ?__lovable_token=<JWT> gate; untokened requests 302 to lovable's login).
 * The start URL's query params are stored once and re-applied at goto time
 * to every same-origin navigation (seeded routes and discovered anchors
 * alike). Canonical URLs everywhere else (queue, manifest, slugs, dedupe)
 * remain token-free. First tokened response also sets an HttpOnly
 * lovable-auth cookie which the Playwright context keeps — the per-request
 * query is the belt-and-braces/recovery path.
 *
 * v1.3.0 — TEXT-STABILITY WAIT before DOM/screenshot capture. Base44 sites
 * run JS count-up animations (stats counters), typewriter effects, etc.
 * Capturing page.content() mid-animation baked WRONG numbers into the
 * converted theme (e.g. "370+" captured while the live value was "500+";
 * reducedMotion emulation does NOT stop JS counters). After the existing
 * autoscroll + settle (autoscroll triggers intersection-based counters),
 * the scanner now samples document.body.innerText every ~700ms and only
 * captures once two consecutive samples are identical, capped at 10s per
 * page — on cap it captures anyway and marks unstable_text:true plus
 * text_settle_ms on that page's manifest entry. Per-page settle timings
 * are logged to stderr.
 *
 * v1.2.0 — FAITHFUL-COPY nav model. The primary nav is now captured from the
 * RENDERED DOM robust to JS-handler navs (Base44 SPAs use <button> click
 * handlers, not <a href>). For the header/nav container we enumerate every
 * clickable nav element (a, button, [role=menuitem], [role=button]) in DOM
 * order and emit nav:[{text, kind, target}] where kind is
 * page|anchor|external|action and target is resolved by precedence:
 *   1. real href (URL/route)            -> page (internal) | external
 *   2. data-* / aria-controls anchor    -> anchor (#id)
 *   3. nav label matched to an on-page section heading/id -> anchor (#slug)
 *   4. pure JS CTA with no resolvable target -> action
 * Header CTA buttons are captured the same way (the last gold/solid button).
 * The home page also emits sections:[{slug, heading, anchor}] so anchor nav
 * items can resolve to a real on-page section. A logo {src, alt} is captured.
 *
 * Everything v1.1.0 captured (computed styles, fonts, screenshots, images,
 * palette, multi-page crawl, --seeds) is unchanged.
 *
 * Crawls a live site with headless Chromium (Playwright) breadth-first
 * from the start URL and captures, per page:
 *   - scan/{slug}.html  — full rendered DOM (page.content())
 *   - scan/{slug}.png   — full-page desktop screenshot (1440x900 viewport)
 *   - getComputedStyle() for h1/h2/h3/p/a/nav/button/body (9 props)
 *   - every <img> (src, alt, natural size, bounding box) and CSS
 *     background-image URLs on visible elements > 100x100px
 *   - page title, meta description, first h1 text
 *
 * Home page additionally captures everything live-scan v3.1.0 captures:
 * document.fonts, Google Fonts links / @import discovery, :root vars,
 * palette, nav menu (cap 12).
 *
 * Crawl: candidate URLs ranked nav-order > seeded routes > home-page
 * order > discovery order, breadth-first (each scanned page's
 * same-origin anchors are appended). Same-origin only; auth/admin
 * slugs, route params, file extensions, mailto/tel skipped.
 * Hash/query/trailing-slash normalized.
 *
 * v1.1.0: optional --seeds=<path-to-json> argument — a JSON array of
 * absolute URLs or paths (known routes from analysis_data) that join
 * the crawl queue at rank 0.5 (after home nav anchors, before home
 * body links). Seeds pass through the same normalize/dedupe/filter
 * pipeline as discovered anchors. Required for SPA sites whose nav
 * uses JS click-handlers instead of <a> anchors (the crawler finds
 * 0 anchors on those sites and scans only the home page).
 *
 * Output: scan/manifest.json with pages[], fonts, google_font_links,
 * palette, root_vars, nav, errors[], total bytes.
 *
 * Resilience: per-page try/catch (failures logged to manifest.errors[],
 * crawl continues). Global hard deadline 45 minutes — manifest written
 * with whatever finished, and the manifest is marked truncated:true with
 * a truncated_reason (plus a stdout warning) so a partial scan can never
 * be mistaken for a complete one.
 *
 * Usage: node site-scan.js <start_url> <outdir> [maxPages=200, 0=unlimited] [--seeds=<json>]
 */

'use strict';

const { chromium } = require('playwright');
const fs   = require('fs');
const path = require('path');

const SCANNER_VERSION = '1.5.0';
const CONCURRENCY = (() => {
    const flag = (process.argv.find(a => a.startsWith('--concurrency=')) || '').slice('--concurrency='.length);
    const n = flag ? parseInt(flag, 10) : 6;
    const bounded = (Number.isFinite(n) && n >= 1) ? n : 6;
    // Hard ceiling at 6: this box has 4 CPUs and 15GB RAM, and Chromium
    // tabs are far heavier per unit than plain HTTP concurrency. Do not
    // raise this further, an out of memory kill produces a failed build.
    return Math.min(6, bounded);
})();
const GLOBAL_DEADLINE_MS = 45 * 60 * 1000;
const PAGE_GOTO_TIMEOUT  = 25000;
const TEXT_STABLE_INTERVAL_MS = 700;   // v1.3.0 gap between innerText samples
const TEXT_STABLE_CAP_MS      = 10000; // v1.3.0 max per-page wait before capturing anyway

const cliArgs = process.argv.slice(2);
const positional = cliArgs.filter(a => !a.startsWith('--'));
const seedsArg = (cliArgs.find(a => a.startsWith('--seeds=')) || '').slice('--seeds='.length);
const [startUrlArg, outDirArg, maxPagesArg] = positional;

if (!startUrlArg || !outDirArg) {
    process.stderr.write('Usage: node site-scan.js <start_url> <outdir> [maxPages=200, 0=unlimited] [--seeds=<json>]\n');
    process.exit(1);
}

// v1.1.0 — known routes (from analysis_data) seeded into the crawl queue
let SEED_URLS = [];
if (seedsArg) {
    try {
        const parsed = JSON.parse(fs.readFileSync(seedsArg, 'utf8'));
        if (Array.isArray(parsed)) {
            SEED_URLS = parsed.filter(s => typeof s === 'string' && s.trim() !== '');
        }
    } catch (e) {
        process.stderr.write('Seeds file unreadable (non-fatal): ' + e.message + '\n');
    }
}

// v1.4.0 — AUTH QUERY PASSTHROUGH (token-gated previews, e.g. unpublished
// Lovable projects: ?__lovable_token=...&__lovable_sha=...). The start URL's
// query params are stored once and re-applied to every same-origin
// navigation at goto time. Canonical page URLs (queue keys, manifest,
// slugs, dedupe) stay token-free — normalizeUrl still strips u.search —
// so downstream consumers never see the token. Without this, every seeded
// or discovered sub-path on a token-gated origin would 302 to the
// builder's login (the first tokened load DOES set an HttpOnly auth
// cookie which the browser context keeps, so this is also the recovery
// path if that cookie ever fails or expires mid-crawl).
let AUTH_QUERY  = null; // URLSearchParams from the start URL, or null
let AUTH_ORIGIN = null;
try {
    const su = new URL(startUrlArg);
    const keys = [...su.searchParams.keys()];
    if (keys.length > 0) {
        AUTH_QUERY  = su.searchParams;
        AUTH_ORIGIN = su.origin;
        process.stderr.write('Auth passthrough: ' + keys.length
            + ' start-URL query param(s) re-applied to same-origin navigations ('
            + keys.join(', ') + ')\n');
    }
} catch (e) { /* invalid start URL fails later at first goto */ }

function withAuthQuery(url) {
    if (!AUTH_QUERY) return url;
    try {
        const u = new URL(url);
        if (u.origin !== AUTH_ORIGIN) return url;
        for (const [k, v] of AUTH_QUERY.entries()) {
            if (!u.searchParams.has(k)) u.searchParams.set(k, v);
        }
        return u.href;
    } catch (e) { return url; }
}

// Default 200. A literal '0' means unlimited: no page ceiling at all
// (accuracy is the target here, not cost or time). Any other value is
// floored at 1 so a bad/negative argument can never cap the scan at zero.
const MAX_PAGES = (() => {
    if (maxPagesArg === undefined || maxPagesArg === '') return 200;
    const n = parseInt(maxPagesArg, 10);
    if (!Number.isFinite(n)) return 200;
    if (n === 0) return Infinity;
    return Math.max(1, n);
})();
const MAX_PAGES_LABEL = Number.isFinite(MAX_PAGES) ? String(MAX_PAGES) : 'unlimited';
const OUT_DIR   = path.resolve(outDirArg);
const SCAN_DIR  = path.join(OUT_DIR, 'scan');
fs.mkdirSync(SCAN_DIR, { recursive: true });

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

// ---------------------------------------------------------------- URL utils

const SKIP_SLUG_RE = /(^|\/)(log[-_]?in|log[-_]?out|sign[-_]?in|sign[-_]?up|register|forgot[-_]?password|reset[-_]?password|admin|wp-admin|dashboard|account|my[-_]?account|cart|checkout)(\/|$)/i;
const SKIP_EXT_RE  = /\.(pdf|zip|jpg|jpeg|png|gif|webp|svg|ico|mp3|mp4|webm|mov|avi|doc|docx|xls|xlsx|ppt|pptx|csv|txt|xml|json|rss|atom|gz|tar|dmg|exe|apk)$/i;

let ORIGIN = null;

function normalizeUrl(href, base) {
    let u;
    try { u = new URL(href, base); } catch (e) { return null; }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    if (ORIGIN && u.origin !== ORIGIN) return null;
    u.hash = '';
    u.search = '';
    let p = u.pathname;
    if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
    if (p === '') p = '/';
    if (p.includes(':') || p.includes('*') || p.includes('$')) return null; // route params (react-router :id, wildcards, TanStack $id)
    if (SKIP_SLUG_RE.test(p)) return null;
    if (SKIP_EXT_RE.test(p)) return null;
    return u.origin + p;
}

function slugFromUrl(url) {
    const p = new URL(url).pathname;
    if (p === '/' || p === '') return 'home';
    let slug = p.replace(/^\/+|\/+$/g, '').replace(/\//g, '-')
        .toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/-+/g, '-')
        .replace(/^[-.]+|[-.]+$/g, '');
    return slug || 'home';
}

// --------------------------------------------------------------- page evals

async function autoScroll(page) {
    await page.evaluate(async () => {
        await new Promise((resolve) => {
            let total = 0;
            const step = 600;
            const timer = setInterval(() => {
                window.scrollBy(0, step);
                total += step;
                if (total >= document.body.scrollHeight + 1200 || total > 40000) {
                    clearInterval(timer);
                    window.scrollTo(0, 0);
                    resolve();
                }
            }, 120);
        });
    }).catch(() => {});
}

// v1.3.0 — wait for the page's visible text to stop changing before capture.
// JS count-up counters / typewriter effects keep mutating text after
// networkidle; capturing mid-flight bakes wrong numbers into the theme.
// Counters fire on intersection — autoScroll() has already swept the full
// page (triggering them) by the time this runs, so we only need to wait for
// the animation to finish. Samples document.body.innerText every
// TEXT_STABLE_INTERVAL_MS; stable = two consecutive identical samples.
// Hard cap TEXT_STABLE_CAP_MS, after which we capture anyway and report
// stable:false so the caller can mark unstable_text in the manifest.
async function waitForTextStability(page) {
    const t0 = Date.now();
    let prev = null;
    let samples = 0;
    while (true) {
        const cur = await page.evaluate(
            () => (document.body ? document.body.innerText : '') || ''
        ).catch(() => null);
        samples++;
        if (cur !== null && prev !== null && cur === prev) {
            return { stable: true, waited_ms: Date.now() - t0, samples };
        }
        prev = cur;
        if (Date.now() - t0 + TEXT_STABLE_INTERVAL_MS > TEXT_STABLE_CAP_MS) {
            return { stable: false, waited_ms: Date.now() - t0, samples };
        }
        await page.waitForTimeout(TEXT_STABLE_INTERVAL_MS);
    }
}

async function loadPage(page, url) {
    url = withAuthQuery(url); // v1.4.0 — token-gated previews
    try {
        await page.goto(url, { waitUntil: 'networkidle', timeout: PAGE_GOTO_TIMEOUT });
    } catch (e) {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15000 });
        await page.waitForTimeout(3000);
    }
    await page.evaluate(() => document.fonts.ready.then(() => true)).catch(() => {});
    await autoScroll(page);
    await page.waitForTimeout(1500);
    // v1.3.0 — let count-up/typewriter animations settle before capture
    return await waitForTextStability(page);
}

// Per-page capture: computed styles, images, meta, same-origin anchors
async function capturePageData(page) {
    return page.evaluate(({ selectors, props }) => {
        const out = {
            title: document.title || '',
            meta_description: '',
            h1: '',
            computed: {},
            images: [],
            bg_images: [],
            anchors: [],
            nav_anchors: [],
        };

        const md = document.querySelector('meta[name="description"]');
        if (md) out.meta_description = (md.getAttribute('content') || '').trim().slice(0, 300);
        const h1 = document.querySelector('h1');
        if (h1) out.h1 = (h1.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 200);

        // Computed styles — prefer a visible element with text
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
        }

        const box = (el) => {
            const r = el.getBoundingClientRect();
            return {
                x: Math.round(r.x + window.scrollX), y: Math.round(r.y + window.scrollY),
                w: Math.round(r.width), h: Math.round(r.height),
            };
        };

        // <img> elements
        for (const img of document.querySelectorAll('img')) {
            let src = img.currentSrc || img.src || '';
            if (!src || src.startsWith('data:')) continue;
            try { src = new URL(src, location.href).href; } catch (e) { continue; }
            out.images.push({
                src: src,
                alt: (img.getAttribute('alt') || '').slice(0, 200),
                naturalWidth: img.naturalWidth || 0,
                naturalHeight: img.naturalHeight || 0,
                box: box(img),
            });
        }

        // CSS background-image URLs on visible elements > 100x100
        const seenBg = new Set();
        const all = document.querySelectorAll('body *');
        for (const el of all) {
            if (out.bg_images.length >= 60) break;
            const r = el.getBoundingClientRect();
            if (r.width <= 100 || r.height <= 100) continue;
            const bg = getComputedStyle(el).backgroundImage;
            if (!bg || bg === 'none') continue;
            const m = bg.match(/url\((['"]?)(.*?)\1\)/);
            if (!m || !m[2] || m[2].startsWith('data:')) continue;
            let abs;
            try { abs = new URL(m[2], location.href).href; } catch (e) { continue; }
            const key = abs + '|' + Math.round(r.width) + 'x' + Math.round(r.height);
            if (seenBg.has(key)) continue;
            seenBg.add(key);
            out.bg_images.push({ url: abs, box: box(el) });
        }

        // All same-origin anchors in DOM order
        for (const a of document.querySelectorAll('a[href]')) {
            const h = a.getAttribute('href');
            if (!h) continue;
            out.anchors.push(h);
        }
        // Nav anchors (header nav first) in DOM order
        for (const a of document.querySelectorAll('header nav a[href], header a[href], nav a[href]')) {
            const h = a.getAttribute('href');
            if (!h) continue;
            out.nav_anchors.push(h);
        }

        return out;
    }, { selectors: SCAN_SELECTORS, props: SCAN_PROPS });
}

// Home-only capture — everything live-scan v3.1.0 collects
async function captureHomeDesign(page) {
    return page.evaluate(() => {
        const out = { vars: {}, documentFonts: [], googleLinks: [], fontSheets: [], cssImports: [], fontFaces: [], nav: [], headerCtas: [], sections: [], logo: null };

        // ── v1.2.0 FAITHFUL NAV MODEL ──────────────────────────────────────
        // Locate the primary nav container: a <header>, a <nav>, or the first
        // top-anchored bar. Base44 sites render the nav inside a top-level
        // <nav class="fixed top-0 ...">.
        const navContainer =
            document.querySelector('header nav') ||
            document.querySelector('header') ||
            document.querySelector('nav') ||
            null;

        // Build a stable slug from arbitrary heading text.
        const slugify = (t) => (t || '').toLowerCase().trim()
            .replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '').slice(0, 60);

        // Collect on-page sections (id + first heading) so anchor nav items
        // can resolve to a real #target. Prefer explicit element ids.
        const sectionMap = [];   // {slug, heading, anchor}
        const headingToAnchor = {};
        const idEls = document.querySelectorAll('section[id], div[id], main [id], [id]');
        for (const el of idEls) {
            const id = el.getAttribute('id');
            if (!id || /^(root|app|__|wpadminbar)/.test(id)) continue;
            // first heading inside
            const h = el.querySelector('h1, h2, h3');
            const heading = h ? (h.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 120) : '';
            if (!sectionMap.find(s => s.anchor === '#' + id)) {
                sectionMap.push({ slug: id, heading: heading, anchor: '#' + id });
                if (heading) headingToAnchor[heading.toLowerCase()] = '#' + id;
            }
        }
        out.sections = sectionMap;

        // Synonym groups: a nav label can name a section by a different word
        // than the heading/id (e.g. "Reviews" -> a "What Clients Say" /
        // "testimonials" section). These are faithful target resolutions: the
        // VISIBLE label text is never changed, only the scroll destination.
        const SECTION_SYNONYMS = [
            { words: ['review', 'reviews', 'testimonial', 'testimonials'], match: ['testimonial', 'review', 'client', 'what clients', 'what our clients'] },
            { words: ['about', 'about us', 'our story', 'story'], match: ['about', 'our story', 'who we are'] },
            { words: ['contact', 'contact us', 'get in touch'], match: ['contact', 'get in touch', 'reach'] },
            { words: ['services', 'service', 'what we do', 'how we serve', 'how we help', 'buyers', 'sellers', 'homeowners', 'buy', 'sell'], match: ['service', 'how we serve', 'how we help', 'what we do', 'what we offer'] },
            { words: ['pricing', 'plans', 'prices'], match: ['pricing', 'plans', 'prices'] },
            { words: ['faq', 'faqs', 'questions'], match: ['faq', 'questions'] },
            { words: ['team', 'our team', 'people'], match: ['team', 'our people'] },
            { words: ['gallery', 'portfolio', 'work'], match: ['gallery', 'portfolio', 'our work'] },
            { words: ['home'], match: ['home', 'welcome'] },
        ];

        // Heuristic: map a nav label to a section anchor.
        // 1. exact id match (label slug === section id)
        // 2. section heading/id contains the label or vice-versa
        // 3. synonym group: label word group matches a section heading/id token
        const resolveAnchor = (label) => {
            const ls = slugify(label);
            const ll = label.toLowerCase().trim();

            // 1. direct id hit
            for (const s of sectionMap) {
                if (s.slug.toLowerCase() === ls) return s.anchor;
            }
            // 2. heading contains the full label (>=4 chars to avoid noise)
            if (ll.length >= 4) {
                for (const s of sectionMap) {
                    const hl = (s.heading || '').toLowerCase();
                    if (hl && hl.includes(ll)) return s.anchor;
                }
            }
            // 3. synonym group resolution
            const grp = SECTION_SYNONYMS.find(g => g.words.includes(ll));
            if (grp) {
                for (const s of sectionMap) {
                    const hl = (s.heading || '').toLowerCase();
                    const sl = s.slug.toLowerCase();
                    for (const m of grp.match) {
                        if (sl.includes(m.replace(/\s+/g, '')) || (hl && hl.includes(m))) {
                            return s.anchor;
                        }
                    }
                    // also match the section id directly to any word in the group
                    if (grp.words.includes(sl)) return s.anchor;
                }
            }
            return null;
        };

        // Resolve a single clickable element to {text, kind, target}.
        const origin = location.origin;
        const resolveClickable = (el) => {
            const text = (el.textContent || '').trim().replace(/\s+/g, ' ');
            if (!text || text.length > 60) return null;

            // 1. real href
            let href = null;
            if (el.tagName === 'A' && el.getAttribute('href')) href = el.getAttribute('href');
            if (!href) {
                const inner = el.querySelector && el.querySelector('a[href]');
                if (inner && inner.textContent && inner.textContent.trim() === text) href = inner.getAttribute('href');
            }
            if (href) {
                if (/^(mailto:|tel:)/i.test(href)) return { text, kind: 'external', target: href };
                let u = null;
                try { u = new URL(href, location.href); } catch (e) { u = null; }
                if (u) {
                    if (u.origin === origin) {
                        // internal page or in-page anchor
                        if ((u.pathname === location.pathname || u.pathname === '/' ) && u.hash) {
                            return { text, kind: 'anchor', target: u.hash };
                        }
                        let p = u.pathname;
                        if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
                        if (u.hash && p === location.pathname) return { text, kind: 'anchor', target: u.hash };
                        return { text, kind: 'page', target: (p || '/') + (u.hash || '') };
                    }
                    return { text, kind: 'external', target: u.href };
                }
                if (href.startsWith('#')) return { text, kind: 'anchor', target: href };
            }

            // 2. data-* / aria-controls anchor
            const ariaCtrl = el.getAttribute && el.getAttribute('aria-controls');
            if (ariaCtrl && document.getElementById(ariaCtrl)) {
                return { text, kind: 'anchor', target: '#' + ariaCtrl };
            }
            const dataTargets = ['data-target', 'data-scroll', 'data-scroll-to', 'data-section', 'data-anchor', 'data-to', 'data-href'];
            for (const da of dataTargets) {
                const v = el.getAttribute && el.getAttribute(da);
                if (v) {
                    const anchor = v.startsWith('#') ? v : '#' + slugify(v);
                    return { text, kind: 'anchor', target: anchor };
                }
            }

            // 3. label matched to an on-page section heading/id
            const anchor = resolveAnchor(text);
            if (anchor) return { text, kind: 'anchor', target: anchor };

            // 4. pure JS CTA, no resolvable target
            return { text, kind: 'action', target: null };
        };

        if (navContainer) {
            // Enumerate clickable nav elements in DOM order. We pick top-level
            // clickables only (a/button/[role]) and skip the mobile hamburger
            // (a button whose only child is an svg with no text).
            const clickSel = 'a[href], button, [role="menuitem"], [role="button"]';
            const candidates = navContainer.querySelectorAll(clickSel);
            const seenText = new Set();
            for (const el of candidates) {
                // skip nested clickable inside another captured clickable
                if (el.closest('a[href], button') && el.closest('a[href], button') !== el &&
                    (el.tagName !== 'A')) {
                    // allow only if it is itself the outermost button
                    const outer = el.closest('button');
                    if (outer && outer !== el) continue;
                }
                const text = (el.textContent || '').trim().replace(/\s+/g, ' ');
                // logo button: no text but contains an <img>
                if (!text) {
                    const img = el.querySelector && el.querySelector('img');
                    if (img && !out.logo) {
                        let src = img.currentSrc || img.getAttribute('src') || '';
                        try { src = new URL(src, location.href).href; } catch (e) {}
                        out.logo = { src: src, alt: (img.getAttribute('alt') || '').slice(0, 160) };
                    }
                    continue; // hamburger / icon-only buttons skipped
                }
                if (text.length > 60) continue;
                const key = text.toLowerCase();
                if (seenText.has(key)) continue;
                const r = resolveClickable(el);
                if (!r) continue;
                seenText.add(key);
                // Classify CTA: a button styled as a solid pill (has background
                // color via inline style or btn class) AND is the last item is
                // a header CTA; but we keep ALL items in nav[] verbatim and ALSO
                // mirror CTA-looking ones into headerCtas for the builder.
                const inlineBg = (el.getAttribute && el.getAttribute('style') || '');
                const isPill = /background\s*:\s*(?!transparent)/i.test(inlineBg) ||
                               /\bbtn\b|button/i.test(el.className || '');
                out.nav.push(r);
                if (isPill) out.headerCtas.push(r);
            }
        }

        // Fallback logo: any <img> in the header container with an alt.
        if (!out.logo && navContainer) {
            const img = navContainer.querySelector('img');
            if (img) {
                let src = img.currentSrc || img.getAttribute('src') || '';
                try { src = new URL(src, location.href).href; } catch (e) {}
                out.logo = { src: src, alt: (img.getAttribute('alt') || '').slice(0, 160) };
            }
        }
        // ── end nav model ──────────────────────────────────────────────────

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
            try { walkRules(sheet.cssRules); } catch (e) { /* cross-origin */ }
        }

        const seen = new Set();
        document.fonts.forEach(f => {
            const k = f.family + '|' + f.weight + '|' + f.style;
            if (seen.has(k)) return;
            seen.add(k);
            out.documentFonts.push({ family: f.family.replace(/^["']|["']$/g, ''), weight: f.weight, style: f.style, status: f.status });
        });

        // (v1.2.0: the anchor-only nav block was removed — the faithful nav
        //  model above captures buttons + anchors + role=menuitem verbatim.)

        document.querySelectorAll('link[rel="stylesheet"][href]').forEach(l => {
            const h = l.href;
            if (/fonts\.googleapis\.com/.test(h)) out.googleLinks.push(h);
            else if (/font/i.test(h)) out.fontSheets.push(h);
        });

        return out;
    });
}

// -------------------------------------------------------------------- main

(async () => {
    const startedAt = Date.now();
    const manifest = {
        scanner_version: SCANNER_VERSION,
        start_url: startUrlArg,
        scanned_at: new Date().toISOString(),
        max_pages: Number.isFinite(MAX_PAGES) ? MAX_PAGES : 0, // 0 = unlimited, matches CLI convention
        pages: [],
        fonts: {},
        google_font_links: [],
        palette: {},
        root_vars: {},
        nav: [],
        header_ctas: [],
        sections: [],
        logo: null,
        site_title: '',
        site_description: '',
        errors: [],
        total_bytes: 0,
        duration_ms: 0,
    };

    const writeManifest = () => {
        manifest.duration_ms = Date.now() - startedAt;
        manifest.total_bytes = manifest.pages.reduce(
            (n, p) => n + (p.bytes_html || 0) + (p.bytes_png || 0), 0);
        fs.writeFileSync(path.join(SCAN_DIR, 'manifest.json'), JSON.stringify(manifest, null, 2));
    };

    let browser = null;
    let finished = false;
    // Hoisted so the deadline handler below can report how many pages were
    // still queued (unvisited) at the moment it fired. Assigned once inside
    // the try block, below; never reassigned, only pushed to via enqueue().
    let queue = [];
    const deadline = setTimeout(async () => {
        const deadlineMinutes = Math.round(GLOBAL_DEADLINE_MS / 60000);
        const pagesCaptured = manifest.pages.length;
        const pagesQueued = queue.length;
        const reason = 'global deadline of ' + deadlineMinutes + ' minutes reached: captured '
            + pagesCaptured + ' page(s), ' + pagesQueued + ' still queued and not scanned';
        // Make the truncation loud and impossible to miss downstream. The
        // caller (worker.php / fred-drive.php) only checks that manifest.json
        // exists, not how many pages it holds, and exit code 0 below looks
        // like a normal completion. Without this flag a truncated scan is
        // indistinguishable from a complete one.
        manifest.truncated = true;
        manifest.truncated_reason = reason;
        manifest.errors.push({ url: null, error: 'SCAN TRUNCATED: ' + reason });
        process.stderr.write('SiteScan hard deadline hit (' + deadlineMinutes + ' min) — writing partial manifest\n');
        process.stdout.write('WARNING: SITE SCAN TRUNCATED. ' + reason + '\n');
        writeManifest();
        if (browser) { try { await browser.close(); } catch (_) {} }
        // Exit code intentionally left at 0: a partial scan is still better
        // than none, and the manifest flag plus this log line are what carry
        // the truncation signal, not the process exit status.
        process.exit(0);
    }, GLOBAL_DEADLINE_MS);

    try {
        const startNorm = (() => {
            const u = new URL(startUrlArg);
            ORIGIN = u.origin;
            u.hash = ''; u.search = '';
            let p = u.pathname;
            if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
            return u.origin + (p || '/');
        })();

        browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            viewport: { width: 1440, height: 900 },
            ignoreHTTPSErrors: true,
        });

        // Crawl state. queue entries keep discovery rank:
        // rank 0 = nav anchors (home), rank 1 = home body anchors,
        // rank 2+ = breadth-first discovery order.
        const visited = new Set();      // normalized URLs scanned or queued
        // queue is declared at the outer scope above (hoisted for the
        // deadline handler); this wave-processing loop only pushes to it.
        let seqCounter = 0;
        const enqueue = (url, rank) => {
            if (!url || visited.has(url)) return;
            visited.add(url);
            queue.push({ url, rank, seq: seqCounter++ });
        };

        const usedSlugs = new Set();
        const uniqueSlug = (base) => {
            let s = base, i = 2;
            while (usedSlugs.has(s)) s = base + '-' + (i++);
            usedSlugs.add(s);
            return s;
        };

        // v1.5.0 — pure capture function: takes a pre-opened page and a
        // pre-assigned slug, loads and captures the page, writes the
        // html/png files, and returns the manifest entry + raw anchor
        // lists. Does NOT touch any shared state (manifest, queue,
        // visited, usedSlugs) — callers merge results after each wave.
        const captureOnePage = async (pg, url, slug) => {
            const t0 = Date.now();
            const stability = await loadPage(pg, url);
            const data = await capturePageData(pg);

            const htmlFile = slug + '.html';
            const pngFile  = slug + '.png';

            const html = await pg.content();
            fs.writeFileSync(path.join(SCAN_DIR, htmlFile), html);

            let bytesPng = 0;
            const pngPath = path.join(SCAN_DIR, pngFile);
            await pg.screenshot({ path: pngPath, fullPage: true, timeout: 20000 })
                .catch(() => pg.screenshot({ path: pngPath, fullPage: false }).catch(() => {}));
            if (fs.existsSync(pngPath)) bytesPng = fs.statSync(pngPath).size;

            const entry = {
                slug,
                url,
                title: data.title,
                h1: data.h1,
                meta_description: data.meta_description,
                html_file: 'scan/' + htmlFile,
                screenshot_file: bytesPng ? ('scan/' + pngFile) : null,
                bytes_html: Buffer.byteLength(html, 'utf8'),
                bytes_png: bytesPng,
                n_images: data.images.length,
                computed: data.computed,
                images: data.images,
                bg_images: data.bg_images,
            };
            // v1.3.0 — text-stability telemetry; unstable_text only on cap-out
            if (stability) {
                entry.text_settle_ms = stability.waited_ms;
                if (!stability.stable) entry.unstable_text = true;
                process.stderr.write(`  text-stability ${slug}: ${stability.stable ? 'stable' : 'UNSTABLE (cap)'} after ${stability.waited_ms}ms (${stability.samples} samples)\n`);
            }

            return {
                entry,
                anchors:     data.anchors,
                nav_anchors: data.nav_anchors,
                elapsed_ms:  Date.now() - t0,
            };
        };

        // Page 1: home — always sequential; it seeds the queue and
        // populates manifest.fonts/palette/nav/root_vars before we can
        // process anything else.
        const homePage = await context.newPage();
        homePage.setDefaultTimeout(PAGE_GOTO_TIMEOUT);
        visited.add(startNorm);
        usedSlugs.clear();
        process.stderr.write(`SiteScan v${SCANNER_VERSION} — start ${startNorm}, maxPages=${MAX_PAGES_LABEL}, concurrency=${CONCURRENCY}\n`);
        try {
            const homeSlug = uniqueSlug(slugFromUrl(startNorm));
            const homeResult = await captureOnePage(homePage, startNorm, homeSlug);

            // Home-only: capture design tokens (fonts, nav, palette, vars)
            const home = await captureHomeDesign(homePage);
            manifest.root_vars = home.vars;
            manifest.nav = home.nav || [];
            manifest.header_ctas = home.headerCtas || [];
            manifest.sections = home.sections || [];
            manifest.logo = home.logo || null;
            manifest.site_title = homeResult.entry.title || '';
            manifest.site_description = homeResult.entry.meta_description || '';
            // v1.2.0: queue every internal 'page' nav target
            for (const n of (home.nav || [])) {
                if (n.kind === 'page' && n.target && n.target.startsWith('/')) {
                    enqueue(normalizeUrl(n.target, startNorm), 0);
                }
            }
            manifest.fonts = {
                document_fonts: home.documentFonts,
                font_stylesheets: [...new Set(home.fontSheets)],
                css_imports: [...new Set(home.cssImports)],
                font_faces: home.fontFaces.slice(0, 40),
            };
            manifest.google_font_links = [...new Set(home.googleLinks.concat(
                home.cssImports.filter(u => /fonts\.googleapis\.com/.test(u))
            ))];
            const c = homeResult.entry.computed;
            manifest.palette = {
                background: (c.body && c.body['background-color']) || '',
                text:       (c.body && c.body.color) || '',
                heading:    (c.h1 && c.h1.color) || (c.h2 && c.h2.color) || '',
                link:       (c.a && c.a.color) || '',
                button_bg:  (c.btn && c.btn['background-color']) || '',
                button_text:(c.btn && c.btn.color) || '',
            };
            // Home candidate URLs: nav anchors rank 0, body anchors rank 1
            for (const h of homeResult.nav_anchors) enqueue(normalizeUrl(h, startNorm), 0);
            for (const h of homeResult.anchors)     enqueue(normalizeUrl(h, startNorm), 1);

            manifest.pages.push(homeResult.entry);
            process.stderr.write(
                `[${manifest.pages.length}/${MAX_PAGES_LABEL}] ${homeSlug}  html=${homeResult.entry.bytes_html}B  ` +
                `imgs=${homeResult.entry.n_images}  png=${homeResult.entry.bytes_png}B  ${homeResult.elapsed_ms}ms  ${startNorm}\n`);
        } catch (e) {
            manifest.errors.push({ url: startNorm, error: 'home: ' + e.message });
            process.stderr.write('FATAL home page failed: ' + e.message + '\n');
        } finally {
            try { await homePage.close(); } catch (_) {}
        }

        // v1.1.0 — seeded known routes join the queue at rank 0.5 (after the
        // home page's nav anchors at rank 0, before its body anchors at rank
        // 1). Same normalize/dedupe/filter pipeline as discovered anchors;
        // URLs the home page already discovered keep their original rank.
        let seededCount = 0;
        for (const s of SEED_URLS) {
            const norm = normalizeUrl(s, startNorm);
            if (norm && !visited.has(norm)) {
                enqueue(norm, 0.5);
                seededCount++;
            }
        }
        if (SEED_URLS.length) {
            process.stderr.write(`Seeds: ${SEED_URLS.length} supplied, ${seededCount} enqueued (rank 0.5)\n`);
        }

        // v1.5.0 — Remaining pages: wave-based bounded-concurrency pool.
        // Each wave: sort queue, take up to CONCURRENCY items, pre-assign
        // slugs deterministically (in rank+seq order, single-threaded so
        // usedSlugs stays consistent), then open N browser pages
        // concurrently and capture. After all tasks settle, merge entries
        // into manifest.pages in wave-assignment order, enqueue newly
        // discovered anchors. Every page is closed in a try/finally so
        // chromium pages don't leak even on capture failure.
        while (manifest.pages.length < MAX_PAGES) {
            queue.sort((a, b) => (a.rank - b.rank) || (a.seq - b.seq));

            // Take up to CONCURRENCY items for this wave (respecting the cap)
            const remaining = MAX_PAGES - manifest.pages.length;
            const waveSize  = Math.min(CONCURRENCY, remaining, queue.length);
            if (waveSize === 0) break;
            const wave = queue.splice(0, waveSize);

            // Pre-assign slugs now, single-threaded, before any async work.
            // This guarantees deterministic slug ordering regardless of which
            // worker finishes first.
            const waveTasks = wave.map(item => ({
                url:  item.url,
                slug: uniqueSlug(slugFromUrl(item.url)),
            }));

            // Run all tasks in this wave concurrently, each with its own page.
            const waveResults = await Promise.all(waveTasks.map(async (task) => {
                const pg = await context.newPage();
                pg.setDefaultTimeout(PAGE_GOTO_TIMEOUT);
                try {
                    const result = await captureOnePage(pg, task.url, task.slug);
                    return { ok: true, task, result };
                } catch (e) {
                    return { ok: false, task, error: e.message };
                } finally {
                    try { await pg.close(); } catch (_) {}
                }
            }));

            // Merge results in wave-assignment order (preserves rank+seq ordering).
            for (const wr of waveResults) {
                if (!wr.ok) {
                    manifest.errors.push({ url: wr.task.url, error: wr.error });
                    process.stderr.write(`ERROR ${wr.task.url}: ${wr.error}\n`);
                    continue;
                }
                const { task, result } = wr;
                manifest.pages.push(result.entry);
                // Breadth-first harvest: this page's anchors at rank 2+
                for (const h of result.anchors) {
                    enqueue(normalizeUrl(h, task.url), 2 + manifest.pages.length);
                }
                process.stderr.write(
                    `[${manifest.pages.length}/${MAX_PAGES_LABEL}] ${task.slug}  html=${result.entry.bytes_html}B  ` +
                    `imgs=${result.entry.n_images}  png=${result.entry.bytes_png}B  ${result.elapsed_ms}ms  ${task.url}\n`);
            }
        }

        await context.close();
        finished = true;
    } catch (e) {
        manifest.errors.push({ url: null, error: 'fatal: ' + e.message });
        process.stderr.write('SiteScan fatal: ' + e.message + '\n');
    } finally {
        clearTimeout(deadline);
        if (browser) { try { await browser.close(); } catch (_) {} }
        writeManifest();
        process.stderr.write(
            `Done: ${manifest.pages.length} pages, ${manifest.errors.length} errors, ` +
            `${manifest.total_bytes} bytes, ${manifest.duration_ms}ms\n`);
    }
    process.exit(finished && manifest.pages.length > 0 ? 0 : (manifest.pages.length > 0 ? 0 : 1));
})();
