# Engine Update Prompt
## For the chat currently updating the base44towordpress converter

---

## The Core Problem: Uploaded Source Files Are Unreliable

The current pipeline (Parser → Analyzer → ClaudeClient) is built on a false assumption: that the uploaded Base44 zip file contains accurate design information. It does not.

Here is what actually happens when a Base44 app is exported:

- **Tailwind JIT purges everything.** The compiled output only contains classes that were actually used at build time. The source JSX has utility class strings like `text-gray-900 font-semibold tracking-wide` that Vite strips from the live bundle. When the Analyzer scans for classes, it finds source strings — not what the browser actually renders.
- **Images are build-time imports.** `import heroImg from './assets/hero.jpg'` is resolved by Vite into a hashed filename in `dist/`. The source file never contains the real image path. The Parser reads source, so no images are ever extracted.
- **CSS variables may be overridden at runtime.** A `:root {}` block in globals.css may declare `--color-primary: #1a1a1a` but a component's inline style or a JS theme switcher overrides it. The Analyzer reads the declaration, not what the browser computes.
- **Fonts declared in source may not load.** A `@import url(https://fonts.googleapis.com/...)` in globals.css may be the intended font, but if the live site has a different Google Fonts URL injected by a script, or uses a font that falls back at runtime, the source declaration is wrong.
- **Layout is invisible in source.** Section padding, hero height, grid column counts, sticky behaviour — none of this can be recovered from JSX attribute strings. You cannot know that `py-24 md:py-32` renders as 6rem/8rem padding without running the browser.

**The result:** Claude receives inaccurate or empty color/font/spacing data and produces themes that guess the design rather than copy it.

---

## The Fix: Playwright Live Scan as Stage 0

Before Parser runs, a new stage must visit the live URL in a real browser and extract ground-truth design data. This replaces the Analyzer's CSS extraction for colors, fonts, and spacing. The zip source is still used for page structure, component names, and content text.

### What to build

A new PHP class `LiveScanner` that shells out to a Node.js Playwright script and returns structured JSON. The script should:

**1. Launch headless Chromium via Playwright**

```js
const { chromium } = require('playwright');
const browser = await chromium.launch();
const page = await browser.newPage();
await page.setViewportSize({ width: 1440, height: 900 });
await page.goto(url, { waitUntil: 'networkidle' });
```

**2. Extract computed design tokens**

```js
const tokens = await page.evaluate(() => {
    const root = getComputedStyle(document.documentElement);
    const vars = {};
    // All CSS custom properties on :root
    for (const sheet of document.styleSheets) {
        try {
            for (const rule of sheet.cssRules) {
                if (rule.selectorText === ':root') {
                    rule.style.cssText.split(';').forEach(d => {
                        const [k, v] = d.split(':').map(s => s.trim());
                        if (k && k.startsWith('--')) vars[k] = v;
                    });
                }
            }
        } catch(e) {}
    }

    // Computed styles for key elements
    const elements = {
        body:  document.body,
        h1:    document.querySelector('h1'),
        h2:    document.querySelector('h2'),
        h3:    document.querySelector('h3'),
        nav_a: document.querySelector('nav a'),
        btn:   document.querySelector('button, .btn, [class*="button"]'),
        p:     document.querySelector('p'),
    };
    const props = ['font-family','font-size','font-weight','line-height','letter-spacing','color','background-color'];
    const computed = {};
    for (const [key, el] of Object.entries(elements)) {
        if (!el) continue;
        const s = getComputedStyle(el);
        computed[key] = {};
        props.forEach(p => { computed[key][p] = s.getPropertyValue(p); });
    }

    // Google Fonts links
    const fontLinks = [...document.querySelectorAll('link[href*="fonts.googleapis.com"]')]
        .map(l => l.href);

    return { vars, computed, fontLinks };
});
```

**3. Take screenshots**

```js
// Desktop full-page
await page.screenshot({ path: outputDir + '/desktop.png', fullPage: true });

// Mobile
await page.setViewportSize({ width: 390, height: 844 });
await page.screenshot({ path: outputDir + '/mobile.png', fullPage: true });
```

**4. Extract all image URLs**

```js
const images = await page.evaluate(() => {
    const urls = new Set();
    document.querySelectorAll('img[src]').forEach(img => {
        if (!img.src.startsWith('data:')) urls.add(img.src);
    });
    document.querySelectorAll('*').forEach(el => {
        const bg = getComputedStyle(el).backgroundImage;
        const m = bg.match(/url\(["']?(https?[^"')]+)/);
        if (m) urls.add(m[1]);
    });
    return [...urls];
});
```

