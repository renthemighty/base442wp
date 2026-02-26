# B442WP Style Scanner

Automatically extracts typography and color tokens from a live Base44 app URL.
**No manual DevTools inspection required.**

## How it works

Instead of asking the user to open DevTools → Computed tab, this scanner:

1. **Fetches the live URL** and all linked CSS files
2. **Parses CSS custom properties** (`:root` variables — colors, fonts)
3. **Scans Tailwind utility classes** in the HTML for font weights, sizes, tracking, leading
4. **Detects Google Fonts** `<link>` tags and parses exact family names + weight axes
5. **Extracts hex/HSL colors** from inline Tailwind arbitrary values (`bg-[#e8983f]`)
6. **Optionally uses Puppeteer** for true computed styles (if installed)

> **Rule 1 enforced:** Computed/live styles trump source `globals.css`.
> Base44's Vite plugin often fails to inject font rules on live sites.
> The scanner reads what the browser actually renders, not what the source declares.

## Usage

```bash
# Basic scan — outputs scan-output.json
node scan-styles.js https://myapp.base44.app/

# Custom output file
node scan-styles.js https://myapp.base44.app/ ./my-tokens.json

# Generate CSS vars from scan output
node generate-css-vars.js scan-output.json > ../my-theme/assets/css/tokens.css

# Full pipeline
URL=https://myapp.base44.app/ npm run scan:full
```

## With Puppeteer (enhanced — true computed styles)

```bash
npm install  # installs optional puppeteer dependency
node scan-styles.js https://myapp.base44.app/
```

When Puppeteer is available, the scanner launches a headless Chromium instance,
navigates to the page, and calls `window.getComputedStyle()` on actual DOM elements.
This captures the *exact* font-family, font-weight, font-size, line-height,
letter-spacing, and color that the browser renders — the same values you'd see
in DevTools → Computed tab.

## Without Puppeteer (fallback — CSS fetch method)

Without Puppeteer, the scanner uses a pure Node.js fetch strategy:
- Downloads all `<link rel="stylesheet">` CSS files
- Parses `@font-face`, `:root {}`, `font-family` rules
- Scans Tailwind class strings in the HTML

For Base44 apps, this is highly accurate because:
- Colors come from `:root` CSS vars
- Typography comes from Tailwind utility classes in the HTML
- Google Fonts are declared in `<link>` tags (not JS)

## Output format

```json
{
  "_scannedUrl": "https://myapp.base44.app/",
  "_scannedAt": "2026-02-26T12:00:00Z",
  "_puppeteerUsed": false,
  "typography": {
    "bodyFont": "Inter",
    "headingFont": "Inter",
    "heroFontWeight": 900,
    "bodyFontWeight": 400,
    "heroFontSize": "6rem",
    "heroLetterSpacing": "-0.025em",
    "bodyLineHeight": "1.625",
    "googleFonts": [
      { "name": "Inter", "weights": [400, 500, 600, 700, 800, 900] }
    ]
  },
  "colors": {
    "palette": {
      "primary": "#e8983f",
      "primaryDark": "#d4882f",
      "dark": "#111827",
      "darker": "#030712",
      "text": "#1B2A5C",
      "textMuted": "#6b7280",
      "surface": "#F7F7FC",
      "background": "#ffffff"
    }
  },
  "computedStyles": {
    "h1": { "font-family": "...", "font-weight": "900", ... },
    "body": { ... }
  }
}
```
