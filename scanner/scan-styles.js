#!/usr/bin/env node
/**
 * B442WP Style Scanner
 * Automatically extracts typography + color tokens from a live Base44 app URL.
 * No manual DevTools inspection required.
 *
 * Usage:
 *   node scan-styles.js <url> [output.json]
 *   node scan-styles.js https://myapp.base44.app/ ./tokens.json
 *
 * Strategy (no headless browser required):
 *   1. Fetch the live URL HTML
 *   2. Extract all <link rel="stylesheet"> URLs → download & parse each CSS file
 *   3. Extract inline <style> tags
 *   4. Parse :root CSS custom properties (colors, fonts)
 *   5. Scan Tailwind utility classes in the HTML for font/color hints
 *   6. Extract Google Fonts <link> tags for exact font names + weights
 *   7. Detect color hex values from bg-[#...] / text-[#...] patterns
 *   8. Output a structured JSON of design tokens
 *
 * If puppeteer is installed (optional), uses it for true computed styles.
 */

'use strict';

const https = require('https');
const http  = require('http');
const url   = require('url');
const fs    = require('fs');
const path  = require('path');

// ─── CONFIG ──────────────────────────────────────────────────────────────────

const TARGET_ELEMENTS = ['h1','h2','h3','h4','p','body','nav a','button','span'];
const TAILWIND_FONT_WEIGHTS = {
  'font-thin':900,'font-extralight':200,'font-light':300,'font-normal':400,
  'font-medium':500,'font-semibold':600,'font-bold':700,'font-extrabold':800,'font-black':900,
};
const TAILWIND_FONT_SIZES = {
  'text-xs':'0.75rem','text-sm':'0.875rem','text-base':'1rem','text-lg':'1.125rem',
  'text-xl':'1.25rem','text-2xl':'1.5rem','text-3xl':'1.875rem','text-4xl':'2.25rem',
  'text-5xl':'3rem','text-6xl':'3.75rem','text-7xl':'4.5rem','text-8xl':'6rem','text-9xl':'8rem',
};
const TAILWIND_TRACKING = {
  'tracking-tighter':'-0.05em','tracking-tight':'-0.025em','tracking-normal':'0',
  'tracking-wide':'0.025em','tracking-wider':'0.05em','tracking-widest':'0.1em',
};
const TAILWIND_LEADING = {
  'leading-none':'1','leading-tight':'1.25','leading-snug':'1.375','leading-normal':'1.5',
  'leading-relaxed':'1.625','leading-loose':'2',
};

// ─── FETCH HELPERS ───────────────────────────────────────────────────────────

function fetchUrl(rawUrl, redirectCount = 0) {
  return new Promise((resolve, reject) => {
    if (redirectCount > 5) return reject(new Error('Too many redirects'));
    const parsed = url.parse(rawUrl);
    const lib = parsed.protocol === 'https:' ? https : http;
    const opts = {
      hostname: parsed.hostname,
      port: parsed.port,
      path: parsed.path,
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (compatible; B442WP-Scanner/1.0)',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.5',
      },
      timeout: 15000,
    };
    const req = lib.request(opts, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        const redirect = url.resolve(rawUrl, res.headers.location);
        return resolve(fetchUrl(redirect, redirectCount + 1));
      }
      let body = '';
      res.on('data', chunk => body += chunk);
      res.on('end', () => resolve({ status: res.statusCode, body, headers: res.headers, finalUrl: rawUrl }));
    });
    req.on('error', reject);
    req.on('timeout', () => { req.destroy(); reject(new Error('Request timed out')); });
    req.end();
  });
}

function resolveUrl(base, relative) {
  try { return url.resolve(base, relative); } catch { return relative; }
}

// ─── CSS PARSERS ─────────────────────────────────────────────────────────────

function extractCssVars(cssText) {
  const vars = {};
  const rootMatch = cssText.match(/:root\s*\{([^}]+)\}/gs);
  if (!rootMatch) return vars;
  rootMatch.forEach(block => {
    const lines = block.matchAll(/--([\w-]+)\s*:\s*([^;]+);/g);
    for (const [, name, val] of lines) {
      vars['--' + name] = val.trim();
    }
  });
  return vars;
}

