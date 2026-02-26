#!/usr/bin/env node
/**
 * B442WP CSS Vars Generator
 * Reads a scan-output.json and writes a :root CSS block + theme.css header comment
 * with all extracted design tokens ready to paste into your WordPress theme CSS.
 *
 * Usage:
 *   node generate-css-vars.js scan-output.json
 *   node generate-css-vars.js scan-output.json > ../screwed-theme/assets/css/tokens.css
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const inputFile = process.argv[2];
if (!inputFile) {
  console.error('Usage: node generate-css-vars.js <scan-output.json>');
  process.exit(1);
}

const tokens = JSON.parse(fs.readFileSync(inputFile, 'utf8'));
const { typography: t, colors: c } = tokens;

// ─── Font stack resolver ───────────────────────────────────────────────────

function fontStack(name) {
  const SYSTEM_STACKS = {
    'System Sans': 'ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"',
    'System Serif': "ui-serif, Georgia, Cambria, 'Times New Roman', Times, serif",
    'System Mono': 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
  };
  if (SYSTEM_STACKS[name]) return SYSTEM_STACKS[name];
  // Google Font → with system fallback
  if (/serif/i.test(name)) return `'${name}', ui-serif, Georgia, Cambria, 'Times New Roman', Times, serif`;
  if (/mono|code/i.test(name)) return `'${name}', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace`;
  return `'${name}', ui-sans-serif, system-ui, sans-serif`;
}

// ─── Google Fonts link builder ─────────────────────────────────────────────

function buildGoogleFontsUrl(googleFonts) {
  if (!googleFonts || googleFonts.length === 0) return null;
  const parts = googleFonts.map(f => {
    const weights = f.weights && f.weights.length
      ? f.weights.map(w => `0,${w}`).join(';')
      : '0,400;0,700';
    return `family=${encodeURIComponent(f.name)}:ital,wght@${weights}`;
  });
  return `https://fonts.googleapis.com/css2?${parts.join('&')}&display=swap`;
}

// ─── CSS output ────────────────────────────────────────────────────────────

const gfUrl = buildGoogleFontsUrl(t.googleFonts);
const scannedAt = tokens._scannedAt ? new Date(tokens._scannedAt).toLocaleString() : 'unknown';

let output = `/*
 * ============================================================
 *  B442WP Auto-Extracted Design Tokens
 *  Source URL : ${tokens._scannedUrl}
 *  Scanned at : ${scannedAt}
 *  Method     : ${tokens._puppeteerUsed ? 'Puppeteer (true computed styles)' : 'CSS fetch + Tailwind parse'}
 * ============================================================
 *
 *  Rule 1: Computed styles trump source code.
 *  These values come from the LIVE site, not globals.css.
 *  They account for failed font injections, system fallbacks, etc.
 * ============================================================
 */

`;

if (gfUrl) {
  output += `/*
 * Google Fonts — output via wp_head at priority 5 (never wp_enqueue_style)
 * See Rule 2: esc_url() mangles CSS2 API axis values.
 *
 * PHP snippet for functions.php:
 *
 * add_action( 'wp_head', function() {
 *     echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\\n";
 *     echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\\n";
 *     echo '<link href="${gfUrl}" rel="stylesheet">' . "\\n";
 * }, 5 );
 */

`;
}

output += `:root {

  /* ── Colors ──────────────────────────────── */
  --color-primary:      ${c.palette.primary};
  --color-primary-dark: ${c.palette.primaryDark};
  --color-dark:         ${c.palette.dark};
  --color-darker:       ${c.palette.darker};
  --color-text:         ${c.palette.text};
  --color-text-muted:   ${c.palette.textMuted};
  --color-surface:      ${c.palette.surface};
  --color-bg:           ${c.palette.background};

`;

// Add any extra CSS vars found in source
const extraVars = Object.entries(tokens.cssVarsRaw || {})
  .filter(([k]) => !['--primary','--secondary','--background','--foreground','--muted','--border',
                      '--input','--ring','--radius','--card','--popover','--accent',
                      '--destructive'].includes(k))
  .slice(0, 20);
if (extraVars.length) {
  output += `  /* ── Source CSS custom properties ───────── */\n`;
  extraVars.forEach(([k, v]) => {
    output += `  ${k}: ${v};\n`;
  });
  output += '\n';
}

output += `  /* ── Typography ─────────────────────────── */
  --font-body:          ${fontStack(t.bodyFont)};
  --font-heading:       ${fontStack(t.headingFont)};
  --font-weight-body:   ${t.bodyFontWeight || 400};
  --font-weight-hero:   ${t.heroFontWeight || 900};

  /* ── Sizes ───────────────────────────────── */
  --size-hero:          ${t.heroFontSize || '4.5rem'};
  --tracking-hero:      ${t.heroLetterSpacing || '-0.025em'};
  --leading-body:       ${t.bodyLineHeight || '1.625'};

}

/*
 * ── Body defaults ──────────────────────────────────────────────────────────
 */
html {
  -webkit-text-size-adjust: 100%;
  font-feature-settings: normal;
  font-variation-settings: normal;
  scroll-behavior: smooth;
}

body {
  font-family: var(--font-body);
  font-weight: var(--font-weight-body);
  line-height: var(--leading-body);
  color: var(--color-text);
  background-color: var(--color-bg);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

h1, h2, h3, h4, h5, h6 {
  font-family: var(--font-heading);
  /* Note: weight/size/line-height set per-component (Rule 4) */
}
`;

// Summary to stderr
console.error('\n── Token Summary ────────────────────────────────');
console.error('Body font:       ', t.bodyFont);
console.error('Heading font:    ', t.headingFont);
console.error('Hero size:       ', t.heroFontSize);
console.error('Hero weight:     ', t.heroFontWeight);
console.error('Body line-height:', t.bodyLineHeight);
console.error('Primary color:   ', c.palette.primary);
console.error('Dark bg:         ', c.palette.dark);
if (gfUrl) console.error('\nGoogle Fonts URL:', gfUrl.slice(0, 80) + '...');
console.error('─────────────────────────────────────────────────\n');

process.stdout.write(output);