**5. Extract internal page URLs**

```js
const pages = await page.evaluate(() => {
    const origin = location.origin;
    return [...new Set(
        [...document.querySelectorAll('a[href]')]
            .map(a => a.href)
            .filter(h => h.startsWith(origin) && !h.includes('#'))
    )];
});
```

**6. Optionally visit each internal page and repeat steps 2–4**

Loop through the discovered pages (max 8), run the same extraction. This catches per-page hero images, inner page typography, product images on shop pages.

**7. Return everything as JSON**

```json
{
  "tokens": { "vars": {}, "computed": {} },
  "fontLinks": [],
  "images": [],
  "pages": [],
  "screenshots": { "desktop": "/tmp/scan/desktop.png", "mobile": "/tmp/scan/mobile.png" }
}
```

### How the data flows into the pipeline

- **Replaces** Analyzer's `extractCssVars()`, `extractColors()`, `extractFonts()` — use live scan values instead
- **Feeds Claude** as a new `live_scan` key in the prompt context, labelled as authoritative:

```
## LIVE SITE SCAN (authoritative — use these values, not the source analysis)
Computed colors: ...
Computed fonts: ...
Google Fonts URLs: ...
Screenshots: attached
```

- **Zip source still used for:** page routing structure, component names, entity names, WooCommerce detection, raw text content for demo content

### Priority order for Claude

1. Playwright `getComputedStyle()` values — always use these
2. CSS custom properties from live stylesheets — use as secondary
3. Analyzer zip-extracted values — use only if 1 and 2 are empty
4. Tailwind class inference — last resort, flag as estimated

---

## The Learnings Feed: A Living Knowledge Base

The engine now has a learnings feed at `converter/prompts/learnings.md`. It works like this:

- Every entry records a real problem we discovered on a live conversion, its root cause, and the rule going forward
- `system.php` reads this file at runtime and appends it to every Claude prompt under the heading "LEARNED PATTERNS FROM REAL CONVERSIONS"
- Claude receives this as lived experience and applies the rules proactively — it does not wait to encounter the same problem

**To add a new entry:** append to `converter/prompts/learnings.md` in this format:

```markdown
## YYYY-MM-DD — [Site Name] — [Short Topic]
**Observed:** what we saw on the live converted site
**Root cause:** why it happened
**Rule:** what to do going forward (include code examples where useful)
```

This is the same pattern as the WrkMate training log. Append only — never edit existing entries. The file grows as we do more conversions and catch more edge cases.

**What's already in the learnings feed (seeded from Inkwell Co.):**
- WordPress Navigation block ignores `margin-left: auto` in block JSON → always use CSS override
- `wp_footer` fires on FSE pages too → guard WC injections with `is_woocommerce()`
- CSS media query shorthand resets all four padding sides → use axis-specific properties in responsive overrides
- WooCommerce injects mini-cart block into Navigation → suppress or override with CSS
- Product images are missing after conversion → Playwright live scan is the only reliable source
- Filter tabs (ALL/JOURNEY/WHOLESALE) have no filtering behaviour → generate JS category filter with data attributes

**As we work on more conversions** — whether it's a Flatsome site, a block theme, an ecommerce store — every new problem we fix becomes an entry. The engine gets smarter with every site we ship.

---

## Summary of changes needed

1. **New: `LiveScanner.php`** — PHP class that accepts a URL, runs the Playwright Node script, returns structured JSON
2. **New: `scanner/live-scan.js`** — Playwright script (upgrade from current Puppeteer in scan-styles.js, expand to multi-page + assets)
3. **Updated: `Converter.php`** — run `LiveScanner` as Stage 0 before Parser/Analyzer; pass scan output into all subsequent Claude prompts
4. **Updated: `Analyzer.php`** — accept live scan data as override; skip CSS extraction when scan data is present
5. **Updated: `system.php`** — already done: reads `learnings.md` and appends to every prompt
6. **New: `converter/prompts/learnings.md`** — already seeded with 6 entries from Inkwell Co. conversion

The Playwright dependency (npm package `playwright` + Chromium) needs to be installed on the server. Add to a `package.json` in the scanner directory or at the app root. The PHP `LiveScanner` shells out via `exec()` or `proc_open()`.