function extractFontFaces(cssText) {
  const faces = [];
  const matches = cssText.matchAll(/@font-face\s*\{([^}]+)\}/gs);
  for (const [, block] of matches) {
    const family = (block.match(/font-family\s*:\s*['"]?([^'";]+)['"]?/) || [])[1];
    const weight = (block.match(/font-weight\s*:\s*([^;]+)/) || [])[1];
    const style  = (block.match(/font-style\s*:\s*([^;]+)/) || [])[1];
    if (family) faces.push({ family: family.trim(), weight: (weight||'400').trim(), style: (style||'normal').trim() });
  }
  return faces;
}

function extractGoogleFontUrl(html) {
  // Matches both <link href="...fonts.googleapis.com..."> and @import url(...)
  const patterns = [
    /href=["']([^"']*fonts\.googleapis\.com[^"']+)["']/gi,
    /@import\s+url\(['"]?([^'")\s]*fonts\.googleapis\.com[^'")\s]+)['"]?\)/gi,
  ];
  const found = [];
  for (const pat of patterns) {
    let m;
    while ((m = pat.exec(html)) !== null) found.push(m[1]);
  }
  return [...new Set(found)];
}

function parseGoogleFontUrl(fontUrl) {
  // Extract font family names and weights from Google Fonts URL
  const decoded = decodeURIComponent(fontUrl);
  const families = [];
  const familyMatches = decoded.matchAll(/family=([^&]+)/g);
  for (const [, fam] of familyMatches) {
    const [name, axes] = fam.split(':');
    const cleanName = name.replace(/\+/g, ' ');
    const weights = [];
    if (axes) {
      const wMatches = axes.matchAll(/(?:wght@|,)([0-9,;]+)/g);
      for (const [, w] of wMatches) {
        w.split(/[,;]/).forEach(v => { const n = parseInt(v); if (n >= 100 && n <= 900) weights.push(n); });
      }
    }
    families.push({ name: cleanName, weights: [...new Set(weights)].sort() });
  }
  return families;
}

// ─── HTML CLASS SCANNERS ─────────────────────────────────────────────────────

function scanTailwindClasses(html) {
  const classes = (html.match(/class=["']([^"']+)["']/g) || []).flatMap(m => {
    const inner = m.replace(/^class=["']|["']$/g, '');
    return inner.split(/\s+/);
  });

  const classSet = new Set(classes);
  const found = { fontWeights: {}, fontSizes: {}, tracking: {}, leading: {}, colors: new Set(), bgColors: new Set() };

  // Font weights
  for (const [cls, val] of Object.entries(TAILWIND_FONT_WEIGHTS)) {
    if (classSet.has(cls)) found.fontWeights[cls] = val;
  }

  // Font sizes (including responsive variants sm:text-*, lg:text-*)
  for (const cls of classSet) {
    const sizeMatch = cls.match(/(?:sm:|md:|lg:|xl:)?text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|8xl|9xl)$/);
    if (sizeMatch) found.fontSizes[cls] = TAILWIND_FONT_SIZES['text-' + sizeMatch[1]];

    const trackMatch = cls.match(/tracking-(tighter|tight|normal|wide|wider|widest)$/);
    if (trackMatch) found.tracking[cls] = TAILWIND_TRACKING['tracking-' + trackMatch[1]];

    const leadMatch = cls.match(/leading-(none|tight|snug|normal|relaxed|loose)$/);
    if (leadMatch) found.leading[cls] = TAILWIND_LEADING['leading-' + leadMatch[1]];

    // Arbitrary hex colors: text-[#abc123] or text-[#aabbcc]
    const hexTextMatch = cls.match(/text-\[#([0-9a-fA-F]{3,8})\]/);
    if (hexTextMatch) found.colors.add('#' + hexTextMatch[1]);

    const hexBgMatch = cls.match(/bg-\[#([0-9a-fA-F]{3,8})\]/);
    if (hexBgMatch) found.bgColors.add('#' + hexBgMatch[1]);

    // HSL arbitrary: bg-[hsl(...)] — capture as-is
    const hslMatch = cls.match(/(bg|text)-\[(hsl[^)]+\))\]/);
    if (hslMatch) found.bgColors.add(hslMatch[2]);
  }

  return {
    fontWeights: found.fontWeights,
    fontSizes: found.fontSizes,
    tracking: found.tracking,
    leading: found.leading,
    hexColors: [...found.colors],
    hexBgColors: [...found.bgColors],
  };
}

function extractInlineHexColors(cssText) {
  // Extract all hex colors from CSS
  const colors = new Set();
  const matches = cssText.matchAll(/#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/g);
  for (const [hex] of matches) colors.add(hex.toUpperCase());
  return [...colors];
}

function extractHslColors(cssText) {
  const colors = [];
  const matches = cssText.matchAll(/hsl\([^)]+\)/g);
  for (const [hsl] of matches) colors.push(hsl);
  return [...new Set(colors)];
}

// ─── TYPOGRAPHY INFERENCE ────────────────────────────────────────────────────

function inferTypography(tailwindData, cssVars, fontFaces, googleFonts, allCss) {
  // Detect body font
  let bodyFont = 'System Sans';
  let headingFont = 'System Sans';

  // Check CSS vars for font-family declarations
  for (const [key, val] of Object.entries(cssVars)) {
    if (/font/.test(key) && !/size|weight/.test(key)) {
      const clean = val.replace(/['"]/g, '');
      if (/body|base/.test(key)) bodyFont = clean;
      if (/heading|display|serif/.test(key)) headingFont = clean;
    }
  }

  // Check font-family in :root
  const rootFontMatch = allCss.match(/:root\s*\{[^}]*font-family\s*:\s*([^;]+)/s);
  if (rootFontMatch) {
    const primary = rootFontMatch[1].trim().split(',')[0].replace(/['"]/g, '').trim();
    if (primary && primary !== 'inherit') bodyFont = primary;
  }

  // Body/html font-family rule
  const bodyFontMatch = allCss.match(/(?:^|\})\s*(?:body|html)\s*\{[^}]*font-family\s*:\s*([^;]+)/ms);
  if (bodyFontMatch) {
    const primary = bodyFontMatch[1].trim().split(',')[0].replace(/['"]/g, '').trim();
    if (primary && primary !== 'inherit') bodyFont = primary;
  }

  // Google Fonts take precedence if loaded
  if (googleFonts.length > 0) {
    bodyFont = googleFonts[0].name;
    if (googleFonts.length > 1) headingFont = googleFonts[1].name;
  }

  // Font weights from Tailwind classes
  const weights = Object.values(tailwindData.fontWeights);
  const heroWeight = weights.includes(900) ? 900 : (weights.includes(800) ? 800 : 700);
  const bodyWeight = weights.includes(400) ? 400 : 400;

  // Hero size (largest text class used)
  const sizes = Object.values(tailwindData.fontSizes).map(s => parseFloat(s));
  const heroSize = sizes.length ? Math.max(...sizes) + 'rem' : '4.5rem';

  // Letter-spacing
  const trackings = Object.values(tailwindData.tracking);
  const heroTracking = trackings.includes('-0.025em') ? '-0.025em' : (trackings[0] || '0');

  // Line-height
  const leadings = Object.values(tailwindData.leading);
  const bodyLineHeight = leadings.includes('1.625') ? '1.625' : (leadings[0] || '1.5');

  return {
    bodyFont,
    headingFont,
    heroFontWeight: heroWeight,
    bodyFontWeight: bodyWeight,
    heroFontSize: heroSize,
    heroLetterSpacing: heroTracking,
    bodyLineHeight,
    googleFonts,
    fontFaces,
  };
}

function inferColors(cssVars, inlineHex, tailwindHex, tailwindBg, allCss) {
  const palette = {};

  // From CSS custom properties
  for (const [key, val] of Object.entries(cssVars)) {
    if (val.startsWith('#')) {
      palette[key] = val;
    } else if (/^\d+\s+\d+%\s+\d+%/.test(val)) {
      // HSL shorthand (Tailwind: "30 78% 57%") → convert to hsl()
      palette[key] = `hsl(${val})`;
    } else if (val.startsWith('hsl')) {
      palette[key] = val;
    }
  }

  // Cluster colors by hue to find primary/accent
  const allHex = [...new Set([...inlineHex, ...tailwindHex, ...tailwindBg])];

  // Score colors: orange-ish colors are likely accent
  const orangeColors = allHex.filter(h => {
    const r = parseInt(h.slice(1,3), 16);
    const g = parseInt(h.slice(3,5), 16);
    const b = parseInt(h.slice(5,7), 16);
    return r > 180 && g > 80 && g < 180 && b < 100; // orange heuristic
  });

  const darkColors = allHex.filter(h => {
    const r = parseInt(h.slice(1,3), 16);
    const g = parseInt(h.slice(3,5), 16);
    const b = parseInt(h.slice(5,7), 16);
    const lum = 0.2126*r + 0.7152*g + 0.0722*b;
    return lum < 40 && h.length === 7;
  });

  const lightColors = allHex.filter(h => {
    const r = parseInt(h.slice(1,3), 16);
    const g = parseInt(h.slice(3,5), 16);
    const b = parseInt(h.slice(5,7), 16);
    const lum = 0.2126*r + 0.7152*g + 0.0722*b;
    return lum > 220 && h.length === 7;
  });

  const textColors = allHex.filter(h => {
    const r = parseInt(h.slice(1,3), 16);
    const g = parseInt(h.slice(3,5), 16);
    const b = parseInt(h.slice(5,7), 16);
    const lum = 0.2126*r + 0.7152*g + 0.0722*b;
    return lum > 40 && lum < 100 && h.length === 7;
  });

  // Build final palette
  const result = {
    primary:     orangeColors[0] || palette['--primary'] || '#e8983f',
    primaryDark: orangeColors[1] || '#d4882f',
    dark:        darkColors[0]   || '#111827',
    darker:      darkColors[1]   || '#030712',
    text:        textColors[0]   || '#1B2A5C',
    textMuted:   '#6b7280',
    surface:     lightColors[0]  || '#F7F7FC',
    background:  '#ffffff',
  };

  // Override with explicit CSS vars if they exist
  if (palette['--primary'])    result.primary     = palette['--primary'];
  if (palette['--background']) result.background  = palette['--background'];
  if (palette['--foreground']) result.text        = palette['--foreground'];

  return { cssVars: palette, palette: result, allColors: allHex };
}

// ─── PUPPETEER (OPTIONAL ENHANCEMENT) ────────────────────────────────────────

async function tryPuppeteerScan(targetUrl, tokens) {
  try {
    const puppeteer = require('puppeteer');
    console.log('  [puppeteer] launching headless browser for computed styles...');
    const browser = await puppeteer.launch({ args: ['--no-sandbox','--disable-setuid-sandbox'] });
    const page    = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    await page.goto(targetUrl, { waitUntil: 'networkidle2', timeout: 30000 });

    const computedStyles = await page.evaluate(() => {
      const selectors = {
        'h1':    document.querySelector('h1'),
        'h2':    document.querySelector('h2'),
        'h3':    document.querySelector('h3'),
        'body':  document.body,
        'nav a': document.querySelector('nav a'),
        'p':     document.querySelector('p'),
      };
      const props = ['font-family','font-weight','font-size','line-height','letter-spacing','color'];
      const result = {};
      for (const [sel, el] of Object.entries(selectors)) {
        if (!el) continue;
        const cs = window.getComputedStyle(el);
        result[sel] = {};
        for (const prop of props) result[sel][prop] = cs.getPropertyValue(prop);
      }
      return result;
    });

    await browser.close();
    tokens.computedStyles = computedStyles;
    tokens._puppeteerUsed = true;

    // Override typography from computed styles (Rule 1: Computed styles trump source)
    if (computedStyles.h1) {
      const h1 = computedStyles.h1;
      tokens.typography.headingFont     = h1['font-family'].split(',')[0].replace(/["']/g,'').trim();
      tokens.typography.heroFontWeight  = parseInt(h1['font-weight']) || 900;
      tokens.typography.heroLetterSpacing = h1['letter-spacing'];
    }
    if (computedStyles.body) {
      const body = computedStyles.body;
      tokens.typography.bodyFont       = body['font-family'].split(',')[0].replace(/["']/g,'').trim();
      tokens.typography.bodyFontWeight = parseInt(body['font-weight']) || 400;
      tokens.typography.bodyLineHeight = body['line-height'];
    }
    console.log('  [puppeteer] computed styles captured.');
  } catch (e) {
    if (e.code === 'MODULE_NOT_FOUND') {
      console.log('  [puppeteer] not installed — using CSS fetch method (still accurate for Base44 apps)');
    } else {
      console.log('  [puppeteer] error:', e.message);
    }
  }
  return tokens;
}

// ─── MAIN SCANNER ─────────────────────────────────────────────────────────────

async function scan(targetUrl, outputPath) {
  console.log('\nB442WP Style Scanner');
  console.log('====================');
  console.log('Target:', targetUrl);
  console.log('');

  // 1. Fetch HTML
  console.log('[1/6] Fetching HTML...');
  let html, finalUrl;
  try {
    const res = await fetchUrl(targetUrl);
    html = res.body;
    finalUrl = res.finalUrl;
    console.log('      Status:', res.status, '| Size:', Math.round(html.length/1024) + 'kb');
  } catch (e) {
    console.error('Failed to fetch:', e.message);
    process.exit(1);
  }

  // 2. Extract CSS URLs from <link> tags
  console.log('[2/6] Finding CSS files...');
  const cssLinks = [];
  const linkMatches = html.matchAll(/<link[^>]+rel=["']stylesheet["'][^>]*href=["']([^"']+)["']/gi);
  for (const [, href] of linkMatches) cssLinks.push(resolveUrl(finalUrl, href));

  // Also check reversed attribute order
  const linkMatches2 = html.matchAll(/<link[^>]+href=["']([^"']+)["'][^>]*rel=["']stylesheet["']/gi);
  for (const [, href] of linkMatches2) cssLinks.push(resolveUrl(finalUrl, href));

  console.log('      Found', [...new Set(cssLinks)].length, 'CSS file(s)');

  // 3. Download & combine all CSS
  console.log('[3/6] Downloading CSS...');
  let allCss = '';

  // Extract <style> tag contents
  const styleTags = html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi);
  for (const [, content] of styleTags) allCss += '\n' + content;

  // Download linked CSS files
  for (const cssUrl of [...new Set(cssLinks)]) {
    try {
      const res = await fetchUrl(cssUrl);
      allCss += '\n' + res.body;
      console.log('      ✓', cssUrl.split('/').pop().slice(0,60));
    } catch (e) {
      console.log('      ✗ Failed:', cssUrl.split('/').pop().slice(0,60));
    }
  }

  // 4. Parse CSS
  console.log('[4/6] Parsing CSS tokens...');
  const cssVars   = extractCssVars(allCss);
  const fontFaces = extractFontFaces(allCss);
  const inlineHex = extractInlineHexColors(allCss);
  const hslColors = extractHslColors(allCss);

  // 5. Scan Tailwind classes in HTML
  console.log('[5/6] Scanning Tailwind classes...');
  const tailwindData = scanTailwindClasses(html);

  // 6. Google Fonts
  console.log('[6/6] Detecting Google Fonts...');
  const gfUrls    = extractGoogleFontUrl(html + allCss);
  const googleFonts = gfUrls.flatMap(u => parseGoogleFontUrl(u));
  if (googleFonts.length) {
    console.log('      Found:', googleFonts.map(f => f.name).join(', '));
  } else {
    console.log('      No Google Fonts detected — system fonts likely');
  }

  // Build token object
  let tokens = {
    _scannedUrl: targetUrl,
    _scannedAt:  new Date().toISOString(),
    _puppeteerUsed: false,
    typography: inferTypography(tailwindData, cssVars, fontFaces, googleFonts, allCss),
    colors: inferColors(cssVars, inlineHex, tailwindData.hexColors, tailwindData.hexBgColors, allCss),
    tailwind: tailwindData,
    cssVarsRaw: cssVars,
    googleFontUrls: gfUrls,
  };

  // Optional: enhance with Puppeteer for true computed styles (Rule 1)
  tokens = await tryPuppeteerScan(targetUrl, tokens);

  // Save output
  const out = outputPath || './scan-output.json';
  fs.writeFileSync(out, JSON.stringify(tokens, null, 2));

  console.log('\n── RESULTS ──────────────────────────────────');
  console.log('Body font:     ', tokens.typography.bodyFont);
  console.log('Heading font:  ', tokens.typography.headingFont);
  console.log('Hero weight:   ', tokens.typography.heroFontWeight);
  console.log('Body line-height:', tokens.typography.bodyLineHeight);
  console.log('Primary color: ', tokens.colors.palette.primary);
  console.log('Dark bg:       ', tokens.colors.palette.dark);
  console.log('Text color:    ', tokens.colors.palette.text);
  if (tokens._puppeteerUsed) console.log('\n✓ Computed styles used (Puppeteer)');
  else console.log('\n✓ CSS-extracted styles used (fetch-based)');
  console.log('\nTokens saved to:', out);
  console.log('─────────────────────────────────────────────\n');

  return tokens;
}

// ─── CLI ENTRY ────────────────────────────────────────────────────────────────

const args = process.argv.slice(2);
if (!args[0]) {
  console.error('Usage: node scan-styles.js <url> [output.json]');
  process.exit(1);
}

scan(args[0], args[1]).catch(e => { console.error('Scanner error:', e); process.exit(1); });
