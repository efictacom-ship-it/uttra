// design.mjs - the design agent's own render check and stock-photo helper
// for Uttra Design files (see AGENTS.md "Marketing designs"). Lives at
// /workspace/scripts/design.mjs in a project container and next to the kit
// in a standalone design workspace. Renders one HTML composition at its
// stage size with the pinned Playwright Chromium, prints the PNG path plus
// a DIAGNOSTICS line when the page has the problems the platform's central
// check would flag (text off the canvas, clipped, overlapping, low
// contrast, Arabic line-height, failed fonts, broken images). The platform
// renders the final files itself when the [[uttra:design]] marker is
// emitted; this is for LOOKING before that - read the PNG it writes.
//
//   node design.mjs render <file.html> [--out <png>] [--width W --height H] [--scale 2]
//        default --out: next to the html, same name, .png
//   node design.mjs check  <file.html>          # diagnostics only, as JSON
//   node design.mjs photo --query "<3-8 english words>" [--orientation portrait|landscape|squarish] [--index 0] --out assets/hero.jpg
//        fetches one real photograph through the platform (needs the run's
//        UTTRA_DESIGN_API / UTTRA_DESIGN_GRANT environment); writes the image
//        and <out>.json with the credit. Exit 3 when photos are unavailable.
//   node design.mjs compose spec.json --out designs/<size>.html [--size <preset>]
//        composes a finished page from a small spec (see compose() below).
//   node design.mjs photo --url "https://..." --out assets/hero.jpg
//        fetches the picture the user linked (public http(s) image, <= 15 MB).
//   node design.mjs photo --query "..." --candidates 4 --out assets/hero.jpg
//        fetches up to 4 results and renders ONE contact sheet
//        (assets/candidates/sheet.png) to look at; then
//   node design.mjs photo --pick 2 --out assets/hero.jpg
//        keeps candidate 2 as the photo and removes the others.
//   node design.mjs site <https://...> [--out assets]
//        reads a website the user linked: its logo (assets/site-logo.*), name,
//        what it is about, colours and share image - the real brand for the design.
//   node design.mjs image <info|grid|cutout|erase|patch|draw|sample|enhance|adjust|mask|recolor|textfind|textreplace|textstyles|textadd|deliver> <in> --out <file>
//        the photo tools (remove background, erase things, clone / draw / sample to complete,
//        enhance / upscale, adjust, recolour, find and replace text, text styles) and
//        `deliver`: the edited picture as a design at its own size.
//
// The kit is linked by the file itself (design-kit.css); fonts come with it.

import { readFile, writeFile, mkdir, rm, rename, appendFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { readdir } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { dirname, relative, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const args = process.argv.slice(2);
const cmd = args[0];
const flag = (name) => { const i = args.indexOf(`--${name}`); return i >= 0 ? args[i + 1] : undefined; };

async function loadChromium() {
  try {
    const mod = createRequire('/opt/uttra-browser/package.json')('playwright');
    return mod.chromium || mod.default?.chromium;
  } catch {}
  try { return (await import('playwright')).chromium; } catch {}
  return null;
}

if (cmd === 'photo') {
  await photo();
  process.exit(0);
}
if (cmd === 'site') {
  await site();
  process.exit(0);
}
if (cmd === 'image') {
  await image();
  process.exit(0);
}
if (cmd === 'tool') { await tool(); process.exit(0); }
if (cmd === 'look') { await look(); process.exit(0); }
if (cmd === 'compose') {
  await compose();
  process.exit(0);
}

const file = args[1];
if (!cmd || !file || !['render', 'check'].includes(cmd)) {
  process.stderr.write('usage: node design.mjs render|check <file.html> [--out <png>] [--width W --height H] [--scale 2]\n       node design.mjs photo --query "<english words>" [--orientation portrait|landscape|squarish] [--index 0] --out assets/<name>.jpg\n       node design.mjs photo --url "https://..." --out assets/<name>.jpg\n');
  process.exit(2);
}

const path = resolve(file);
if (!existsSync(path)) { process.stderr.write(`not found: ${path}\n`); process.exit(2); }
const html = await readFile(path, 'utf8');

// Stage size: --width/--height flags, else the stage's --w/--h, else the
// s-<preset> class, else 1080x1080.
const presets = { 'ig-square': [1080, 1080], 'ig-portrait': [1080, 1350], 'ig-story': [1080, 1920], 'whatsapp-status': [1080, 1920], 'x-post': [1600, 900], linkedin: [1200, 627], 'fb-cover': [1640, 624], 'youtube-thumb': [1280, 720], og: [1200, 630], 'x-header': [1500, 500], 'linkedin-cover': [1584, 396], poster: [1240, 1754] };
let width = parseInt(flag('width') || '', 10), height = parseInt(flag('height') || '', 10);
if (!width || !height) {
  const m = /--w\s*:\s*(\d+)px[^"']*--h\s*:\s*(\d+)px/.exec(html) || /--h\s*:\s*(\d+)px[^"']*--w\s*:\s*(\d+)px/.exec(html);
  if (m) [width, height] = html.indexOf('--w') < html.indexOf('--h') ? [+m[1], +m[2]] : [+m[2], +m[1]];
}
if (!width || !height) {
  const c = /class="[^"]*\bs-([a-z0-9-]+)/.exec(html);
  if (c && presets[c[1]]) [width, height] = presets[c[1]];
}
if (!width || !height) [width, height] = [1080, 1080];
const scale = Math.min(3, Math.max(1, parseInt(flag('scale') || '2', 10)));

const chromium = await loadChromium();
if (!chromium) { process.stderr.write('playwright is not installed - run: cd /workspace && npm i -D playwright && npx playwright install chromium\n'); process.exit(4); }

const checkScript = `(() => {
  const vw = innerWidth, vh = innerHeight, issues = [];
  const snippet = (el) => (el.innerText || '').trim().replace(/\\s+/g, ' ').slice(0, 48);
  const parse = (s) => { const m = /rgba?\\(([^)]+)\\)/.exec(s || ''); if (!m) return null; const p = m[1].split(',').map(parseFloat); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const bgOf = (el) => { let e = el; while (e && e !== document.documentElement) { const cs = getComputedStyle(e); if (cs.backgroundImage !== 'none') return null; const c = parse(cs.backgroundColor); if (c && c.a >= 0.92) return c; if (c && c.a > 0.2) return null; e = e.parentElement; } return { r: 255, g: 255, b: 255, a: 1 }; };
  const margin = Math.round(Math.min(vw, vh) * 0.035);
  let inked = 0;
  for (const el of document.body.querySelectorAll('*')) {
    if (typeof el.className === 'string' && /(^|\\s)marquee(\\s|$)/.test(el.className)) continue;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) continue;
    if (el.closest && el.closest('svg')) continue; // a letter cut into a mark: contrast is against the shape by construction
    if (![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) continue;
    inked += Math.max(0, Math.min(r.right, vw) - Math.max(r.left, 0)) * Math.max(0, Math.min(r.bottom, vh) - Math.max(r.top, 0));
    if (r.left < -1 || r.top < -1 || r.right > vw + 1 || r.bottom > vh + 1) issues.push({ type: 'offcanvas', text: snippet(el) });
    else if (r.left < margin || r.top < margin || vw - r.right < margin || vh - r.bottom < margin) issues.push({ type: 'margin', text: snippet(el) });
    const cx = (r.left + r.right) / 2, cy = (r.top + r.bottom) / 2;
    if (cx >= 0 && cx <= vw && cy >= 0 && cy <= vh) {
      const top = document.elementFromPoint(cx, cy);
      if (top && top !== el && !el.contains(top) && !top.contains(el)) {
        const tcs = getComputedStyle(top), tbg = parse(tcs.backgroundColor);
        if (!(top.dataset && top.dataset.art === 'overlap') && ((tbg && tbg.a > 0.5) || tcs.backgroundImage !== 'none' || /^(IMG|VIDEO|CANVAS|SVG)$/i.test(top.tagName))) issues.push({ type: 'overlap', text: snippet(el), detail: 'covered by another element (' + top.tagName.toLowerCase() + (top.className && typeof top.className === 'string' ? '.' + top.className.split(' ')[0] : '') + ')' });
      }
    }
    if (el.scrollWidth > el.clientWidth + 2 && /hidden|clip/.test(cs.overflowX)) issues.push({ type: 'overflow', text: snippet(el) });
    if (el.scrollHeight > el.clientHeight + 2 && /hidden|clip/.test(cs.overflowY)) issues.push({ type: 'overflow', text: snippet(el) });
    const fg = parse(cs.color), bg = bgOf(el), size = parseFloat(cs.fontSize) || 16, weight = parseInt(cs.fontWeight, 10) || 400;
    const large = size >= 32 || (size >= 24 && weight >= 700);
    if (fg && bg && fg.a > 0.5) { const rr = ratio(fg, bg); if (rr < (large ? 3 : 4.5)) issues.push({ type: 'contrast', text: snippet(el), detail: rr.toFixed(2) + ':1' }); }
    const lh = parseFloat(cs.lineHeight);
    if (/[\\u0600-\\u06FF]/.test(el.textContent) && Number.isFinite(lh) && lh / size < 1.25 && size < 60) issues.push({ type: 'lineheight', text: snippet(el) });
    if (cs.fontStyle === 'italic' && /[\\u0600-\\u06FF]/.test(el.textContent)) issues.push({ type: 'fakeitalic', text: snippet(el) });
  }
  const textEls = [...document.body.querySelectorAll('*')].filter((el) => !(el.closest && el.closest('svg')) && [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim()) && el.getBoundingClientRect().width > 0 && !/(^|\\s)marquee(\\s|$)/.test(typeof el.className === 'string' ? el.className : ''));
  const leaves = textEls.filter((el) => !textEls.some((o) => o !== el && el.contains(o)));
  for (let i = 0; i < leaves.length; i++) {
    const a = leaves[i].getBoundingClientRect();
    for (let j = i + 1; j < leaves.length; j++) {
      const b = leaves[j].getBoundingClientRect();
      const ix = Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left)), iy = Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top));
      const small = Math.min(a.width * a.height, b.width * b.height);
      if (small > 0 && (ix * iy) / small > 0.25) { issues.push({ type: 'overlap', text: snippet(leaves[i]), detail: 'text collides with "' + snippet(leaves[j]) + '"' }); break; }
    }
  }
  // Icons / pictures sitting on text.
  for (const el of document.body.querySelectorAll('svg, img')) {
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0 || r.width * r.height > vw * vh * 0.2) continue;
    for (const t of leaves) {
      if (t.contains(el) || el.contains(t)) continue;
      const b = t.getBoundingClientRect();
      const ix = Math.max(0, Math.min(r.right, b.right) - Math.max(r.left, b.left)), iy = Math.max(0, Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top));
      // a composed cut-out may run over the headline's edge on purpose (data-art="overlap"): up to 45% of the text, never more
      if (ix * iy > (el.dataset && el.dataset.art === 'overlap' ? 0.45 : 0.2) * Math.min(r.width * r.height, b.width * b.height)) { issues.push({ type: 'overlap', text: snippet(t), detail: el.tagName.toLowerCase() + ' sits on this text' }); break; }
    }
  }
  // Panels: elements with their own visible surface (a card, ticket, band,
  // photo frame) that are not the whole canvas. A text box that crosses a
  // panel's edge (half on it, half off it) is the most common "looks
  // broken" the eye catches - flag it.
  const panelRect = (p) => {
    const r = p.getBoundingClientRect();
    let box = { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height };
    for (let el = p, depth = 0; el && depth < 3; el = el.parentElement, depth++) {
      const cls = typeof el.className === 'string' ? el.className : '';
      if (/mask-fade-end/.test(cls)) box.right -= r.width * 0.32;
      else if (/mask-fade-start/.test(cls)) box.left += r.width * 0.32;
      else if (/mask-fade-b/.test(cls)) box.bottom -= r.height * 0.38;
      else if (/mask-fade-t/.test(cls)) box.top += r.height * 0.38;
      else continue;
      break;
    }
    box.width = box.right - box.left; box.height = box.bottom - box.top;
    return box;
  };
  const panels = [...document.body.querySelectorAll('*')].filter((el) => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0 || cs.position === 'fixed') return false;
    // Decorative layers (gradients, blobs, scrims, grain, rings) are not
    // panels: text is meant to sit across them, so their edges never count.
    if (typeof el.className === 'string' && /(^|\\s)(layer|blur-blob|blur-blob-2|grain|vignette|ring|dot|g-[a-z-]+|mask-[a-z-]+|bg|bg-2|text-gradient|highlight)(\\s|$)/.test(el.className)) return false;
    if (/blur\\(/.test(cs.filter) || parseFloat(cs.opacity) < 0.85) return false;
    if (el.closest && el.closest('.g-decor, .layer, .marquee')) return false; // inside a decorative wrapper
    const r = el.getBoundingClientRect();
    if (r.width < vw * 0.12 || r.height < vh * 0.05) return false;
    if (r.width * r.height > vw * vh * 0.85) return false;
    const bg = parse(cs.backgroundColor);
    const surfaced = (bg && bg.a > 0.5) || cs.backgroundImage !== 'none' || /^(IMG|VIDEO|CANVAS)$/.test(el.tagName) || (parseFloat(cs.borderTopWidth) > 0 && cs.borderTopStyle !== 'none');
    return surfaced;
  });
  for (const t of leaves) {
    const b = t.getBoundingClientRect();
    for (const p of panels) {
      if (p === t || p.contains(t) || t.contains(p)) continue;
      const r = panelRect(p);
      const ix = Math.max(0, Math.min(r.right, b.right) - Math.max(r.left, b.left)), iy = Math.max(0, Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top));
      const inter = ix * iy, area = b.width * b.height;
      if (area > 0 && inter > 0.08 * area && inter < 0.92 * area) { issues.push({ type: 'straddle', text: snippet(t), detail: 'text crosses the edge of a panel (' + p.tagName.toLowerCase() + (typeof p.className === 'string' && p.className ? '.' + p.className.split(' ')[0] : '') + ')' }); break; }
    }
  }
  // Empty regions: a 3x3 grid; a cell with no text, image, icon or panel
  // is reported so dead areas get a decision, not an accident.
  const inkRects = [];
  for (const el of leaves) inkRects.push(el.getBoundingClientRect());
  for (const el of document.body.querySelectorAll('img, svg, video')) { const r = el.getBoundingClientRect(); if (r.width > 0 && r.width * r.height < vw * vh * 0.85) inkRects.push(r); }
  for (const p of panels) { const r = p.getBoundingClientRect(); const cs = getComputedStyle(p); if (r.width * r.height < vw * vh * 0.5 || cs.backgroundImage !== 'none' || /^(IMG|VIDEO|CANVAS)$/.test(p.tagName)) inkRects.push(r); }
  const names = [['top-start', 'top-center', 'top-end'], ['middle-start', 'middle-center', 'middle-end'], ['bottom-start', 'bottom-center', 'bottom-end']];
  const rtl = getComputedStyle(document.documentElement).direction === 'rtl';
  const emptyCells = [];
  // a photograph covering the canvas (bleed / poster) is the fill itself
  const fullPhoto = [...document.images].some((im) => { const r = im.getBoundingClientRect(); return r.width * r.height >= vw * vh * 0.85; });
  for (let row = 0; row < 3 && !fullPhoto; row++) for (let col = 0; col < 3; col++) {
    const cx0 = (vw / 3) * col, cx1 = (vw / 3) * (col + 1), cy0 = (vh / 3) * row, cy1 = (vh / 3) * (row + 1);
    let covered = 0;
    for (const r of inkRects) covered += Math.max(0, Math.min(r.right, cx1) - Math.max(r.left, cx0)) * Math.max(0, Math.min(r.bottom, cy1) - Math.max(r.top, cy0));
    if (covered < (vw / 3) * (vh / 3) * 0.06) emptyCells.push(names[row][rtl ? 2 - col : col]);
  }
  // Crowding: a text box hugging (or barely overlapping) the edge of a
  // component it is not inside - the "subline touching the button" look.
  const gapMin = Math.max(8, vh * 0.012);
  for (const t of leaves) {
    const b = t.getBoundingClientRect();
    for (const p of panels) {
      if (p === t || p.contains(t) || t.contains(p)) continue;
      const r = panelRect(p);
      const xo = Math.min(r.right, b.right) - Math.max(r.left, b.left);
      const yo = Math.min(r.bottom, b.bottom) - Math.max(r.top, b.top);
      const vgap = Math.max(r.top - b.bottom, b.top - r.bottom);
      const hgap = Math.max(r.left - b.right, b.left - r.right);
      const near = (xo > 0 && vgap > -gapMin && vgap < gapMin) || (yo > 0 && hgap > -gapMin && hgap < gapMin);
      if (near) {
        const cls = typeof p.className === 'string' && p.className.trim() ? '.' + p.className.trim().split(/\\s+/)[0] : '';
        issues.push({ type: 'crowd', text: snippet(t), detail: 'touches the edge of ' + p.tagName.toLowerCase() + cls + ' - leave at least ' + Math.round(gapMin * 2) + 'px of air between a text block and the next element' });
        break;
      }
    }
  }
  // One call to action: a second button (or a button-wide pill) is noise.
  const ctaEls = [...document.body.querySelectorAll('.btn, button, a.btn')].filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (el.textContent || '').trim(); });
  const widePills = [...document.body.querySelectorAll('.pill')].filter((el) => { const r = el.getBoundingClientRect(); return r.width > vw * 0.3 && (el.textContent || '').trim() && !ctaEls.includes(el); });
  if (ctaEls.length + widePills.length >= 2) issues.push({ type: 'cta', text: snippet(ctaEls[0] || widePills[0]), detail: (ctaEls.length + widePills.length) + ' button-like elements - keep ONE call to action; a second action becomes a plain text line (phone, handle), never another button or a wide pill' });
  // The headline (largest type) breaks into 2 lines, 3 at most - never a
  // word per line.
  let head = null, headSize = 0, headlineInfo = null;
  const ownWords = (t) => [...t.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join(' ').trim().split(/\\s+/).filter(Boolean).length;
  const bigText = textEls.filter((t) => !/marquee/.test(typeof t.className === 'string' ? t.className : ''));
  for (const t of bigText) { const s = parseFloat(getComputedStyle(t).fontSize) || 0; if (s > headSize) { headSize = s; head = t; } }
  if (head && headSize > 0) {
    // Every element with its own text set in (nearly) the largest size
    // belongs to the headline (an h1 with an accent span, or several
    // stacked elements); its own text nodes are its words.
    const parts = bigText.filter((t) => (parseFloat(getComputedStyle(t).fontSize) || 0) >= headSize * 0.85);
    let lines = 0, words = 0, prevBottom = -1e9;
    const rows = parts.filter((t) => !parts.some((o) => o !== t && o.contains(t))).map((t) => ({ r: t.getBoundingClientRect(), lh: parseFloat(getComputedStyle(t).lineHeight) || headSize * 1.2, w: (t.innerText || t.textContent || '').trim().split(/\\s+/).filter(Boolean).length })).sort((a, b) => a.r.top - b.r.top);
    for (const p of rows) {
      words += p.w;
      const own = Math.max(1, Math.round(p.r.height / p.lh));
      if (p.r.top >= prevBottom - 4) lines += own; else lines += Math.max(0, own - 1);
      prevBottom = Math.max(prevBottom, p.r.bottom);
    }
    headlineInfo = { size: headSize, parts: parts.length, lines, words, rows: rows.map((p) => [Math.round(p.r.height), Math.round(p.lh), p.w]) };
    if (lines >= 4 || (lines >= 3 && words / lines < 1.5)) issues.push({ type: 'headline-lines', text: snippet(head), detail: lines + ' lines for ' + words + ' words - a headline breaks into 2 lines (3 at most) at natural phrases: widen its column (max-width) or shorten it, never one word per line' });
  }
  // A shaped photo (arch, circle, blob...) cut by the canvas top/bottom
  // reads as a mistake; a bleed is a full-width or one-side decision.
  for (const el of document.body.querySelectorAll('[class*="clip-"]')) {
    if (typeof el.className !== 'string' || !/clip-(arch|circle|blob|hex|ticket)/.test(el.className)) continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.width > vw * 0.9) continue;
    const cut = (r.top < -2 ? 'top ' : '') + (r.bottom > vh + 2 ? 'bottom' : '');
    if (cut.trim()) issues.push({ type: 'photo-cut', text: el.className.trim().split(/\\s+/).slice(0, 3).join(' '), detail: 'the shaped photo runs past the canvas ' + cut.trim() + ' edge so its shape is cut off - fit the whole shape inside the canvas with air (faces in its upper third, headroom above the hair), or bleed it on one SIDE on purpose' });
  }
  const fontsFailed = []; try { document.fonts.forEach((f) => { if (f.status === 'error') fontsFailed.push(f.family); }); } catch {}
  const brokenImages = [...document.images].filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.getAttribute('src'));
  const textCoverage = Math.round((inked / (vw * vh)) * 100) / 100;
  return { headline: headlineInfo, issues: issues.slice(0, 30), fontsFailed: [...new Set(fontsFailed)], brokenImages, textCoverage, emptyCells, composed: !!document.querySelector('.stage[data-composed]') };
})()`;

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'] });
try {
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: cmd === 'check' ? 1 : scale, reducedMotion: 'reduce' });
  const page = await context.newPage();
  await page.goto(pathToFileURL(path).href, { waitUntil: 'load', timeout: 45000 });
  await page.evaluate(() => (document.fonts ? document.fonts.ready : true)).catch(() => {});
  await page.waitForTimeout(600);
  const diag = await page.evaluate(checkScript).catch((e) => ({ error: String(e && e.message || e) }));
  if (cmd === 'check') {
    process.stdout.write(JSON.stringify({ width, height, ...diag }) + '\n');
  } else {
    const out = resolve(flag('out') || path.replace(/\.html?$/i, '') + '.png');
    await mkdir(dirname(out), { recursive: true });
    await page.screenshot({ path: out, clip: { x: 0, y: 0, width, height }, animations: 'disabled' });
    process.stdout.write(out + '\n');
    const problems = (diag.issues || []).filter((i) => i.type !== 'margin');
    if (diag.error) process.stderr.write('CHECK ERROR (platform bug, not the design): ' + diag.error + '\n');
    // A photo was fetched into assets/ but the file shows none (or hides it):
    // the customer asked for real pictures, so this is a real defect.
    try {
      const assetsDir = resolve(dirname(path), '..', 'assets');
      // (the site reader's logo / screenshot / share image are not a fetched photograph)
      const fetched = existsSync(assetsDir) ? (await readdir(assetsDir)).filter((n) => /\.(jpe?g|png|webp)$/i.test(n) && !/^site-(logo|shot|og)\./i.test(n)) : [];
      if (fetched.length) {
        const usage = await page.evaluate(() => {
          // logos and small pictures are not the photo
          const imgs = [...document.querySelectorAll('img')].filter((i) => /assets\//.test(i.getAttribute('src') || '') && !/(^|\s)(logo|logo-lg)(\s|$)/.test(typeof i.className === 'string' ? i.className : '') && !/site-logo\./.test(i.getAttribute('src') || ''));
          const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
          let visible = 0;
          for (const i of imgs) {
            const r = i.getBoundingClientRect();
            const cs = getComputedStyle(i);
            let hidden = cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.6;
            let el = i;
            while (el && el !== document.body && !hidden) {
              const s = getComputedStyle(el);
              if (/blur\(/.test(s.filter) && /blur\((\d+(?:\.\d+)?)px\)/.test(s.filter) && parseFloat(RegExp.$1) > 4) hidden = true;
              if (parseFloat(s.opacity) < 0.6) hidden = true;
              el = el.parentElement;
            }
            if (!hidden) visible += Math.max(0, Math.min(r.right, vw) - Math.max(r.left, 0)) * Math.max(0, Math.min(r.bottom, vh) - Math.max(r.top, 0));
          }
          return { count: imgs.length, share: visible / (vw * vh) };
        });
        if (usage.count === 0 && !process.env.UTTRA_DESIGN_NEEDS_PHOTO) problems.push({ type: 'photo-unused', text: 'a photo was fetched into assets/ but the design shows no <img> from assets/ - place it as a visible hero (data-slot="hero"), at least 30% of the canvas, sharp (no blur), natural colours' });
        else if (usage.share < 0.22) problems.push({ type: 'photo-hidden', text: 'the fetched photo covers only ' + Math.round(usage.share * 100) + '% of the canvas or is blurred/faded - make it a visible hero: sharp, at least 30% of the canvas, faces recognisable' });
        diag.photo = usage;
      }
    } catch (e) { /* the check never blocks a render */ }
    // The request asked for photos/people: a design that shows none is a
    // failed design, whatever else looks fine.
    if (process.env.UTTRA_DESIGN_NEEDS_PHOTO && !(diag.photo && diag.photo.count > 0 && diag.photo.share >= 0.3)) {
      const share = diag.photo ? Math.round(diag.photo.share * 100) : 0;
      problems.push({ type: 'photo-missing', text: 'the request asks for a real photo/people but the canvas shows ' + (share ? 'one covering only ' + share + '%' : 'none') + ' - fetch one with the photo helper (see PHOTOS) and place it as the hero (data-slot="hero"), sharp, at least 30% of the canvas (a blueprint with a photo: B1-B4)' });
    }
    // Dead areas: two or more of the nine cells with nothing in them is a
    // layout that did not fill the canvas.
    const logoPage = /data-layout="logo"/.test(html);
    // Hand-written pages are not accepted: every design comes out of the
    // composer (spec.json -> compose), which is where the lockups, layouts
    // and contrast guarantees live.
    if (diag.composed === false) problems.push({ type: 'handmade', text: 'this page was not produced by the composer - hand-written HTML is not accepted: write spec.json and run `node design.mjs compose spec.json --out <file> --size <id>` (alternatives = one spec and one compose per file, never an options board)' });
    if (!logoPage && Array.isArray(diag.emptyCells) && diag.emptyCells.length >= 2) {
      problems.push({ type: 'dead-space', text: 'empty areas: ' + diag.emptyCells.join(', ') + ' - fill the canvas with intent: enlarge the photo or the headline, use the blueprint\'s space-between distribution, add the facts row or the brand mark there; never leave a third of the canvas empty' });
    }
    try {
      const logPath = resolve(dirname(path), '..', '.diagnostics.log');
      await appendFile(logPath, JSON.stringify({ t: new Date().toISOString(), file: path.split('/').slice(-2).join('/'), error: diag.error || undefined, problems: problems.map((p) => p.type + ': ' + (p.text || '') + (p.detail ? ' - ' + p.detail : '')), emptyCells: diag.emptyCells, photo: diag.photo || null }) + '\n');
    } catch {}
    if (problems.length || (diag.fontsFailed && diag.fontsFailed.length) || (diag.brokenImages && diag.brokenImages.length) || diag.error) {
      process.stdout.write('DIAGNOSTICS: ' + JSON.stringify({ width, height, ...diag }) + '\n');
    } else if ((diag.issues || []).length) {
      process.stdout.write('NOTE (safe margin): ' + JSON.stringify(diag.issues.slice(0, 6)) + '\n');
    }
    if ((diag.emptyCells || []).length >= 3) {
      process.stdout.write('NOTE (empty regions, 3x3 grid): ' + diag.emptyCells.join(', ') + ' - decide: deliberate negative space with one anchor, or rebalance.\n');
    }
    process.stdout.write(`Now READ ${out} and judge it: overlaps, clipped text, empty areas, contrast, Arabic shaping.\n`);
  }
  await context.close();
} finally {
  await browser.close();
}

// photoCandidates fetches the top results of one search in parallel and
// renders them as ONE numbered contact sheet, so the agent looks once and
// picks instead of fetching, reading and rejecting photos one by one.
async function photoCandidates(query, n, out) {
  const api = (process.env.UTTRA_DESIGN_API || '').replace(/\/$/, '');
  const grant = process.env.UTTRA_DESIGN_GRANT || '';
  const silent = ' - design without a photograph (colour, shapes, typography) and do NOT mention this to the user.';
  if (!api || !grant) { process.stdout.write('PHOTOS UNAVAILABLE: the photo helper is not wired for this run' + silent + '\n'); process.exit(3); }
  const dir = resolve(dirname(resolve(out)), 'candidates');
  await rm(dir, { recursive: true, force: true });
  await mkdir(dir, { recursive: true });
  const orientation = flag('orientation');
  const one = async (i) => {
    const params = new URLSearchParams({ query, index: String(i) });
    if (orientation) params.set('orientation', orientation);
    const res = await fetch(`${api}/photo?${params}`, { headers: { Authorization: `Bearer ${grant}` }, signal: AbortSignal.timeout(45000) });
    if (!res.ok) { let msg = `${res.status}`; try { msg = (await res.json()).error || msg; } catch {} throw new Error(msg); }
    const bytes = Buffer.from(await res.arrayBuffer());
    const meta = res.headers.get('x-design-photo');
    let p = null; try { p = meta ? JSON.parse(meta) : null; } catch {}
    return { bytes, meta: p };
  };
  const settled = await Promise.allSettled(Array.from({ length: n }, (_, i) => one(i)));
  const got = [];
  const seen = new Set();
  for (const s of settled) {
    if (s.status !== 'fulfilled') continue;
    const key = s.value.meta?.id || s.value.bytes.length;
    if (seen.has(key)) continue;
    seen.add(key);
    got.push(s.value);
  }
  if (!got.length) {
    const why = settled.find((s) => s.status === 'rejected');
    process.stdout.write(`PHOTOS UNAVAILABLE: ${why ? why.reason.message : 'no results'} - try ONE different, simpler English query (2-4 words); if that fails too${silent}\n`);
    process.exit(3);
  }
  const ext = (m) => (m?.contentType || '').includes('png') ? 'png' : (m?.contentType || '').includes('webp') ? 'webp' : 'jpg';
  const files = [];
  for (let i = 0; i < got.length; i++) {
    const name = `${i + 1}.${ext(got[i].meta)}`;
    await writeFile(resolve(dir, name), got[i].bytes);
    if (got[i].meta) await writeFile(resolve(dir, name + '.json'), JSON.stringify(got[i].meta, null, 2));
    files.push(name);
  }
  if (files.length === 1) {
    await pickCandidate(1, out);
    return;
  }
  // The sheet: a numbered grid, each photo shown whole (contain) on a neutral ground.
  const cols = files.length <= 2 ? 2 : files.length <= 4 ? 2 : 3;
  const rows = Math.ceil(files.length / cols);
  const cell = 560, w = cols * cell, h = rows * cell;
  const cells = files.map((name, i) => `<figure><img src="${pathToFileURL(resolve(dir, name)).href}"><figcaption>${i + 1}</figcaption></figure>`).join('');
  const sheet = `<!doctype html><meta charset=utf-8><style>html,body{margin:0;background:#1a1a1a}body{display:grid;grid-template-columns:repeat(${cols},${cell}px);width:${w}px;height:${h}px}figure{margin:0;position:relative;width:${cell}px;height:${cell}px;overflow:hidden;background:#222;outline:2px solid #1a1a1a}img{width:100%;height:100%;object-fit:cover;display:block}figcaption{position:absolute;left:14px;top:14px;width:56px;height:56px;border-radius:50%;background:#fff;color:#000;font:700 30px/56px system-ui,sans-serif;text-align:center}</style>${cells}`;
  const sheetHtml = resolve(dir, 'sheet.html');
  await writeFile(sheetHtml, sheet);
  const sheetPng = resolve(dir, 'sheet.png');
  const chromium = await loadChromium();
  if (!chromium) { process.stdout.write(`${files.length} candidates saved in assets/candidates/ (no browser to make a sheet): READ them, then node design.mjs photo --pick <n> --out ${out}\n`); return; }
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await page.goto(pathToFileURL(sheetHtml).href, { waitUntil: 'load' });
    await page.evaluate(() => Promise.all([...document.images].map((i) => i.decode().catch(() => {}))));
    await page.screenshot({ path: sheetPng, fullPage: false });
  } finally { await browser.close(); }
  await rm(sheetHtml, { force: true });
  process.stdout.write(`CANDIDATES: ${files.length} photos for "${query}" in assets/candidates/sheet.png - READ that ONE image, choose the best number, then: node design.mjs photo --pick <n> --out ${out}\n`);
}

// pickCandidate keeps one candidate as the photo and drops the rest.
async function pickCandidate(n, out) {
  const target = resolve(out);
  const dir = resolve(dirname(target), 'candidates');
  const names = existsSync(dir) ? (await readdir(dir)).filter((f) => new RegExp(`^${n}\\.(jpe?g|png|webp)$`, 'i').test(f)) : [];
  if (!names.length) { process.stdout.write(`no candidate ${n} - run the search with --candidates again\n`); process.exit(2); }
  await mkdir(dirname(target), { recursive: true });
  await rename(resolve(dir, names[0]), target);
  if (existsSync(resolve(dir, names[0] + '.json'))) await rename(resolve(dir, names[0] + '.json'), target + '.json');
  await rm(dir, { recursive: true, force: true });
  process.stdout.write(`${target}\nUse it as <img src="../assets/${target.split('/').pop()}" data-slot="hero" alt=""> from designs/ (or ./assets/... from the workspace root).\n`);
}

// photo fetches one real photograph (a search, or the picture the user
// linked) through the platform's authenticated helper route and writes it
// plus its credit sidecar. Whatever happens here is between the agent and
// the platform: the user is never told about sources, keys or failures.
async function photo() {
  const query = (flag('query') || '').trim();
  const link = (flag('url') || '').trim();
  const out = flag('out');
  const pick = parseInt(flag('pick') || '', 10);
  if (pick && out) { await pickCandidate(pick, out); return; }
  const candidates = Math.min(6, parseInt(flag('candidates') || '', 10) || 0);
  if (candidates > 1 && query && out) { await photoCandidates(query, candidates, out); return; }
  if ((!query && !link) || !out) {
    process.stderr.write('usage: node design.mjs photo --query "<3-8 english words>" [--orientation portrait|landscape|squarish] [--index 0] --out assets/<name>.jpg\n       node design.mjs photo --url "https://..." --out assets/<name>.jpg\n');
    process.exit(2);
  }
  const api = (process.env.UTTRA_DESIGN_API || '').replace(/\/$/, '');
  const grant = process.env.UTTRA_DESIGN_GRANT || '';
  const silent = ' - design without a photograph (colour, shapes, typography) and do NOT mention this to the user.';
  if (!api || !grant) {
    process.stdout.write('PHOTOS UNAVAILABLE: the photo helper is not wired for this run' + silent + '\n');
    process.exit(3);
  }
  const params = link ? new URLSearchParams({ url: link }) : new URLSearchParams({ query });
  const orientation = flag('orientation');
  if (orientation && !link) params.set('orientation', orientation);
  const index = flag('index');
  if (index && !link) params.set('index', index);
  let res;
  try {
    res = await fetch(`${api}/${link ? 'fetch' : 'photo'}?${params}`, { headers: { Authorization: `Bearer ${grant}` }, signal: AbortSignal.timeout(45000) });
  } catch (e) {
    process.stdout.write(`PHOTOS UNAVAILABLE: ${String(e && e.message || e)}${silent}\n`);
    process.exit(3);
  }
  if (!res.ok) {
    let msg = `${res.status}`;
    try { msg = (await res.json()).error || msg; } catch {}
    process.stdout.write(`PHOTOS UNAVAILABLE: ${msg}${link ? silent : ' - try ONE different, simpler English query (2-4 words); if that fails too' + silent}\n`);
    process.exit(3);
  }
  const bytes = Buffer.from(await res.arrayBuffer());
  const target = resolve(out);
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, bytes);
  const meta = res.headers.get('x-design-photo');
  let credit = '';
  if (meta) {
    try {
      const p = JSON.parse(meta);
      await writeFile(target + '.json', JSON.stringify(p, null, 2));
      credit = ` - ${p.attribution || ''}${p.width && p.height ? ` (${p.width}x${p.height})` : ''}`;
    } catch {}
  }
  process.stdout.write(`${target} (${bytes.length} bytes)${credit}\nUse it as <img src="../assets/${target.split('/').pop()}" data-slot="hero" alt=""> from designs/ (or ./assets/... from the workspace root).\n`);
}

// ---------------------------------------------------------------------------
// site: reads a website the user linked - its logo, its name, what it is
// about, its colours and its own share image - so a design carries the real
// brand instead of a guess (owner 2026-09-14: "send it a link, take its logo,
// see what the site is about, make the ad").
//
//   node design.mjs site <https://...> [--out assets]
//
// Writes assets/site.json, assets/site-logo.<ext> (when a logo was found),
// assets/site-og.<ext> (the site's share image, if any) and
// assets/site-shot.jpg (a screenshot for the look), then prints a summary.
// Only public websites: private, loopback and link-local addresses are
// refused - the page itself, its redirects and every sub-resource.
async function site() {
  const url = String(args[1] && !args[1].startsWith('--') ? args[1] : (flag('url') || '')).trim();
  const outDir = flag('out') || 'assets';
  if (!/^https?:\/\/\S+$/i.test(url)) {
    process.stderr.write('usage: node design.mjs site <https://...> [--out assets]\n');
    process.exit(2);
  }
  const dns = await import('node:dns/promises');
  const net = await import('node:net');
  const privateIP = (ip) => {
    if (net.isIPv4(ip)) {
      const [a, b] = ip.split('.').map(Number);
      return a === 0 || a === 10 || a === 127 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || a >= 224;
    }
    const v6 = String(ip).toLowerCase();
    if (v6.startsWith('::ffff:')) return privateIP(v6.slice(7));
    return v6 === '::1' || v6 === '::' || /^f[cd]/.test(v6) || /^fe[89ab]/.test(v6);
  };
  const hostCache = new Map();
  const hostOK = async (host) => {
    host = String(host || '').toLowerCase().replace(/^\[|\]$/g, '');
    if (!host || host === 'localhost' || /\.(local|lxd|internal|localdomain)$/.test(host)) return false;
    if (net.isIP(host)) return !privateIP(host);
    if (hostCache.has(host)) return hostCache.get(host);
    let ok = false;
    try {
      const addrs = await dns.lookup(host, { all: true });
      ok = addrs.length > 0 && addrs.every((a) => !privateIP(a.address));
    } catch { ok = false; }
    hostCache.set(host, ok);
    return ok;
  };
  const unavailable = (why) => {
    process.stdout.write(`SITE UNAVAILABLE: ${why} - design from the request alone (the name, the trade, the colours the user gave) and do NOT mention this to the user.\n`);
    process.exit(3);
  };
  let target;
  try { target = new URL(url); } catch { unavailable('not a valid address'); }
  if (!(await hostOK(target.hostname))) unavailable('not a public website');
  const chromium = await loadChromium();
  if (!chromium) unavailable('no browser in this workspace');
  const ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 UttraDesign/1.0';
  const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, userAgent: ua, locale: 'ar', ignoreHTTPSErrors: true });
  const page = await context.newPage();
  await page.route('**/*', async (route) => {
    let u;
    try { u = new URL(route.request().url()); } catch { return route.abort(); }
    if (!/^https?:$/.test(u.protocol) || !(await hostOK(u.hostname))) return route.abort();
    return route.continue();
  });
  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25000 });
    await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(800);
  } catch (e) {
    await browser.close();
    unavailable(`the site did not load (${String(e && e.message || e).split('\n')[0].slice(0, 90)})`);
  }
  const info = await page.evaluate(() => {
    const abs = (s) => { try { return s ? new URL(s, location.href).href : ''; } catch { return ''; } };
    const meta = (sel) => { const m = document.querySelector(sel); return (m && (m.getAttribute('content') || '')).trim(); };
    const text = (el) => (el && (el.innerText || el.textContent) || '').replace(/\s+/g, ' ').trim();
    const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) !== 0; };
    const hex = (c) => { const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/.exec(c || ''); if (!m) return ''; if (m[4] !== undefined && parseFloat(m[4]) < 0.5) return ''; return '#' + [m[1], m[2], m[3]].map((n) => (+n).toString(16).padStart(2, '0')).join(''); };
    const classOf = (el) => (typeof el.className === 'string' ? el.className : (el.className && el.className.baseVal) || '');
    const score = (el) => {
      const a = el.closest('a');
      const hint = [el.getAttribute('src') || '', el.getAttribute('alt') || '', el.getAttribute('title') || '', el.getAttribute('aria-label') || '', classOf(el), el.id || '', a ? (a.getAttribute('aria-label') || '') + ' ' + classOf(a) + ' ' + (a.id || '') + ' ' + (a.getAttribute('title') || '') : ''].join(' ').toLowerCase();
      let s = 0;
      if (/logo/.test(hint)) s += 6;
      if (/brand|site-title|navbar-brand|home/.test(hint)) s += 2;
      if (el.closest('header, nav, [role="banner"], .header, .navbar, #header, .site-header, .topbar')) s += 3;
      if (a) { const h = (a.getAttribute('href') || '').replace(/\/+$/, ''); if (h === '' || h === location.origin || h === '/' || h === '.' || h === location.pathname.replace(/\/+$/, '')) s += 3; }
      const r = el.getBoundingClientRect();
      if (r.top < 220) s += 1;
      if (r.width < 20 || r.height < 12) s -= 6;
      if (r.width > 760 || r.height > 420) s -= 4;
      if (/avatar|icon-|social|payment|badge|flag|arrow|menu|close|search|cart|user|chevron|star/.test(hint)) s -= 4;
      return s;
    };
    let best = null, bestScore = 1;
    for (const el of document.querySelectorAll('img, svg')) {
      if (!visible(el) || el.closest('svg')) continue;
      const s = score(el);
      if (s > bestScore) { bestScore = s; best = el; }
    }
    let logo = null;
    if (best) {
      const r = best.getBoundingClientRect();
      if (best.tagName.toLowerCase() === 'img') {
        const srcset = (best.getAttribute('srcset') || '').split(',').map((s) => s.trim().split(/\s+/)[0]).filter(Boolean);
        // the sharpest source: the last srcset entry, else the file itself
        const src = srcset[srcset.length - 1] || best.currentSrc || best.getAttribute('src') || best.getAttribute('data-src') || '';
        logo = { kind: 'img', src: abs(src), alt: best.getAttribute('alt') || '', width: Math.round(r.width), height: Math.round(r.height), natural: Math.max(best.naturalWidth || 0, best.naturalHeight || 0) };
      } else {
        const svg = best.cloneNode(true);
        for (const use of svg.querySelectorAll('use')) {
          const ref = use.getAttribute('href') || use.getAttribute('xlink:href') || '';
          const sym = ref.startsWith('#') ? document.querySelector(ref) : null;
          if (sym) {
            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.innerHTML = sym.innerHTML;
            if (!svg.getAttribute('viewBox') && sym.getAttribute('viewBox')) svg.setAttribute('viewBox', sym.getAttribute('viewBox'));
            use.replaceWith(g);
          }
        }
        if (!svg.getAttribute('xmlns')) svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        const cs = getComputedStyle(best);
        if (cs.color) svg.setAttribute('color', cs.color);
        if (!svg.getAttribute('viewBox') && r.width && r.height) svg.setAttribute('viewBox', `0 0 ${Math.round(r.width)} ${Math.round(r.height)}`);
        svg.removeAttribute('width'); svg.removeAttribute('height'); svg.removeAttribute('class'); svg.removeAttribute('style');
        logo = { kind: 'svg', svg: svg.outerHTML.slice(0, 120000), width: Math.round(r.width), height: Math.round(r.height) };
      }
    }
    const icons = [...document.querySelectorAll('link[rel~="apple-touch-icon"], link[rel~="icon"], link[rel="shortcut icon"]')]
      .map((l) => ({ href: abs(l.getAttribute('href')), size: parseInt((l.getAttribute('sizes') || '0').split('x')[0], 10) || (/apple-touch/.test(l.getAttribute('rel') || '') ? 180 : 32) }))
      .filter((i) => i.href).sort((a, b) => b.size - a.size);
    const colors = [];
    const push = (c, role) => { const h = hex(c); if (h && !colors.some((x) => x.value === h)) colors.push({ value: h, role }); };
    const rawTheme = meta('meta[name="theme-color"]');
    if (/^#[0-9a-f]{6}$/i.test(rawTheme)) colors.push({ value: rawTheme.toLowerCase(), role: 'theme-color' });
    const bgOf = (el) => { let e = el; while (e && e !== document.documentElement) { const h = hex(getComputedStyle(e).backgroundColor); if (h) return h; e = e.parentElement; } return ''; };
    const header = document.querySelector('header, nav, [role="banner"], .header, .navbar');
    if (header) { const h = bgOf(header); if (h && !colors.some((x) => x.value === h)) colors.push({ value: h, role: 'header' }); }
    for (const b of [...document.querySelectorAll('button, a[class*="btn"], a[class*="button"], [class*="cta"], [class*="primary"]')].filter(visible).slice(0, 8)) { push(getComputedStyle(b).backgroundColor, 'button'); push(getComputedStyle(b).color, 'button-text'); }
    push(getComputedStyle(document.body).backgroundColor, 'page');
    push(getComputedStyle(document.body).color, 'text');
    const heads = [...document.querySelectorAll('h1, h2, h3')].filter(visible).map(text).filter((t) => t.length > 2 && t.length < 140).slice(0, 12);
    return {
      url: location.href, title: (document.title || '').trim(), lang: document.documentElement.lang || '',
      siteName: meta('meta[property="og:site_name"]') || meta('meta[name="application-name"]'),
      description: meta('meta[name="description"]') || meta('meta[property="og:description"]'),
      ogTitle: meta('meta[property="og:title"]'), ogImage: abs(meta('meta[property="og:image"]') || meta('meta[name="twitter:image"]')),
      logo, icons: icons.slice(0, 3), colors: colors.slice(0, 8), headings: heads, text: text(document.body).slice(0, 1200),
    };
  });
  await mkdir(outDir, { recursive: true });
  const shot = resolve(outDir, 'site-shot.jpg');
  await page.screenshot({ path: shot, type: 'jpeg', quality: 70 }).catch(() => {});
  await browser.close();
  const fetchFile = async (link, base, maxBytes) => {
    let u;
    try { u = new URL(link); } catch { return ''; }
    if (!/^https?:$/.test(u.protocol) || !(await hostOK(u.hostname))) return '';
    let res;
    try { res = await fetch(link, { headers: { 'user-agent': ua, accept: 'image/*,*/*;q=0.5' }, signal: AbortSignal.timeout(15000), redirect: 'follow' }); } catch { return ''; }
    if (!res.ok) return '';
    const type = (res.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
    const ext = { 'image/svg+xml': 'svg', 'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/gif': 'gif', 'image/x-icon': 'ico', 'image/vnd.microsoft.icon': 'ico', 'image/avif': 'avif' }[type] || (link.split('?')[0].match(/\.(svg|png|jpe?g|webp|gif|ico|avif)$/i) || [])[1];
    if (!ext) return '';
    const bytes = Buffer.from(await res.arrayBuffer());
    if (!bytes.length || bytes.length > maxBytes) return '';
    const file = resolve(outDir, `${base}.${ext.toLowerCase() === 'jpeg' ? 'jpg' : ext.toLowerCase()}`);
    await writeFile(file, bytes);
    return file;
  };
  let logoFile = '', logoFrom = 'the page';
  // a tiny page logo (an icon-sized file) loses to the site's touch icon,
  // which is at least 180px - a decor-sized use needs the pixels
  const tinyLogo = info.logo && info.logo.kind === 'img' && info.logo.natural > 0 && info.logo.natural < 120;
  const bigIcon = info.icons.find((i) => i.size >= 180);
  if (tinyLogo && bigIcon) { logoFile = await fetchFile(bigIcon.href, 'site-logo', 2 * 1024 * 1024); if (logoFile) logoFrom = 'the site icon'; }
  if (!logoFile && info.logo && info.logo.kind === 'svg') { logoFile = resolve(outDir, 'site-logo.svg'); await writeFile(logoFile, info.logo.svg); }
  else if (!logoFile && info.logo && info.logo.kind === 'img') logoFile = await fetchFile(info.logo.src, 'site-logo', 4 * 1024 * 1024);
  if (!logoFile) for (const icon of info.icons) { logoFile = await fetchFile(icon.href, 'site-logo', 2 * 1024 * 1024); if (logoFile) { logoFrom = 'the site icon'; break; } }
  const ogFile = info.ogImage ? await fetchFile(info.ogImage, 'site-og', 8 * 1024 * 1024) : '';
  const rel = (f) => (f ? relative(process.cwd(), f) : '');
  const summary = Object.assign({}, info, { logo: info.logo ? Object.assign({}, info.logo, { svg: undefined }) : null, logoFile: rel(logoFile), ogFile: rel(ogFile), shot: rel(shot) });
  await writeFile(resolve(outDir, 'site.json'), JSON.stringify(summary, null, 2));
  const lines = [];
  const name = info.siteName || info.ogTitle || info.title || target.hostname;
  lines.push(`SITE: ${name}${info.title && info.title !== name ? ` - ${info.title}` : ''} (${target.hostname})`);
  if (info.description) lines.push(`ABOUT: ${info.description.slice(0, 300)}`);
  if (info.headings.length) lines.push(`HEADINGS: ${info.headings.slice(0, 8).join(' | ')}`);
  if (info.text) lines.push(`TEXT: ${info.text.slice(0, 600)}`);
  lines.push(logoFile
    ? `LOGO: ${rel(logoFile)} (from ${logoFrom}${info.logo && info.logo.width && logoFrom === 'the page' ? `, ${info.logo.width}x${info.logo.height}` : ''}) - the brand's real logo: use it as is, "logo":{"file":"${rel(logoFile)}"}, never redraw it`
    : 'LOGO: none found on the page - design a lockup from the brand name (logo.mark)');
  if (ogFile) lines.push(`PHOTO: ${rel(ogFile)} - the site's own share image; use it as the photo only when it is a real photograph of the business (not a text banner), otherwise fetch one`);
  if (info.colors.length) lines.push(`COLORS: ${info.colors.map((c) => `${c.value} (${c.role})`).join(', ')} - derive the palette from these (brand-derived)`);
  if (existsSync(shot)) lines.push(`LOOK: ${rel(shot)} - a screenshot of the site; read it ONCE only when the brand's look matters for the design`);
  process.stdout.write(lines.join('\n') + '\n');
}

// ---------------------------------------------------------------------------
// image: the photo tools - what a retoucher does in Photoshop, one command
// each (owner 2026-09-14: "the client sends a picture and asks for edits:
// remove the background, remove something, fix something, improve the
// quality - build the professional tools for that").
//
//   node design.mjs image info    <in>                              size, alpha, brightness
//   node design.mjs image grid    <in> --out assets/grid.png         the picture with a coordinate grid (to point at things)
//   node design.mjs image cutout  <in> --out assets/cut.png [--bg "#fff"|blur|assets/bg.jpg] [--model u2net_human_seg] [--matting] [--trim]
//   node design.mjs image erase   <in> --out assets/clean.jpg --rect x,y,w,h [--rect ...] [--mask m.png] [--feather 6]
//   node design.mjs image sample  <in> --at x,y [--at x2,y2] [--radius 4]     the exact colours at points (the eyedropper)
//   node design.mjs image patch   <in> --out assets/p.png --from x,y,w,h --dest x,y [--flip h|v] [--scale 1.1] [--rotate deg] [--feather px]
//                                 the clone stamp: a region of the same picture copied over a gap through a feathered edge
//   node design.mjs image place   <in> --out assets/p.png --overlay assets/pic.png --quad x1,y1,x2,y2,x3,y3,x4,y4 [--fit contain|cover|stretch] [--margin 0.06] [--shade 0.75] [--blend print|multiply|normal] [--corners 0.04]
//                                 a picture ON a surface in the photo (a card, a screen, a wall, a shirt): warped into the surface's
//                                 perspective, lit by the surface's own light and shadows, blended like print - never a flat paste
//   node design.mjs image draw    <in> --out assets/d.png --svg <file.svg | inline markup> | --html <file.html | fragment>
//                                 vector shapes / CSS painted over the picture at its own pixel coordinates (paths, gradients,
//                                 strokes, blur and drop-shadow filters, text in our fonts) - how a missing flat part is redrawn
//   node design.mjs image enhance <in> --out assets/enh.jpg [--strength 0.6] [--denoise] [--upscale 2|4]
//   node design.mjs image adjust  <in> --out assets/adj.jpg [--brightness 1.1] [--contrast 1.1] [--saturation 1.2] [--hue 0]
//                                 [--temperature -100..100] [--gamma 1.0] [--sharpen 0..3] [--blur px] [--vignette 0..1] [--grain 0..1]
//                                 [--crop x,y,w,h] [--rotate deg] [--flip h|v] [--resize WxH|Wx|xH] [--fit WxH] [--format png|jpg|webp] [--quality 92]
//   node design.mjs image mask    <in> --out assets/mask.png (--rect ... | --subject) [--invert]
//   node design.mjs image recolor <in> --out assets/re.jpg (--rect ...|--mask m.png|--subject) (--to "#hex" | --hue 30 --sat 1 --light 0)
//   node design.mjs image textfind <in> --out assets/text.json         every text in the picture: numbered boxes (assets/text-boxes.png), text, colour, background
//   node design.mjs image textreplace <in> --out assets/new.jpg --boxes assets/text.json --box <n> --text "new text" [--font F] [--weight 700] [--color #hex] [--align auto|left|center|right] [--shadow]
//                                 the old text taken out cleanly, the new one set in the matching face, weight, size and colour; --text "" only removes
//   node design.mjs image deliver <edited> --out designs/photo.html   the finished picture as a design page at its own size
//
// Coordinates are always in the picture's own pixels. Heavy work (cutout,
// erase, enhance, mask, recolor) runs in the container's Python toolbox.
// (a function, not consts: compose() and image() run at module top level
// before a const declared further down would be initialised)
function imageEnv() { return { py: '/opt/uttra-design-render/py/bin/python', tool: '/opt/uttra-design-render/tools/imgtool.py', maxSide: 4096 }; }

async function loadSharp() {
  try { return createRequire('/opt/uttra-browser/package.json')('sharp'); } catch {}
  try { return (await import('sharp')).default; } catch {}
  return null;
}

function imageUsage() {
  process.stderr.write('usage: node design.mjs image <info|grid|cutout|erase|enhance|adjust|mask|recolor|deliver> <in> --out <file> [options] - see the header of design.mjs\n');
  process.exit(2);
}

function imageHint(out) {
  return out ? `Read ${out} ONCE to confirm the result. When it is right: node design.mjs image deliver ${out} --out designs/photo.html && node design.mjs render designs/photo.html\n` : '';
}

async function image() {
  const { py: IMAGE_PY, tool: IMAGE_TOOL, maxSide: IMAGE_MAX_SIDE } = imageEnv();
  const op = args[1];
  const input = args[2];
  if (!op || (op !== 'textstyles' && (!input || input.startsWith('--')))) imageUsage();
  if (['cutout', 'erase', 'enhance', 'mask', 'recolor', 'textfind', 'textreplace', 'textstyles', 'textadd', 'sample', 'patch', 'place'].includes(op)) {
    if (!flag('out') && op !== 'textstyles' && op !== 'sample') imageUsage();
    const { spawn } = await import('node:child_process');
    if (!existsSync(IMAGE_PY) || !existsSync(IMAGE_TOOL)) {
      process.stdout.write(`IMAGE TOOL UNAVAILABLE (${op}) - the photo toolbox is not installed in this workspace; tell the user plainly that this edit is not available right now.\n`);
      process.exit(3);
    }
    const child = spawn(IMAGE_PY, [IMAGE_TOOL, op, ...args.slice(2)], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { OMP_NUM_THREADS: '4' }) });
    let out = '', err = '';
    child.stdout.on('data', (d) => { out += d; });
    child.stderr.on('data', (d) => { err += d; });
    const timer = setTimeout(() => child.kill('SIGKILL'), 300000);
    const code = await new Promise((r) => child.on('close', r));
    clearTimeout(timer);
    if (code !== 0) {
      const last = err.trim().split('\n').filter(Boolean).pop() || `exit ${code}`;
      process.stdout.write(`IMAGE TOOL FAILED (${op}): ${last.slice(0, 300)}\n`);
      process.exit(3);
    }
    let info = null;
    try { info = JSON.parse(out.trim().split('\n').pop()); } catch {}
    process.stdout.write((info ? JSON.stringify(info) : out.trim()) + '\n' + imageHint(info && info.out ? info.out : flag('out')));
    return;
  }
  const sharp = await loadSharp();
  if (!sharp) { process.stdout.write('IMAGE TOOL UNAVAILABLE: sharp is not installed\n'); process.exit(3); }
  if (!existsSync(input)) { process.stdout.write(`IMAGE TOOL FAILED (${op}): no such file ${input}\n`); process.exit(3); }
  const num = (name, d) => { const v = parseFloat(flag(name)); return Number.isFinite(v) ? v : d; };

  if (op === 'info') {
    const meta = await sharp(input).rotate().metadata();
    const st = await sharp(input).rotate().stats();
    const mean = st.channels.slice(0, 3).reduce((a, c) => a + c.mean, 0) / Math.min(3, st.channels.length);
    process.stdout.write(JSON.stringify({ file: input, width: meta.width, height: meta.height, format: meta.format, transparent: !!meta.hasAlpha, brightness: Math.round(mean), dark: mean < 70, flat: st.channels.slice(0, 3).every((c) => c.stdev < 40) }) + '\n');
    return;
  }

  if (op === 'grid') {
    const out = flag('out') || 'assets/grid.png';
    const meta = await sharp(input).rotate().metadata();
    const W = meta.width, H = meta.height;
    const scale = Math.min(1, 1400 / Math.max(W, H));
    const w = Math.round(W * scale), h = Math.round(H * scale);
    const lines = [];
    for (let i = 1; i < 10; i++) {
      const x = Math.round(w * i / 10), y = Math.round(h * i / 10);
      lines.push(`<line x1="${x}" y1="0" x2="${x}" y2="${h}" stroke="#fff" stroke-opacity=".55" stroke-width="1"/><line x1="0" y1="${y}" x2="${w}" y2="${y}" stroke="#fff" stroke-opacity=".55" stroke-width="1"/>`);
      lines.push(`<text x="${x + 3}" y="14" font-family="sans-serif" font-size="13" fill="#fff" stroke="#000" stroke-width="3" paint-order="stroke">${Math.round(W * i / 10)}</text>`);
      lines.push(`<text x="3" y="${y - 3}" font-family="sans-serif" font-size="13" fill="#fff" stroke="#000" stroke-width="3" paint-order="stroke">${Math.round(H * i / 10)}</text>`);
    }
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}">${lines.join('')}<text x="${w - 4}" y="${h - 6}" text-anchor="end" font-family="sans-serif" font-size="13" fill="#fff" stroke="#000" stroke-width="3" paint-order="stroke">${W} x ${H}</text></svg>`;
    await mkdir(dirname(resolve(out)), { recursive: true });
    await sharp(input).rotate().resize(w, h).composite([{ input: Buffer.from(svg) }]).png().toFile(out);
    process.stdout.write(`${out} - the picture (${W}x${H}) with lines every 10%; the labels are ORIGINAL pixel coordinates. Read it once, then give --rect x,y,w,h in those pixels (generous: include a margin around the thing).\n`);
    return;
  }

  if (op === 'adjust') {
    const out = flag('out');
    if (!out) imageUsage();
    let img = sharp(input, { failOn: 'none' }).rotate();
    if (flag('crop')) {
      const [x, y, w, h] = flag('crop').split(',').map((v) => Math.round(parseFloat(v)));
      if ([x, y, w, h].some((v) => !Number.isFinite(v)) || w <= 0 || h <= 0) { process.stdout.write('IMAGE TOOL FAILED (adjust): --crop wants x,y,w,h\n'); process.exit(3); }
      img = img.extract({ left: Math.max(0, x), top: Math.max(0, y), width: w, height: h });
    }
    if (flag('rotate')) img = img.rotate(num('rotate', 0), { background: { r: 0, g: 0, b: 0, alpha: 0 } });
    if (flag('flip') === 'h') img = img.flop();
    if (flag('flip') === 'v') img = img.flip();
    if (flag('resize')) {
      const m = /^(\d*)x(\d*)$/i.exec(flag('resize'));
      if (m && (m[1] || m[2])) img = img.resize({ width: m[1] ? +m[1] : undefined, height: m[2] ? +m[2] : undefined, fit: 'inside' });
    }
    if (flag('fit')) {
      const m = /^(\d+)x(\d+)$/i.exec(flag('fit'));
      if (m) img = img.resize({ width: +m[1], height: +m[2], fit: 'cover', position: flag('position') || 'attention' });
    }
    const b = num('brightness', 1), s = num('saturation', 1), hue = num('hue', 0);
    if (b !== 1 || s !== 1 || hue) img = img.modulate({ brightness: b, saturation: s, hue });
    const c = num('contrast', 1);
    if (c !== 1) img = img.linear([c, c, c], [128 * (1 - c), 128 * (1 - c), 128 * (1 - c)]);
    const g = num('gamma', 0);
    if (g) img = img.gamma(Math.max(1, Math.min(3, g)));
    const temp = num('temperature', 0);
    if (temp) { const k = Math.max(-1, Math.min(1, temp / 100)); img = img.recomb([[1 + 0.22 * k, 0, 0], [0, 1 + 0.04 * k, 0], [0, 0, 1 - 0.22 * k]]); }
    const sh = num('sharpen', 0);
    if (sh > 0) img = img.sharpen({ sigma: 0.7 + 0.5 * sh, m1: 0.4 * sh, m2: 1.0 * sh });
    const bl = num('blur', 0);
    if (bl > 0) img = img.blur(Math.max(0.3, bl));
    const vig = num('vignette', 0), grain = num('grain', 0);
    if (vig > 0 || grain > 0) {
      const { data, info } = await img.png().toBuffer({ resolveWithObject: true });
      const overlays = [];
      if (vig > 0) overlays.push({ input: Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${info.width}" height="${info.height}"><defs><radialGradient id="v" cx="50%" cy="50%" r="72%"><stop offset="45%" stop-color="#000" stop-opacity="0"/><stop offset="100%" stop-color="#000" stop-opacity="${Math.min(1, vig).toFixed(2)}"/></radialGradient></defs><rect width="100%" height="100%" fill="url(#v)"/></svg>`) });
      if (grain > 0) overlays.push({ input: Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="${info.width}" height="${info.height}"><filter id="n"><feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch"/><feColorMatrix type="saturate" values="0"/></filter><rect width="100%" height="100%" filter="url(#n)" opacity="${Math.min(0.5, grain * 0.35).toFixed(2)}"/></svg>`), blend: 'overlay' });
      img = sharp(data).composite(overlays);
    }
    const meta = await sharp(input).metadata();
    const fmt = (flag('format') || (out.split('.').pop() || '').toLowerCase()).replace('jpeg', 'jpg');
    const q = Math.round(num('quality', 92));
    if (fmt === 'jpg') img = img.flatten({ background: '#ffffff' }).jpeg({ quality: q, chromaSubsampling: '4:4:4' });
    else if (fmt === 'webp') img = img.webp({ quality: q });
    else img = img.png();
    await mkdir(dirname(resolve(out)), { recursive: true });
    const res = await img.toFile(out);
    process.stdout.write(JSON.stringify({ ok: true, op: 'adjust', out, width: res.width, height: res.height, from: `${meta.width}x${meta.height}` }) + '\n' + imageHint(out));
    return;
  }

  if (op === 'draw') {
    const out = flag('out');
    const svgArg = flag('svg'), htmlArg = flag('html');
    if (!out || (!svgArg && !htmlArg)) { process.stdout.write('IMAGE TOOL FAILED (draw): --out and one of --svg <file.svg | inline markup> / --html <file.html | fragment> are required\n'); process.exit(3); }
    const meta = await sharp(input).rotate().metadata();
    const W = meta.width, H = meta.height;
    const source = svgArg || htmlArg;
    let layer = existsSync(source) ? await readFile(source, 'utf8') : source;
    if (svgArg && !/<svg[\s>]/i.test(layer)) layer = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}">${layer}</svg>`;
    const chromium = await loadChromium();
    if (!chromium) { process.stdout.write('IMAGE TOOL UNAVAILABLE (draw): no browser\n'); process.exit(3); }
    const mime = meta.format === 'jpeg' ? 'image/jpeg' : meta.format === 'webp' ? 'image/webp' : 'image/png';
    const dataUrl = `data:${mime};base64,${(await sharp(input).rotate().toBuffer()).toString('base64')}`;
    const html = `<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="file://${resolve('design-kit.css')}"><style>html,body{margin:0;background:transparent}#stage{position:relative;width:${W}px;height:${H}px;overflow:hidden}#stage>img{position:absolute;inset:0;width:100%;height:100%;display:block}#layer{position:absolute;inset:0;width:${W}px;height:${H}px}#layer>svg{width:100%;height:100%;display:block}</style></head><body><div id="stage"><img src="${dataUrl}"><div id="layer">${layer}</div></div></body></html>`;
    const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage', '--font-render-hinting=none'] });
    try {
      const page = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
      await page.setContent(html, { waitUntil: 'load' });
      await page.evaluate(() => (document.fonts ? document.fonts.ready.then(() => true) : true)).catch(() => {});
      await page.waitForTimeout(120);
      const png = await page.screenshot({ type: 'png', clip: { x: 0, y: 0, width: W, height: H }, omitBackground: true, animations: 'disabled' });
      await mkdir(dirname(resolve(out)), { recursive: true });
      if (/\.jpe?g$/i.test(out)) await sharp(png).flatten({ background: '#ffffff' }).jpeg({ quality: 92, chromaSubsampling: '4:4:4' }).toFile(out);
      else await sharp(png).png().toFile(out);
    } finally { await browser.close(); }
    process.stdout.write(JSON.stringify({ ok: true, op: 'draw', out, width: W, height: H, layer: svgArg ? 'svg' : 'html' }) + '\n' + imageHint(out));
    return;
  }

  if (op === 'deliver') {
    const out = flag('out') || 'designs/photo.html';
    const meta = await sharp(input).rotate().metadata();
    let src = input, w = meta.width, h = meta.height;
    if (!w || !h) { process.stdout.write('IMAGE TOOL FAILED (deliver): unreadable picture\n'); process.exit(3); }
    if (Math.max(w, h) > IMAGE_MAX_SIDE) {
      const s = IMAGE_MAX_SIDE / Math.max(w, h);
      w = Math.round(w * s); h = Math.round(h * s);
      src = input.replace(/(\.[a-z0-9]+)?$/i, '') + '-fit.png';
      await sharp(input).rotate().resize(w, h).png().toFile(src);
    }
    const transparent = !!meta.hasAlpha;
    const rel = relative(dirname(resolve(out)), resolve(src)).split('\\').join('/');
    const html = `<!doctype html><html lang="ar"><head><meta charset="utf-8"><link rel="stylesheet" href="../design-kit.css"><style>html,body,.stage{background:transparent!important}</style></head><body><div class="stage" data-layout="image" data-composed="uttra"${transparent ? ' data-transparent="1"' : ''} style="--w:${w}px;--h:${h}px;background:${transparent ? 'transparent' : '#000'}"><img src="${rel}" data-slot="hero" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill;display:block"></div></body></html>`;
    await mkdir(dirname(resolve(out)), { recursive: true });
    await writeFile(out, html);
    process.stdout.write(`${out} (${w}x${h}${transparent ? ', transparent PNG' : ''}) - the finished picture at its own size. Now: node design.mjs render ${out} - then the marker lists ${out}.\n`);
    return;
  }
  imageUsage();
}

// ---------------------------------------------------------------------------
// --- tool: tools the designer builds and keeps ---------------------------------
// The owner's rule (2026-09-15): when an edit needs an operation no tool
// does, the designer may build a small Python tool on the libraries already
// installed, test it on the job, and keep it for every later job. Kept
// tools live outside the workspace (tools/learned); saving is refused for
// anything that reaches the network, spawns processes, installs, downloads
// or trains models, or touches paths outside the job - the studio stays
// light on the servers.
function toolDir() { return '/opt/uttra-design-render/tools/learned'; }
function toolBuiltins() { return new Set(['info', 'grid', 'cutout', 'erase', 'enhance', 'adjust', 'mask', 'recolor', 'textfind', 'textreplace', 'textstyles', 'textadd', 'sample', 'patch', 'place', 'draw', 'deliver', 'run', 'save', 'list', 'rm']); }
function toolForbidden() {
  return [
  [/^\s*(?:import|from)\s+(?:requests|urllib\d?|http|httpx|aiohttp|socket|ftplib|smtplib|telnetlib|subprocess|multiprocessing|ctypes|pip|setuptools|importlib|torch|torchvision|transformers|diffusers|huggingface_hub|tensorflow|keras|jax)\b/m, 'a network, process, install or model library'],
  [/\b(?:urlopen|urlretrieve|hf_hub_download|snapshot_download|torch\.hub|load_state_dict|\.train\(|optimizer|\.backward\(|\.fit\(|pip\s+install)/, 'a download or training'],
  [/\bos\.(?:system|popen|remove|unlink|rmdir|removedirs|chmod|chown|kill|exec\w*|spawn\w*|fork)\s*\(/, 'a shell or file removal'],
  [/\b(?:eval|exec|__import__|compile)\s*\(/, 'dynamic code'],
  [/\bshutil\.(?:rmtree|move|copytree)\b/, 'file removal'],
  [/['"`]\/(?!opt\/uttra-design-render\/(?:models|fonts)\/)[A-Za-z_.]/, 'an absolute path outside the job'],
  [/\.\.\//, 'a parent directory'],
  ];
}
function toolHeader(src) {
  const get = (k) => { const m = src.match(new RegExp('^#\\s*' + k + ':\\s*(.+)$', 'm')); return m ? m[1].trim() : ''; };
  return { name: get('tool'), desc: get('desc'), usage: get('usage') };
}
function toolProblems(src, header) {
  const out = [];
  if (Buffer.byteLength(src) > 60 * 1024) out.push('larger than 60 KB');
  if (!/^[a-z][a-z0-9-]{2,40}$/.test(header.name)) out.push('header "# tool: <kebab-name>" missing or not kebab-case');
  if (toolBuiltins().has(header.name)) out.push('the name collides with a built-in');
  if (!header.desc) out.push('header "# desc: ..." missing');
  if (!header.usage) out.push('header "# usage: ..." missing');
  if (!/\bargparse\b/.test(src)) out.push('no argparse (a tool takes its files and options as arguments)');
  for (const [re, why] of toolForbidden()) { const m = src.match(re); if (m) out.push(why + ' (' + String(m[0]).trim().slice(0, 40) + ')'); }
  return out;
}
async function toolManifest() { try { const m = JSON.parse(await readFile(toolDir() + '/manifest.json', 'utf8')); return { tools: Array.isArray(m.tools) ? m.tools : [] }; } catch { return { tools: [] }; } }
async function writeToolManifest(m) { await mkdir(toolDir(), { recursive: true }); await writeFile(toolDir() + '/manifest.json', JSON.stringify(m, null, 1)); }
function toolUsage() { process.stderr.write('usage: node design.mjs tool list | run <tools/name.py|name> <args...> | save <tools/name.py> [--replace] | rm <name>\n'); process.exit(2); }
async function tool() {
  const sub = args[1];
  const { py: IMAGE_PY } = imageEnv();
  const { createHash } = await import('node:crypto');
  if (sub === 'list') {
    const m = await toolManifest();
    if (!m.tools.length) { process.stdout.write('No tools built yet. Build one as MISSING TOOL says when an operation is missing.\n'); return; }
    for (const t of m.tools) process.stdout.write(`${t.name} - ${t.desc}\n  usage: node design.mjs tool run ${t.usage}\n  (kept ${String(t.savedAt || '').slice(0, 10)}, run ${t.runs || 0}x)\n`);
    return;
  }
  if (sub === 'run') {
    let target = args[2];
    if (!target) toolUsage();
    const rest = args.slice(3);
    let learned = null;
    if (!target.endsWith('.py')) {
      const m = await toolManifest();
      learned = m.tools.find((t) => t.name === target);
      if (!learned) { process.stdout.write(`No tool named ${target}. \`node design.mjs tool list\` shows the tools that exist; a new one is written as tools/<name>.py in this directory.\n`); process.exit(3); }
      target = toolDir() + '/' + learned.file;
    } else if (!existsSync(target)) { process.stdout.write(`${target} does not exist.\n`); process.exit(3); }
    const src = await readFile(target, 'utf8');
    const problems = toolProblems(src, toolHeader(src));
    if (problems.length) { process.stdout.write('This tool cannot run here: ' + problems.join('; ') + '. Fix the file - the rules are in MISSING TOOL.\n'); process.exit(3); }
    const { spawn } = await import('node:child_process');
    const started = Date.now();
    const child = spawn('bash', ['-c', 'ulimit -v 6000000; exec nice -n 10 "$@"', 'tool', IMAGE_PY, resolve(target), ...rest], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { OMP_NUM_THREADS: '4', PYTHONDONTWRITEBYTECODE: '1' }) });
    let out = '', err = '';
    child.stdout.on('data', (d) => { out += d; });
    child.stderr.on('data', (d) => { err += d; });
    const timer = setTimeout(() => child.kill('SIGKILL'), 180000);
    const code = await new Promise((r) => child.on('close', r));
    clearTimeout(timer);
    process.stdout.write(out.slice(-4000));
    const seconds = Math.round((Date.now() - started) / 1000);
    if (code !== 0) { process.stdout.write(`TOOL FAILED (exit ${code}, ${seconds} s)\n${err.trim().split('\n').slice(-12).join('\n')}\n`); process.exit(3); }
    const sha = createHash('sha1').update(src).digest('hex');
    let runs = [];
    try { runs = JSON.parse(await readFile('.tool-runs.json', 'utf8')); } catch {}
    runs.push({ sha, at: Date.now(), seconds });
    await writeFile('.tool-runs.json', JSON.stringify(runs));
    if (learned) { const m = await toolManifest(); const t = m.tools.find((x) => x.name === learned.name); if (t) { t.runs = (t.runs || 0) + 1; t.lastRun = new Date().toISOString(); await writeToolManifest(m); } }
    const outFile = flag('out');
    process.stdout.write(`OK (${seconds} s).${outFile ? ' Read ' + outFile + ' ONCE to confirm the result.' : ''}\n`);
    return;
  }
  if (sub === 'save') {
    const file = args[2];
    if (!file || !file.endsWith('.py') || !existsSync(file)) toolUsage();
    const src = await readFile(file, 'utf8');
    const header = toolHeader(src);
    const problems = toolProblems(src, header);
    if (problems.length) { process.stdout.write('NOT SAVED: ' + problems.join('; ') + '.\n'); process.exit(3); }
    const sha = createHash('sha1').update(src).digest('hex');
    let runs = [];
    try { runs = JSON.parse(await readFile('.tool-runs.json', 'utf8')); } catch {}
    if (!runs.some((r) => r.sha === sha)) { process.stdout.write('NOT SAVED: this exact file has not run successfully in this job yet - `node design.mjs tool run ' + file + ' ...` first, read the result, then save.\n'); process.exit(3); }
    const m = await toolManifest();
    const existing = m.tools.find((t) => t.name === header.name);
    if (existing && !args.includes('--replace')) { process.stdout.write(`NOT SAVED: a tool named ${header.name} already exists (run it with \`tool run ${header.name}\`); pass --replace to overwrite it with this better version.\n`); process.exit(3); }
    await mkdir(toolDir(), { recursive: true });
    await writeFile(toolDir() + '/' + header.name + '.py', src);
    const entry = { name: header.name, desc: header.desc.slice(0, 160), usage: header.usage.slice(0, 200), file: header.name + '.py', savedAt: new Date().toISOString(), runs: existing ? existing.runs || 0 : 0, sha };
    m.tools = m.tools.filter((t) => t.name !== header.name).concat([entry]);
    await writeToolManifest(m);
    process.stdout.write(`SAVED ${header.name} - every later job can run it with: node design.mjs tool run ${header.usage}\n`);
    return;
  }
  if (sub === 'rm') {
    const m = await toolManifest();
    const t = m.tools.find((x) => x.name === args[2]);
    if (!t) { process.stdout.write('no such tool\n'); process.exit(3); }
    m.tools = m.tools.filter((x) => x !== t);
    await writeToolManifest(m);
    await rm(toolDir() + '/' + t.file, { force: true });
    process.stdout.write('removed\n');
    return;
  }
  toolUsage();
}

// --- look: the studio's learned compositions ---------------------------------
// looks.json is written into the workspace by the platform (the looks real
// users kept); `look <name>` prints one skeleton to start spec.json from.
async function look() {
  let doc;
  try { doc = JSON.parse(await readFile('looks.json', 'utf8')); } catch { process.stdout.write('No learned looks for this job.\n'); return; }
  const looks = Array.isArray(doc.looks) ? doc.looks : [];
  const name = args[1];
  if (!name || name === 'list') { for (const l of looks) process.stdout.write(`${l.name} - ${l.summary}\n`); return; }
  const l = looks.find((x) => x.name === name);
  if (!l) { process.stdout.write(`No learned look named ${name}. Known: ${looks.map((x) => x.name).join(', ')}\n`); process.exit(3); }
  process.stdout.write(JSON.stringify(l.spec, null, 1) + '\n');
}

// ---------------------------------------------------------------------------
// compose: the design composer. The agent writes a SMALL spec (copy, palette,
// fonts, photo, layout, effects) and this turns it into a finished page on
// the kit in milliseconds - no hand-written HTML, the same spec for every
// size. Layouts: split | fade | bleed | poster | top | circle | diagonal | frame | card | bignumber | magazine | type | product | logo.
// Designer moves (owner 2026-09-15: no stamps): photo.cutout = a rembg cut-out of the subject that
// spills over the text column (the headline runs behind it), scale "loud" (headline up to 14u),
// effects "stickers" (facts as tilted stickers pinned on the photo) and "echo" (the photo's own
// colours blurred into the background), copy.number for the big-number hero.
//
//   node design.mjs compose spec.json --out designs/ig-square.html [--size ig-square]
//
// spec (every field optional except copy.headline):
// { "layout":"fade", "photo":{"file":"assets/hero.jpg","side":"left|right","focus":"50% 25%","treatment":"fade|rounded|arch|circle|none"},
//   "palette":{"bg":"#F6F7F9","bg2":"#E9EEF2","ink":"#12171C","ink2":"#3D4852","accent":"#1E9E8A","accent2":"#C9A75A","muted":"#8A96A0"},
//   "fonts":{"display":"Alexandria","body":"Cairo","latin":"Plus Jakarta Sans"},
//   "copy":{"brand":"..","eyebrow":"..","headline":"first line|second line","accentWord":"..","subline":"..","cta":"..","facts":["..",".."],"detail":".."},
//   "effects":["mesh","grain","ring","dots","blob","vignette","gradient-word","highlight-word","glow"],
//   "logo":"assets/logo.png",
//   "textStyle":"neon-glow" (a text style id from `node design.mjs image textstyles`, or an inline
//                            definition {fill, stroke, shadow, glow, extrude, box, letterSpacing}) - on the headline }
async function compose() {
  const specFile = args[1];
  const out = flag('out');
  if (!specFile || !out || specFile.startsWith('--')) {
    process.stderr.write('usage: node design.mjs compose spec.json --out designs/<size>.html [--size <preset>]\n');
    process.exit(2);
  }
  let spec;
  try { spec = JSON.parse(await readFile(resolve(specFile), 'utf8')); } catch (e) { process.stderr.write('spec.json: ' + (e && e.message || e) + '\n'); process.exit(2); }
  await applyMove(spec);
  const sizeId = flag('size') || spec.size || 'ig-square';
  const sizePresets = { 'ig-square': [1080, 1080], 'ig-portrait': [1080, 1350], 'ig-story': [1080, 1920], 'whatsapp-status': [1080, 1920], 'x-post': [1600, 900], linkedin: [1200, 627], 'fb-cover': [1640, 624], 'youtube-thumb': [1280, 720], og: [1200, 630], 'x-header': [1500, 500], 'linkedin-cover': [1584, 396], poster: [1240, 1754] };
  const [w, h] = sizePresets[sizeId] || [spec.width || 1080, spec.height || 1080];
  const html = composeHTML(spec, sizeId, w, h, await loadTextStyles());
  const target = resolve(out);
  await mkdir(dirname(target), { recursive: true });
  await writeFile(target, html);
  process.stdout.write(target + ' (' + html.length + ' bytes) - now: node design.mjs render ' + out + '\n');
}

// applyMove: spec.move names the designer move VARIETY asked for; the
// composer carries it out so it never depends on the agent remembering -
// a cut-out is produced from the photo (trimmed to the subject), stickers
// / echo become effects, loud becomes the scale.
async function applyMove(spec) {
  const move = String(spec.move || '').trim();
  if (!move) return;
  spec.effects = Array.isArray(spec.effects) ? spec.effects : [];
  if (move === 'stickers' || move === 'echo') { if (!spec.effects.includes(move)) spec.effects.push(move); return; }
  if (move === 'loud') { spec.scale = 'loud'; return; }
  if (move !== 'cutout' || !spec.photo || !spec.photo.file || spec.photo.cutout) return;
  const { py: IMAGE_PY, tool: IMAGE_TOOL } = imageEnv();
  if (!existsSync(IMAGE_PY) || !existsSync(IMAGE_TOOL) || !existsSync(spec.photo.file)) return;
  const out = 'assets/hero-cut.png';
  const model = spec.photo.subject === 'person' || spec.photo.subject === 'people' ? 'u2net_human_seg' : 'isnet-general-use';
  const { spawnSync } = await import('node:child_process');
  const r = spawnSync(IMAGE_PY, [IMAGE_TOOL, 'cutout', spec.photo.file, '--out', out, '--model', model, '--trim'], { encoding: 'utf8', timeout: 120000, env: Object.assign({}, process.env, { OMP_NUM_THREADS: '4' }) });
  if (r.status === 0 && existsSync(out)) { spec.photo.cutout = out; process.stdout.write('move cutout: the subject is cut out (' + out + ')\n'); }
  else process.stdout.write('move cutout: no clean subject in this photo - composed without it\n');
}

// --- colour helpers ---------------------------------------------------------
function hexRgb(hex) {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(hex || '').trim());
  if (!m) return null;
  let s = m[1]; if (s.length === 3) s = s.split('').map((c) => c + c).join('');
  return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)];
}
function rgbHex(rgb) { return '#' + rgb.map((v) => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join(''); }
function lum(rgb) { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]); }
function ratio(a, b) { const la = lum(a), lb = lum(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); }
function mix(a, b, t) { return a.map((v, i) => v + (b[i] - v) * t); }
function escapeHTML(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

// --- the composer ------------------------------------------------------------
// the shared text-style library (seed + learned) - the same definitions
// the photo tools render with Pillow; here they become CSS
async function loadTextStyles() {
  const out = {};
  for (const name of ['seed.json', 'learned.json']) {
    try {
      const doc = JSON.parse(await readFile('/opt/uttra-design-render/styles/' + name, 'utf8'));
      for (const s of doc.styles || []) if (s && s.id) out[s.id] = s;
    } catch {}
  }
  return out;
}
function textStyleCSS(style) {
  if (!style || typeof style !== 'object') return '';
  const tok = (c) => { const v = String(c || '').trim(); return /^(ink|bg|accent|accent2|muted)$/.test(v) ? `var(--c-${v.replace('accent2', 'accent-2')})` : v; };
  const rgba = (c, o) => `color-mix(in srgb, ${tok(c)} ${Math.round((o == null ? 1 : o) * 100)}%, transparent)`;
  let css = '';
  const fill = style.fill || { type: 'solid', color: 'ink' };
  const gradient = fill.type === 'linear';
  if (gradient) css += `background:linear-gradient(${fill.angle == null ? 90 : fill.angle}deg, ${(fill.stops || ['ink', 'accent']).map(tok).join(', ')});-webkit-background-clip:text;background-clip:text;color:transparent;`;
  else if (fill.type === 'none') css += 'color:transparent;';
  else css += `color:${tok(fill.color || 'ink')};`;
  if (style.stroke) css += `-webkit-text-stroke:${(style.stroke.width || 0.04)}em ${tok(style.stroke.color || 'ink')};paint-order:stroke fill;`;
  const shadows = [], filters = [];
  if (style.glow) { const g = style.glow; shadows.push(`0 0 ${g.blur || 0.3}em ${rgba(g.color || 'accent', g.opacity == null ? 0.9 : g.opacity)}`, `0 0 ${(g.blur || 0.3) * 2}em ${rgba(g.color || 'accent', (g.opacity == null ? 0.9 : g.opacity) * 0.6)}`); }
  if (style.shadow) { const s = style.shadow; shadows.push(`${s.dx || 0}em ${s.dy || 0}em ${s.blur || 0}em ${rgba(s.color || '#000', s.opacity == null ? 0.6 : s.opacity)}`); }
  if (style.extrude) { const e = style.extrude; const depth = Math.max(1, Math.round((e.depth || 0.15) * 40)); const nx = e.dx == null ? 1 : e.dx, ny = e.dy == null ? 1 : e.dy, n = Math.hypot(nx, ny) || 1; for (let i = 1; i <= depth; i++) shadows.push(`${((i / 40) * nx / n).toFixed(3)}em ${((i / 40) * ny / n).toFixed(3)}em 0 ${rgba(e.color || '#000', e.opacity == null ? 1 : e.opacity)}`); }
  if (shadows.length) { if (gradient) filters.push(...shadows.map((s) => `drop-shadow(${s})`)); else css += `text-shadow:${shadows.join(',')};`; }
  if (filters.length) css += `filter:${filters.join(' ')};`;
  if (style.letterSpacing) css += `letter-spacing:${style.letterSpacing}em;`;
  if (style.transform === 'uppercase') css += 'text-transform:uppercase;';
  if (style.box) {
    const b = style.box;
    if (b.shape === 'underline') css += `background:linear-gradient(${tok(b.fill || 'accent2')}, ${tok(b.fill || 'accent2')}) no-repeat left ${(1 - (b.thickness || 0.25) / 2).toFixed(2)}em / 100% ${b.thickness || 0.25}em;`;
    else css += `display:inline-block;background:${rgba(b.fill || 'accent', b.opacity)};padding:${b.paddingY == null ? 0.15 : b.paddingY}em ${b.paddingX == null ? 0.4 : b.paddingX}em;border-radius:${b.shape === 'pill' ? '999px' : (b.radius == null ? 0.08 : b.radius) + 'em'};line-height:1.15;`;
  }
  return css;
}
function composeHTML(spec, sizeId, w, h, textStyles) {
  const tstyle = spec.textStyle ? (typeof spec.textStyle === 'string' ? (textStyles || {})[spec.textStyle] : spec.textStyle) : null;
  const headStyleCSS = textStyleCSS(tstyle);
  const copy = spec.copy || {};
  // a logo page needs no headline: the brand name is the headline
  const headlineRaw = String(copy.headline || (spec.layout === 'logo' ? (copy.brand || (spec.logo && spec.logo.name) || '') : '')).trim();
  if (!headlineRaw) throw new Error('copy.headline is required (a logo page: copy.brand)');
  const rtl = /[؀-ۿ]/.test(headlineRaw + (copy.subline || ''));
  const aspect = w / h;
  const shape = aspect > 1.4 ? 'wide' : aspect < 0.8 ? 'tall' : 'square';
  const story = aspect < 0.62;
  const loud = spec.scale === 'loud';

  // palette with contrast guarantees
  const P = Object.assign({ bg: '#F6F7F9', bg2: '#E9EEF2', ink: '#12171C', ink2: '#3D4852', accent: '#1E9E8A', accent2: '#C9A75A', muted: '#8A96A0' }, spec.palette || {});
  const bg = hexRgb(P.bg) || [246, 247, 249];
  const dark = lum(bg) < 0.35;
  let ink = hexRgb(P.ink) || (dark ? [245, 247, 249] : [18, 23, 28]);
  if (ratio(ink, bg) < 7) ink = dark ? [245, 247, 249] : [18, 23, 28];
  let ink2 = hexRgb(P.ink2) || mix(ink, bg, 0.3);
  if (ratio(ink2, bg) < 4.5) ink2 = mix(ink, bg, 0.25);
  let accent = hexRgb(P.accent) || [30, 158, 138];
  // the accent carries button/pill text in the bg colour: 4.5:1 or darker
  for (let i = 0; i < 12 && ratio(accent, bg) < 4.5; i++) accent = mix(accent, dark ? [255, 255, 255] : [0, 0, 0], 0.12);
  let muted = hexRgb(P.muted) || mix(ink, bg, 0.55);
  if (ratio(muted, bg) < 4.5) muted = mix(ink, bg, 0.45);
  const accent2 = hexRgb(P.accent2) || [201, 167, 90];
  const bg2 = hexRgb(P.bg2) || mix(bg, accent, 0.12);
  if (ratio(muted, bg2) < 4.5 || ratio(muted, bg) < 5) muted = mix(ink, bg, 0.35);
  const btnText = ratio(accent, bg) >= 3 ? rgbHex(bg) : rgbHex(ink);
  const F = Object.assign({ display: 'Cairo', body: 'Cairo', latin: 'Plus Jakarta Sans' }, spec.fonts || {});
  // the logo face: modern and geometric by default (owner 2026-09-14), the
  // brand's own face when the request names one (spec.logo.font)
  // Arabic names in Alexandria (the owner's pick - modern, geometric,
  // designed for Arabic), Latin names in a modern Latin sans; Readex Pro
  // never carries Arabic words (owner 2026-09-14)
  const brandName = String((spec.logo && typeof spec.logo === 'object' && spec.logo.name) || copy.brand || '');
  let logoFont = (spec.logo && typeof spec.logo === 'object' && spec.logo.font) ? String(spec.logo.font).replace(/'/g, '') : (/[؀-ۿ]/.test(brandName) ? 'Alexandria' : 'Plus Jakarta Sans');
  if (/[؀-ۿ]/.test(brandName) && /^readex pro$/i.test(logoFont)) logoFont = 'Alexandria';
  const tokens = `--w:${w}px;--h:${h}px;--c-bg:${rgbHex(bg)};--c-bg-2:${rgbHex(bg2)};--c-ink:${rgbHex(ink)};--c-ink-2:${rgbHex(ink2)};--c-accent:${rgbHex(accent)};--c-accent-2:${rgbHex(accent2)};--c-muted:${rgbHex(muted)};--f-display:'${F.display}';--f-body:'${F.body}';--f-latin:'${F.latin}';--f-logo:'${logoFont}'`;

  // layout + photo
  const photo = spec.photo && spec.photo.file ? Object.assign({ side: rtl ? 'left' : 'right', focus: '50% 28%', treatment: '' }, spec.photo) : null;
  let layout = spec.layout || (photo ? 'fade' : 'type');
  if (!photo && !['type', 'product', 'logo'].includes(layout)) layout = 'type';
  const photoSrc = photo ? (photo.file.startsWith('assets/') ? '../' + photo.file : photo.file) : '';
  const effects = new Set(Array.isArray(spec.effects) && spec.effects.length ? spec.effects : (layout === 'poster' ? ['grain', 'vignette'] : ['mesh', 'grain', 'ring']));

  // copy pieces
  const lines = headlineRaw.includes('|') ? headlineRaw.split('|').map((s) => s.trim()).filter(Boolean) : autoSplit(headlineRaw, layout === 'type' && headlineRaw.length > 16 ? 3 : 0);
  const accentWord = String(copy.accentWord || '').trim();
  const wordClass = effects.has('gradient-word') ? 'text-gradient' : (effects.has('highlight-word') && !/[0-9%]/.test(accentWord)) ? 'highlight' : 'accent';
  const headHTML = lines.map((l) => {
    let t = escapeHTML(l);
    if (accentWord && l.includes(accentWord)) t = t.replace(escapeHTML(accentWord), `<span class="${wordClass}">${escapeHTML(accentWord)}</span>`);
    return t;
  }).join('<br>');
  const longest = Math.max(...lines.map((l) => l.length));
  const logoSpec = typeof spec.logo === 'string' ? { file: spec.logo } : (spec.logo || {});
  const brandHTML = logoLockup(logoSpec, copy.brand, { big: false, btnText });
  const eyebrowHTML = copy.eyebrow ? `<div class="eyebrow">${escapeHTML(copy.eyebrow)}</div>` : '';
  const sublineHTML = copy.subline ? `<p class="lead" style="max-width:${shape === 'wide' ? 34 : 26}ch;font-size:calc(var(--u) * ${loud || shape === 'square' ? 3.4 : 3})">${escapeHTML(copy.subline)}</p>` : '';
  const facts = Array.isArray(copy.facts) ? copy.facts.filter(Boolean).slice(0, 3) : [];
  // stickers: the facts pinned on the photo as tilted stickers instead of a pill row
  const stickersOn = effects.has('stickers') && facts.length > 0;
  const factsHTML = facts.length && !stickersOn ? `<div class="row gap-2" style="flex-wrap:wrap">${facts.slice(0, 2).map((f) => `<span class="tag">${escapeHTML(f)}</span>`).join('')}</div>` : '';
  const detailHTML = copy.detail ? `<span class="caption">${escapeHTML(copy.detail)}</span>` : '';
  const ctaHTML = copy.cta ? `<a class="btn${effects.has('glow') ? ' glow' : ''}" style="color:${btnText}">${escapeHTML(copy.cta)}</a>` : '';

  // headline size from the column it lives in (u = 1% of width)
  const colU = { split: shape === 'wide' ? 50 : 44, fade: shape === 'tall' ? 84 : (shape === 'wide' ? 42 : 50), bleed: shape === 'wide' ? 60 : 78, poster: shape === 'wide' ? 62 : 86, top: 84, circle: shape === 'tall' ? 84 : 48, diagonal: shape === 'wide' ? 48 : 52, frame: shape === 'wide' ? 50 : 46, card: shape === 'wide' ? 40 : 56, bignumber: shape === 'wide' ? 52 : 50, magazine: shape === 'wide' ? 84 : 88, type: 84, product: 80 }[layout] || 50;
  // owner 2026-09-15: the type ran small - an Arabic line averages ~0.6em
  // per character (joined letters), not 0.92; the caps go up too
  const maxU = (shape === 'wide' ? 9 : story ? 11 : 12) + (loud ? 3 : 0) + (layout === 'magazine' ? 1.5 : 0);
  const charW = rtl ? 0.74 : 0.62;
  const headU = Math.max(5, Math.min(maxU, colU / (Math.max(5, longest) * (loud ? charW * 0.92 : charW))));
  // a big headline sets tighter (the check exempts type over 60px); small Arabic keeps 1.3
  const headLH = headU * w / 100 >= 64 ? (rtl ? 1.18 : 1.05) : 1.3;
  const h1 = `<h1 class="display" style="font-size:calc(var(--u) * ${headU.toFixed(2)});line-height:${headLH};text-wrap:wrap;color:var(--c-ink);${headStyleCSS}">${headHTML}</h1>`;
  const textBlock = `<div class="stack" style="gap:calc(var(--u) * ${loud ? 1.6 : 2.2})">${eyebrowHTML}${h1}${sublineHTML}</div>`;
  const actionBlock = `<div class="stack" style="gap:calc(var(--u) * 2)">${factsHTML}<div class="row items-center gap-3" style="flex-wrap:wrap">${ctaHTML}${detailHTML}</div></div>`;
  const brandRow = `<div class="row items-center">${brandHTML}</div>`;

  // background + finish layers
  const bgLayers = [];
  if (effects.has('mesh')) bgLayers.push('<div class="layer z0 g-mesh"></div>');
  else if (effects.has('radial')) bgLayers.push('<div class="layer z0 g-radial"></div>');
  else if (effects.has('linear')) bgLayers.push('<div class="layer z0 g-linear"></div>');
  else bgLayers.push('<div class="layer z0 bg"></div>');
  // echo: the photo's own colours, blurred wide, under everything
  if (effects.has('echo') && spec.photo && spec.photo.file) bgLayers.push(`<div class="layer z0" style="background:url(${spec.photo.file.startsWith('assets/') ? '../' + spec.photo.file : spec.photo.file}) 50% 40% / cover no-repeat;filter:blur(calc(var(--u) * 5)) saturate(1.35);opacity:.3;transform:scale(1.18)"></div>`);
  if (effects.has('dots')) bgLayers.push('<div class="layer z1 g-dots" style="opacity:.16"></div>');
  if (effects.has('grid')) bgLayers.push('<div class="layer z1 g-grid" style="opacity:.1"></div>');
  if (effects.has('stripes')) bgLayers.push('<div class="layer z1 g-stripes" style="opacity:.1"></div>');
  if (effects.has('spot')) bgLayers.push(`<div class="layer z1 g-spot" style="--cx:${photo && photo.side === 'left' ? '25%' : '75%'};--cy:35%;opacity:.5"></div>`);
  const photoLeft = photo && photo.side === 'left';
  if (effects.has('blob') && photo) bgLayers.push(`<div class="blur-blob z1" style="width:48%;height:48%;${photoLeft ? 'left:-10%' : 'right:-10%'};top:12%;opacity:.35"></div>`);
  if (effects.has('ring')) bgLayers.push(`<div class="ring z1" style="width:34%;aspect-ratio:1;${photoLeft ? 'right:-8%' : 'left:-8%'};top:-10%;opacity:.6"></div>`);
  const finish = [];
  if (effects.has('grain')) finish.push('<div class="layer z5 grain" style="opacity:.3"></div>');
  if (effects.has('vignette')) finish.push('<div class="layer z5 vignette"></div>');

  const img = photo ? `<img src="${photoSrc}" data-slot="hero" alt="" style="--focus:${photo.focus}">` : '';
  const treatment = photo ? (photo.treatment || { split: 'rounded', fade: 'fade', bleed: 'none', poster: 'none', top: 'fade-b', circle: 'circle' }[layout] || 'none') : '';
  const shapeClass = { rounded: 'clip-arch-full shadow-xl', arch: 'clip-arch-full shadow-xl', circle: 'clip-circle shadow-xl', none: '', fade: '', 'fade-b': 'mask-fade-b' }[treatment] || '';
  const sideCSS = (pct) => photoLeft ? `left:0;width:${pct}%` : `right:0;width:${pct}%`;
  const colCSS = (pct) => photoLeft ? `right:var(--pad);width:calc(${pct}% - var(--pad) * 2)` : `left:var(--pad);width:calc(${pct}% - var(--pad) * 2)`;
  const fadeClass = photoLeft ? 'mask-fade-end' : 'mask-fade-start';

  // the cut-out subject: contained, standing on the bottom edge of its block,
  // a soft blob behind it, spilling a little toward the words (the headline
  // runs behind it - the check accepts this marked overlap)
  const cutSrc = photo && photo.cutout ? (String(photo.cutout).startsWith('assets/') ? '../' + photo.cutout : photo.cutout) : '';
  const subject = cutSrc ? `<div class="abs" style="inset:0;overflow:visible"><div class="blur-blob" style="width:72%;height:56%;left:14%;bottom:6%;opacity:.5"></div><div class="ring" style="width:64%;aspect-ratio:1;left:18%;top:6%;opacity:.5"></div><img src="${cutSrc}" data-slot="hero" data-art="overlap" alt="" style="position:absolute;left:0;bottom:0;width:100%;height:100%;object-fit:contain;object-position:bottom center;filter:drop-shadow(0 calc(var(--u) * 2.5) calc(var(--u) * 5) rgba(0,0,0,.35));transform:scale(1.12) translateX(${photoLeft ? '7%' : '-7%'});transform-origin:bottom center"></div>` : '';
  const stickers = (spots) => stickersOn ? facts.slice(0, 2).map((f, i) => { const sp = spots[i] || spots[0]; return `<span class="sticker abs z4" style="--rot:${i ? 5 : -6}deg;font-size:calc(var(--u) * 2.6);${photoLeft ? 'left' : 'right'}:${sp[0]}%;top:${sp[1]}%;background:var(--c-accent${i ? '' : '-2'});color:${i ? btnText : 'var(--c-ink)'}">${escapeHTML(f)}</span>`; }).join('') : '';
  const cornerDecor = `<div class="blur-blob z1" style="width:34%;height:34%;${photoLeft ? 'left:-8%' : 'right:-8%'};top:-6%;opacity:.28;background:var(--c-accent-2)"></div><div class="ring z1" style="width:26%;aspect-ratio:1;${photoLeft ? 'left:10%' : 'right:10%'};top:6%;opacity:.5"></div><div class="layer z1 g-dots" style="opacity:.12;clip-path:inset(0 ${photoLeft ? '55% 55% 0' : '0 55% 55%'})"></div>`;
  let body = '';
  const column = (extra) => `<div class="stack" style="justify-content:space-between;min-width:0;height:100%;${extra || ''}">${brandRow}${textBlock}${actionBlock}</div>`;
  if (layout === 'split' && photo && shape !== 'tall') {
    const pct = shape === 'wide' ? 44 : 50;
    const photoEl = subject ? `<div style="position:relative;height:100%">${subject}</div>` : `<div class="photo ${shapeClass}" style="height:100%;position:relative">${img}</div>`;
    const kids = photoLeft ? [photoEl, column()] : [column(), photoEl];
    body = `<div class="layer z2 safe" style="display:grid;grid-template-columns:${photoLeft ? pct + 'fr ' + (100 - pct) + 'fr' : (100 - pct) + 'fr ' + pct + 'fr'};gap:calc(var(--u) * 4);direction:ltr"><div style="direction:${rtl ? 'rtl' : 'ltr'};min-width:0;height:100%${subject && photoLeft ? ';z-index:3' : ''}">${kids[0]}</div><div style="direction:${rtl ? 'rtl' : 'ltr'};min-width:0;height:100%${subject && !photoLeft ? ';z-index:3' : ''}">${kids[1]}</div></div>` + stickers([[8, 12], [30, 70]]);
  } else if (layout === 'fade' && photo && shape !== 'tall') {
    const pct = shape === 'wide' ? 50 : 56;
    body = (subject ? cornerDecor + `<div class="z2" style="position:absolute;top:calc(var(--pad) * 1.5);bottom:0;${sideCSS(pct - 2)}">${subject}</div>` : `<div class="photo z1 ${fadeClass}" style="position:absolute;top:0;bottom:0;${sideCSS(pct)}">${img}</div>`) +
      `<div class="abs z3 stack" style="top:var(--pad);bottom:var(--pad);${colCSS(shape === 'wide' ? 54 : 62)};justify-content:space-between">${brandRow}${textBlock}${actionBlock}</div>`;
  } else if (layout === 'circle' && photo && shape !== 'tall') {
    body = cornerDecor + `<div class="ring z1" style="width:74%;aspect-ratio:1;${photoLeft ? 'left:-14%' : 'right:-14%'};bottom:-16%;opacity:.35"></div>` +
      `<div class="photo clip-circle shadow-xl z2" style="position:absolute;width:${shape === 'wide' ? 46 : 72}%;aspect-ratio:1;${photoLeft ? 'left:-8%' : 'right:-8%'};bottom:-6%">${img}</div>` +
      `<div class="abs z3 stack" style="top:var(--pad);bottom:var(--pad);${colCSS(50)};justify-content:space-between">${brandRow}${textBlock}${actionBlock}</div>`;
  } else if (layout === 'bleed' && photo) {
    body = `<div class="photo fill z0">${img}</div><div class="layer z1 g-scrim-b"></div>` +
      `<div class="layer z2 safe stack" style="justify-content:space-between"><div class="row between items-center">${brandHTML}${detailHTML}</div><div class="stack" style="gap:calc(var(--u) * 2.4);max-width:${shape === 'wide' ? 60 : 78}%">${eyebrowHTML}${h1}${sublineHTML}${factsHTML}<div class="row items-center gap-3">${ctaHTML}</div></div></div>`;
  } else if (layout === 'poster' && photo) {
    // the cinematic poster (music, film, events, street brands): the photo
    // fills the canvas in its natural colours, graded dark toward the bottom
    // and the edges - no scrim band; the title is the artwork: huge, in the
    // display face, in the accent, low on the canvas; small marks in the
    // corners (brand at the start, partner / date at the end)
    const posterU = Math.max(8, Math.min(shape === 'wide' ? 12 : 18, (shape === 'wide' ? 62 : 92) / (Math.max(4, longest) * 0.66)));
    const title = `<h1 class="display" style="font-size:calc(var(--u) * ${posterU.toFixed(2)});line-height:1.12;text-wrap:balance;color:var(--c-accent);text-shadow:0 calc(var(--u) * 0.5) calc(var(--u) * 2.4) color-mix(in srgb, var(--c-bg) 75%, transparent);${headStyleCSS}">${headHTML}</h1>`;
    body = `<div class="photo fill z0">${img}</div>` +
      `<div class="layer z1" style="background:linear-gradient(to top, color-mix(in srgb, var(--c-bg) 97%, transparent) 0%, color-mix(in srgb, var(--c-bg) 74%, transparent) 30%, color-mix(in srgb, var(--c-bg) 22%, transparent) 56%, transparent 80%)"></div>` +
      `<div class="layer z1" style="background:radial-gradient(ellipse 72% 62% at 50% 32%, transparent 0%, color-mix(in srgb, var(--c-bg) 48%, transparent) 100%)"></div>` +
      `<div class="layer z2 safe stack" style="justify-content:space-between"><div class="row between items-start">${brandHTML}${detailHTML}</div><div class="stack" style="gap:calc(var(--u) * 1.6);max-width:${shape === 'wide' ? 64 : 90}%">${eyebrowHTML}${title}${sublineHTML}${factsHTML}${ctaHTML ? `<div class="row items-center gap-3" style="flex-wrap:wrap">${ctaHTML}</div>` : ''}</div></div>`;
  } else if ((layout === 'top' || shape === 'tall') && photo) {
    const top = story ? 56 : 54;
    body = `<div class="photo mask-fade-b z1" style="position:absolute;left:0;right:0;top:0;height:${top}%">${img}</div>` +
      `<div class="abs z3" style="top:${story ? 'calc(var(--h) * 0.06)' : 'var(--pad)'};${rtl ? 'right' : 'left'}:var(--pad)">${brandHTML}</div>` +
      `<div class="abs z3 stack" style="left:var(--pad);right:var(--pad);top:${top + 2}%;bottom:${story ? 'calc(var(--h) * 0.08)' : 'var(--pad)'};justify-content:space-between">${textBlock}${actionBlock}</div>`;
  } else if (layout === 'diagonal' && photo && shape !== 'tall') {
    // the photo fills the start side behind a slanted edge (wide at the top,
    // narrow at the bottom) with an accent stripe along the cut; the brand
    // small in the free top corner, the words low where the photo is narrow
    const poly = photoLeft ? 'polygon(0 0, 60% 0, 42% 100%, 0 100%)' : 'polygon(40% 0, 100% 0, 100% 100%, 58% 100%)';
    const poly2 = photoLeft ? 'polygon(0 0, 62.5% 0, 44.5% 100%, 0 100%)' : 'polygon(37.5% 0, 100% 0, 100% 100%, 55.5% 100%)';
    body = `<div class="layer z1" style="background:var(--c-accent);clip-path:${poly2}"></div>` +
      `<div class="photo z1" style="position:absolute;inset:0;clip-path:${poly}">${img}</div>` +
      `<div class="abs z3" style="top:var(--pad);${photoLeft ? 'right:var(--pad)' : 'left:var(--pad)'}">${brandHTML}</div>` +
      `<div class="abs z3 stack" style="bottom:var(--pad);top:34%;${photoLeft ? 'right:var(--pad);left:47%' : 'left:var(--pad);right:47%'};justify-content:flex-end;gap:calc(var(--u) * 3)">${textBlock}${actionBlock}</div>` + stickers([[6, 10], [26, 62]]);
  } else if (layout === 'frame' && photo && shape !== 'tall') {
    // the photo in a thick frame of the page colour, an accent-2 block
    // offset behind it (the print designer's drop block); words on the
    // other side; stickers may sit on the frame's corner
    const fw = shape === 'wide' ? 40 : 46, fh = shape === 'wide' ? 80 : 66;
    const x = photoLeft ? 'left:var(--pad)' : 'right:var(--pad)';
    body = `<div class="abs z1" style="${x};top:var(--pad);width:${fw}%;height:${fh}%;transform:translate(${photoLeft ? '' : '-'}calc(var(--u) * 3.5), calc(var(--u) * 3.5));background:var(--c-accent-2);border-radius:calc(var(--radius) * 1.2)"></div>` +
      `<div class="photo z2 shadow-xl" style="position:absolute;${x};top:var(--pad);width:${fw}%;height:${fh}%;border:calc(var(--u) * 1.3) solid var(--c-bg);border-radius:calc(var(--radius) * 1.2);overflow:hidden">${img}</div>` +
      `<div class="abs z3 stack" style="top:var(--pad);bottom:var(--pad);${colCSS(shape === 'wide' ? 52 : 50)};justify-content:space-between">${brandRow}${textBlock}${actionBlock}</div>` + stickers([[fw - 14, 4], [4, fh - 6]]);
  } else if (layout === 'card' && photo) {
    // the photo fills the canvas; the words on a tilted card in the lower
    // start corner with a real shadow; the brand in a glass pill up top
    const cw = shape === 'wide' ? 44 : story ? 84 : 64;
    body = `<div class="photo fill z0">${img}</div><div class="layer z1" style="background:linear-gradient(to top, color-mix(in srgb, var(--c-ink) 38%, transparent), transparent 62%)"></div>` +
      `<div class="abs z3" style="top:${story ? 'calc(var(--h) * 0.07)' : 'var(--pad)'};${rtl ? 'right' : 'left'}:var(--pad)"><span class="glass-dark" style="display:inline-flex;padding:calc(var(--u) * 1.4) calc(var(--u) * 2.4);border-radius:999px">${brandHTML}</span></div>` +
      `<div class="abs z3 stack" style="bottom:${story ? 'calc(var(--h) * 0.09)' : 'var(--pad)'};${rtl ? 'right' : 'left'}:var(--pad);width:${cw}%;padding:calc(var(--u) * 4);border-radius:calc(var(--radius) * 1.6);background:var(--c-bg);box-shadow:0 calc(var(--u) * 3) calc(var(--u) * 8) rgba(0,0,0,.32);transform:rotate(-1.5deg);gap:calc(var(--u) * 2.6)">${eyebrowHTML}${h1}${sublineHTML}<div class="row items-center gap-3" style="flex-wrap:wrap;padding-top:calc(var(--u) * 0.8)">${ctaHTML}${detailHTML}</div></div>` + stickers([[56, 10], [8, 28]]);
  } else if (layout === 'bignumber' && photo && shape !== 'tall') {
    // the offer's number IS the design: huge in the accent, the headline
    // small under it, the photo in an arch off the far corner
    const number = String(copy.number || (headlineRaw.match(/\d+\s*%|\d+/) || [''])[0]).trim();
    const numU = Math.min(shape === 'wide' ? 24 : 32, (shape === 'wide' ? 40 : 52) / Math.max(2, number.length) * 1.6);
    const numHTML = number ? `<div class="display num" style="font-size:calc(var(--u) * ${numU.toFixed(1)});line-height:0.95;color:var(--c-accent);letter-spacing:-0.04em;${headStyleCSS}">${escapeHTML(number)}</div>` : '';
    const smallH1 = `<h1 class="display" style="font-size:calc(var(--u) * ${Math.min(headU, 6.4).toFixed(2)});line-height:1.3;color:var(--c-ink)">${headHTML}</h1>`;
    body = cornerDecor + `<div class="ring z1" style="width:70%;aspect-ratio:1;${photoLeft ? 'left:-18%' : 'right:-18%'};bottom:-20%;opacity:.35"></div>` +
      `<div class="photo clip-arch-full shadow-xl z1" style="position:absolute;${photoLeft ? 'left' : 'right'}:0;bottom:0;width:${shape === 'wide' ? 34 : 48}%;height:${shape === 'wide' ? 96 : 84}%">${img}</div>` +
      `<div class="abs z3 stack" style="top:var(--pad);bottom:var(--pad);${colCSS(shape === 'wide' ? 60 : 56)};justify-content:space-between">${brandRow}<div class="stack" style="gap:calc(var(--u) * 1.2)">${eyebrowHTML}${numHTML}${smallH1}${sublineHTML}</div>${actionBlock}</div>` + stickers([[6, 40], [30, 24]]);
  } else if (layout === 'magazine' && photo && shape !== 'tall') {
    // the editorial page: a rule under the masthead row, the headline
    // across the full width, the photo in a rounded plate below with the
    // subline and the action beside/under it
    const mast = `<div class="row between items-center" style="padding-bottom:calc(var(--u) * 2);border-bottom:calc(var(--u) * 0.32) solid var(--c-ink)">${brandHTML}${detailHTML || (copy.eyebrow ? `<span class="kicker">${escapeHTML(copy.eyebrow)}</span>` : '')}</div>`;
    const lower = shape === 'wide'
      ? `<div style="display:grid;grid-template-columns:1.25fr 1fr;gap:calc(var(--u) * 3);flex:1;min-height:0"><div class="photo shadow" style="position:relative;border-radius:var(--radius);overflow:hidden">${img}</div><div class="stack" style="justify-content:space-between">${sublineHTML}${actionBlock}</div></div>`
      : `<div class="photo shadow" style="position:relative;flex:1;min-height:0;border-radius:var(--radius);overflow:hidden">${img}</div><div class="row between items-end" style="gap:calc(var(--u) * 3)">${sublineHTML}${ctaHTML}</div>`;
    body = `<div class="layer z2 safe stack" style="gap:calc(var(--u) * 3)">${mast}<div class="stack" style="gap:calc(var(--u) * 1.2)">${copy.eyebrow && detailHTML ? eyebrowHTML : ''}${h1}</div>${lower}</div>` + stickers([[8, 60], [60, 56]]);
  } else if (layout === 'product' && photo) {
    body = `<div class="layer z2 safe stack" style="justify-content:space-between;align-items:center;text-align:center"><div class="stack items-center" style="gap:calc(var(--u) * 1.6)">${brandHTML}${eyebrowHTML}${h1}</div><div class="photo rel${effects.has('glow') ? ' glow' : ''}" style="width:${shape === 'wide' ? 30 : 55}%;aspect-ratio:1;border-radius:50%"><img src="${photoSrc}" data-slot="hero" alt="" style="object-fit:contain"></div><div class="stack items-center" style="gap:calc(var(--u) * 2)">${sublineHTML}${factsHTML}${ctaHTML}</div></div>`;
  } else if (layout === 'logo') {
    // a logo presentation: the lockup large in the middle, tagline under it,
    // the brand colours as a quiet decor
    // (a brand board: the lockup large in the middle, the mark alone in
    // three tiles along the bottom - on the tint, on the accent, on the ink -
    // the way a designer presents a mark, and the canvas never sits empty)
    const big = logoLockup(logoSpec, copy.brand || lines.join(' '), { big: true, btnText });
    const mk = logoSpec.mark ? (typeof logoSpec.mark === 'string' ? { shape: logoSpec.mark } : logoSpec.mark) : null;
    const tileU = shape === 'wide' ? 11 : 14;
    const tile = (bgc, inner) => `<span style="display:inline-grid;place-items:center;width:calc(var(--u) * ${tileU});height:calc(var(--u) * ${tileU});border-radius:calc(var(--u) * 3);background:${bgc}">${inner}</span>`;
    const mkSize = `calc(var(--u) * ${(tileU * 0.56).toFixed(1)})`;
    const board = mk ? `<div class="row items-center" style="gap:calc(var(--u) * 3)">${tile('var(--c-bg-2)', markSVG(mk, mkSize, btnText))}${tile('var(--c-accent)', markSVG(Object.assign({}, mk, { mono: btnText }), mkSize, 'var(--c-accent)'))}${tile('var(--c-ink)', markSVG(Object.assign({}, mk, { mono: 'var(--c-bg)' }), mkSize, 'var(--c-ink)'))}</div>` : '';
    body = `<div class="ring z1" style="width:70%;aspect-ratio:1;left:50%;top:44%;transform:translate(-50%,-50%);opacity:.25"></div>` +
      `<div class="layer z2 safe stack" style="justify-content:space-between;align-items:center;text-align:center"><span></span><div class="stack items-center" style="gap:calc(var(--u) * 4)">${big}${copy.subline ? `<p class="lead" style="max-width:34ch">${escapeHTML(copy.subline)}</p>` : ''}${detailHTML}</div>${board || '<span></span>'}</div>`;
  } else {
    // type-led (no photo): the headline is the image - three short lines
    // set big; the brand's own mark as a large quiet decor on the empty side
    // (the ghost word when there is no mark); the CTA right under the copy,
    // never alone in a far corner
    const ghost = copy.accentWord || lines[0];
    const mk = logoSpec.mark ? (typeof logoSpec.mark === 'string' ? { shape: logoSpec.mark } : logoSpec.mark) : null;
    const decorSize = `calc(var(--u) * ${shape === 'wide' ? 60 : 78})`;
    const decor = mk
      ? `<div class="g-decor z1" style="position:absolute;${rtl ? 'left' : 'right'}:-20%;bottom:-16%;opacity:.14;transform:rotate(${rtl ? -10 : 10}deg)">${markSVG(Object.assign({}, mk, { letter: '' }), decorSize)}</div>`
      : logoSpec.file
        ? `<div class="g-decor z1" style="position:absolute;${rtl ? 'left' : 'right'}:-12%;bottom:-8%;width:${decorSize};height:${decorSize};opacity:.16;transform:rotate(${rtl ? -8 : 8}deg)"><img src="${logoSpec.file.startsWith('assets/') ? '../' + logoSpec.file : logoSpec.file}" alt="" style="width:100%;height:100%;object-fit:contain"></div>`
        : `<div class="marquee" style="position:absolute;${rtl ? 'left' : 'right'}:2%;top:38%;width:${shape === 'wide' ? 34 : 50}%;text-align:center;overflow:hidden;white-space:nowrap;line-height:1;opacity:.08;font-family:var(--f-display);font-weight:900;font-size:calc(var(--u) * ${Math.min(16, (shape === 'wide' ? 30 : 46) / Math.max(3, ghost.length)).toFixed(1)});animation:none;transform:none">${escapeHTML(ghost)}</div>`;
    body = decor +
      `<div class="layer z2 safe stack" style="justify-content:space-between"><div class="row between items-center">${brandHTML}${detailHTML}</div><div class="stack" style="gap:calc(var(--u) * 3);max-width:${shape === 'wide' ? 66 : 90}%">${eyebrowHTML}${h1}${sublineHTML}<div class="row items-center gap-3" style="flex-wrap:wrap">${ctaHTML}</div></div>${factsHTML || '<span></span>'}</div>`;
  }

  return `<!doctype html><html lang="${rtl ? 'ar' : 'en'}" dir="${rtl ? 'rtl' : 'ltr'}"><head><meta charset="utf-8"><link rel="stylesheet" href="../design-kit.css"><style>.stage .lead{color:var(--c-ink-2)}.stage .caption{color:var(--c-muted)}.stage .eyebrow{color:var(--c-accent)}.stage .photo img{width:100%;height:100%;object-fit:cover;object-position:var(--focus,50% 30%)}.stage .pill.solid{color:${btnText}}</style></head><body><div class="stage s-${sizeId}" data-layout="${layout}" data-composed="uttra" style="${tokens}">${bgLayers.join('')}${body}${finish.join('')}</div></body></html>`;
}

// --- logo lockups --------------------------------------------------------------
// A brand is a designed mark, never a name in a pill. The lockup styles:
//   inline   - icon fused to the name (overlapping its first letter)
//   stacked  - icon above the name, centred
//   badge    - icon inside a filled circle, name beside it
//   monogram - the name's first letter in a rounded tile with the icon tucked
//              in its corner, name beside it
//   wordmark - the name alone, first letter in the accent, icon as the dot
//   fused    - the mark IS the first letter (initial cut into the shape)
// logo = { style, icon: <ICONS key>, name, svg: '<custom inline svg>', file: 'assets/logo.png' }
// (a function, not a const: compose() runs at module top level before a
// const declared further down would be initialised)
function iconPaths() { return {
  tooth: '<path d="M12 5.5c-1.5-1.5-4-2-5.5-.5S5 9 6 11c1 2 1 5 2 8 .5 1 1.5 1 2 0 .5-2 1-3 2-3s1.5 1 2 3c.5 1 1.5 1 2 0 1-3 1-6 2-8s1-4.5-.5-6-4-1-5.5.5z"/>',
  plane: '<path d="M21 12L3 3l4 9-4 9z"/><path d="M7 12h14"/>',
  coffee: '<path d="M4 8h13a3 3 0 0 1 0 6h-1M4 8v6a5 5 0 0 0 5 5h3a5 5 0 0 0 5-5V8M8 3v2M12 3v2"/>',
  home: '<path d="M3 11l9-8 9 8M5 10v10h14V10"/>',
  leaf: '<path d="M20 4c-8 0-14 5-14 13 0 1 0 2 1 3 6 0 13-4 13-16z"/><path d="M6 20c2-5 6-9 10-11"/>',
  scissors: '<circle cx="6" cy="8" r="2"/><circle cx="6" cy="16" r="2"/><path d="M20 4L8 15M20 20L8 9"/>',
  dumbbell: '<path d="M6 8v8M18 8v8M3 10v4M21 10v4M6 12h12"/>',
  cart: '<path d="M3 4h2l2 12h11l2-8H6"/><circle cx="9" cy="19" r="1"/><circle cx="17" cy="19" r="1"/>',
  car: '<path d="M5 16l1-5h12l1 5M3 16h18v3H3zM7 19v2M17 19v2"/>',
  camera: '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13.5" r="3.5"/>',
  book: '<path d="M4 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4zM20 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z"/>',
  heart: '<path d="M12 21s-8-5-8-11a4 4 0 0 1 8-2 4 4 0 0 1 8 2c0 6-8 11-8 11z"/>',
  star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
  chef: '<path d="M7 17h10M7 21h10M8 17v-4M16 17v-4M6 8a3 3 0 0 1 3-4 4 4 0 0 1 6 0 3 3 0 0 1 3 4 3 3 0 0 1-2 5H8a3 3 0 0 1-2-5z"/>',
  wrench: '<path d="M21 7a5 5 0 0 1-7 6L7 20a2 2 0 0 1-3-3l7-7a5 5 0 0 1 6-7l-3 3 1 3 3 1z"/>',
  laptop: '<path d="M4 5h16v11H4zM2 19h20"/>',
  phone: '<path d="M5 3h4l2 5-2 1a11 11 0 0 0 6 6l1-2 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/>',
  pin: '<path d="M12 21s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="3"/>',
  gift: '<path d="M3 9h18v4H3zM5 13h14v8H5zM12 9v12M12 9c-2-4-6-4-6-1s4 1 6 1zM12 9c2-4 6-4 6-1s-4 1-6 1z"/>',
  ring: '<path d="M12 8l2-4h-4z"/><circle cx="12" cy="14" r="6"/>',
  sun: '<circle cx="12" cy="12" r="5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5"/>',
  moon: '<path d="M20 14A8 8 0 1 1 10 4a6 6 0 0 0 10 10z"/>',
  bolt: '<path d="M13 2L4 14h7l-1 8 9-12h-7z"/>',
  shield: '<path d="M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6z"/>',
  sparkle: '<path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/>',
  wave: '<path d="M2 12c3-4 5-4 8 0s5 4 8 0 3-2 4 0"/>',
  graduation: '<path d="M2 9l10-5 10 5-10 5zM6 11v5c3 2 9 2 12 0v-5"/>',
  tag: '<path d="M3 3h8l10 10-8 8L3 11zM7 7h.01"/>',
  building: '<path d="M4 21V4h10v17M14 9h6v12M7 8h2M7 12h2M7 16h2"/>',
  paw: '<path d="M12 20c-3 0-5-2-5-4s2-3 5-3 5 1 5 3-2 4-5 4z"/><circle cx="6" cy="9" r="2"/><circle cx="18" cy="9" r="2"/><circle cx="9" cy="5" r="2"/><circle cx="15" cy="5" r="2"/>',
  pizza: '<path d="M3 21L12 3l9 18z"/><circle cx="12" cy="11" r="1"/><circle cx="9" cy="16" r="1"/><circle cx="14" cy="16" r="1"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M14 9l3 3"/>',
  crown: '<path d="M3 18h18l1-11-6 4-4-7-4 7-6-4z"/>',
  drop: '<path d="M12 3s7 7 7 12a7 7 0 0 1-14 0c0-5 7-12 7-12z"/>',
  flame: '<path d="M12 3c1 4 5 5 5 10a5 5 0 0 1-10 0c0-2 1-3 2-4 0 2 1 3 2 3 0-4-1-6 1-9z"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
  cake: '<path d="M4 20h16v-7H4zM4 13c2-2 4 2 6 0s4-2 6 0 2 2 4 0M12 6v3M12 3v1"/>',
  scale: '<path d="M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
  stethoscope: '<path d="M6 3v6a5 5 0 0 0 10 0V3M11 14v3a4 4 0 0 0 8 0v-2"/><circle cx="19" cy="12" r="2"/>',
  bag: '<path d="M6 8h12l1 13H5zM9 8V6a3 3 0 0 1 6 0v2"/>',
  ticket: '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"/><path d="M12 6v12" stroke-dasharray="2 2"/>',
}; }
function iconSVG(name, sizeCSS, extra) {
  const d = iconPaths()[name];
  if (!d) return '';
  return `<svg viewBox="0 0 24 24" style="width:${sizeCSS};height:${sizeCSS};${extra || ''}" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d}</svg>`;
}
// --- the mark system --------------------------------------------------------
// Our own logo shapes: parametric, filled, gradient-able geometry drawn from
// numbers (never clip-art strokes). Each mark is a 100x100 composition the
// agent tunes: rotate, mirror, gradient, duo (accent + accent2 parts),
// outline, and an initial letter cut into it. Ids:
//   orbit      circle + tilted ring            (travel, tech, logistics)
//   swoosh     two motion crescents           (sport, delivery, speed)
//   shield     rounded shield                 (security, insurance, law)
//   hex        rounded hexagon                (industry, crypto, tech)
//   leaf       two mirrored leaves            (nature, health, organic)
//   drop       teardrop                       (water, beauty, spa, clinic)
//   flame      flame                          (food, grill, energy)
//   spark      four-point star                (beauty, clean, premium)
//   stack      three offset rounded tiles     (apps, finance, software)
//   ring-arrow ring with a gap and arrowhead  (logistics, growth, cycle)
//   rays       sun disc with rays             (energy, solar, morning)
//   chevrons   three layered chevrons         (speed, tech, progress)
//   bubble     speech bubble                  (chat, support, community)
//   cube       isometric cube in three tints  (construction, 3D, tech)
//   tooth      dental silhouette              (dental clinics)
//   plane      paper-plane silhouette         (travel, mail)
//   cup        cup with handle                (cafés)
//   tile       rounded square (with a letter) (any monogram)
//   pin        location pin                   (real estate, places)
//   heart      heart                          (care, charity, love)
function markPaths() { return {
  orbit: [['c', 50, 52, 24], ['e', 50, 52, 46, 15, -24, 'stroke:6']],
  swoosh: [['p', 'M8 64 C28 30 68 20 94 38 C70 34 40 46 24 74 Z'], ['p', 'M20 84 C40 62 70 56 92 66 C74 66 50 74 34 92 Z', 'alt']],
  shield: [['p', 'M50 6 L88 20 V50 C88 74 70 88 50 94 C30 88 12 74 12 50 V20 Z']],
  hex: [['g', 'M50 6 L88 28 V72 L50 94 L12 72 V28 Z']],
  leaf: [['p', 'M50 90 C12 76 12 36 50 10 C46 38 46 62 50 90 Z'], ['p', 'M50 90 C88 76 88 36 50 10 C54 38 54 62 50 90 Z', 'alt']],
  drop: [['p', 'M50 8 C50 8 84 46 84 64 A34 34 0 0 1 16 64 C16 46 50 8 50 8 Z']],
  flame: [['p', 'M52 6 C58 26 84 34 80 62 A30 30 0 0 1 20 62 C18 48 30 42 34 30 C36 44 46 46 44 56 C56 48 44 24 52 6 Z']],
  spark: [['p', 'M50 4 C54 34 66 46 96 50 C66 54 54 66 50 96 C46 66 34 54 4 50 C34 46 46 34 50 4 Z']],
  stack: [['r', 10, 26, 64, 64, 18, 'op:.35'], ['r', 20, 16, 64, 64, 18, 'op:.6'], ['r', 30, 6, 64, 64, 18]],
  'ring-arrow': [['a', 50, 50, 36, 30, 320, 'stroke:12'], ['p', 'M78 14 L96 30 L74 40 Z', 'alt']],
  rays: [['c', 50, 50, 22], ['rays', 50, 50, 30, 46, 8, 'alt']],
  chevrons: [['p', 'M16 30 L50 52 L84 30', 'stroke:11;op:.4'], ['p', 'M16 46 L50 68 L84 46', 'stroke:11;op:.7'], ['p', 'M16 62 L50 84 L84 62', 'stroke:11']],
  bubble: [['p', 'M20 14 H80 A14 14 0 0 1 94 28 V60 A14 14 0 0 1 80 74 H46 L28 90 V74 H20 A14 14 0 0 1 6 60 V28 A14 14 0 0 1 20 14 Z']],
  cube: [['g', 'M50 14 L86 32 L50 50 L14 32 Z', 'op:1'], ['g', 'M14 32 L50 50 V88 L14 70 Z', 'op:.72'], ['g', 'M86 32 L50 50 V88 L86 70 Z', 'op:.5']],
  tooth: [['p', 'M50 10 C36 10 26 18 26 32 C26 44 32 52 32 66 C32 80 36 92 42 92 C48 92 47 70 50 70 C53 70 52 92 58 92 C64 92 68 80 68 66 C68 52 74 44 74 32 C74 18 64 10 50 10 Z']],
  plane: [['p', 'M92 50 L10 12 L30 50 L10 88 Z'], ['p', 'M30 50 L62 50', 'stroke:6;alt']],
  cup: [['p', 'M18 28 H70 V58 A26 26 0 0 1 18 58 Z'], ['p', 'M70 34 H80 A11 11 0 0 1 80 56 H70', 'stroke:7;alt'], ['p', 'M30 10 C34 16 30 20 34 24 M46 8 C50 14 46 18 50 22', 'stroke:4;op:.6']],
  tile: [['r', 8, 8, 84, 84, 22]],
  pin: [['p', 'M50 94 C50 94 84 60 84 40 A34 34 0 0 0 16 40 C16 60 50 94 50 94 Z'], ['c', 50, 40, 13, 'alt']],
  heart: [['p', 'M50 90 C20 70 8 52 8 34 A20 20 0 0 1 50 24 A20 20 0 0 1 92 34 C92 52 80 70 50 90 Z']],
}; }
// markSVG draws one mark. m = { shape, rotate, mirror, gradient, duo, outline, letter }
// (the gradient id counter lives on globalThis: compose() runs at module
// top level, before a module-level `let` further down is initialised)
function markSVG(m, sizeCSS, letterColor) {
  const shapes = markPaths()[m.shape];
  if (!shapes) return '';
  globalThis.__uttraMarkSeq = (globalThis.__uttraMarkSeq || 0) + 1;
  const id = 'mk' + globalThis.__uttraMarkSeq;
  const fillMain = m.mono ? m.mono : (m.gradient === false ? 'var(--c-accent)' : `url(#${id})`);
  const alt = m.mono ? m.mono : (m.duo === false ? fillMain : 'var(--c-accent-2)');
  const sw = m.outline ? ' stroke="var(--c-accent)" stroke-width="7" fill="none"' : '';
  const attrs = (opt) => {
    const o = String(opt || '');
    const isAlt = /\balt\b/.test(o);
    const op = /op:([.\d]+)/.exec(o);
    const st = /stroke:(\d+)/.exec(o);
    let a = '';
    if (st) a += ` fill="none" stroke="${isAlt ? alt : fillMain}" stroke-width="${st[1]}" stroke-linecap="round" stroke-linejoin="round"`;
    else a += m.outline ? sw : ` fill="${isAlt ? alt : fillMain}"`;
    if (op) a += ` opacity="${op[1]}"`;
    return a;
  };
  const parts = shapes.map((s) => {
    const k = s[0];
    if (k === 'p') return `<path d="${s[1]}"${attrs(s[2])}/>`;
    if (k === 'g') return `<path d="${s[1]}" stroke-linejoin="round" stroke="${fillMain}" stroke-width="10"${attrs(s[2])}/>`;
    if (k === 'c') return `<circle cx="${s[1]}" cy="${s[2]}" r="${s[3]}"${attrs(s[4])}/>`;
    if (k === 'r') return `<rect x="${s[1]}" y="${s[2]}" width="${s[3]}" height="${s[4]}" rx="${s[5]}"${attrs(s[6])}/>`;
    if (k === 'e') return `<ellipse cx="${s[1]}" cy="${s[2]}" rx="${s[3]}" ry="${s[4]}" transform="rotate(${s[5]} ${s[1]} ${s[2]})"${attrs(s[6])}/>`;
    if (k === 'a') { // arc: cx cy r startDeg endDeg
      const [cx, cy, r, a0, a1] = [s[1], s[2], s[3], s[4], s[5]];
      const P = (deg) => [cx + r * Math.cos(deg * Math.PI / 180), cy + r * Math.sin(deg * Math.PI / 180)];
      const [x0, y0] = P(a0), [x1, y1] = P(a1);
      return `<path d="M${x0.toFixed(1)} ${y0.toFixed(1)} A${r} ${r} 0 ${a1 - a0 > 180 ? 1 : 0} 1 ${x1.toFixed(1)} ${y1.toFixed(1)}"${attrs(s[6])}/>`;
    }
    if (k === 'rays') { // cx cy r0 r1 count
      const [cx, cy, r0, r1, n] = [s[1], s[2], s[3], s[4], s[5]];
      let d = '';
      for (let i = 0; i < n; i++) { const t = (i / n) * Math.PI * 2; d += `M${(cx + r0 * Math.cos(t)).toFixed(1)} ${(cy + r0 * Math.sin(t)).toFixed(1)} L${(cx + r1 * Math.cos(t)).toFixed(1)} ${(cy + r1 * Math.sin(t)).toFixed(1)} `; }
      return `<path d="${d.trim()}" stroke-width="7" stroke-linecap="round" fill="none" stroke="${/alt/.test(s[6] || '') ? alt : fillMain}"/>`;
    }
    return '';
  }).join('');
  const rot = Number(m.rotate) || 0;
  const mir = m.mirror ? 'scale(-1 1) translate(-100 0)' : '';
  const letter = m.letter ? `<text x="50" y="50" text-anchor="middle" dominant-baseline="central" font-family="var(--f-logo)" font-weight="800" font-size="${m.letterSize || (m.shape === 'tile' ? 54 : 40)}" fill="${letterColor || 'var(--c-bg)'}">${escapeHTML(String(m.letter).slice(0, 2))}</text>` : '';
  return `<svg viewBox="0 0 100 100" style="width:${sizeCSS};height:${sizeCSS};display:block;overflow:visible" aria-hidden="true"><defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--c-accent)"/><stop offset="1" stop-color="var(--c-accent-2)"/></linearGradient></defs><g transform="rotate(${rot} 50 50) ${mir}">${parts}</g>${letter}</svg>`;
}

function logoLockup(logo, name, opts) {
  logo = logo || {};
  name = String(logo.name || name || '').trim();
  const big = !!(opts && opts.big);
  const btnText = (opts && opts.btnText) || 'var(--c-bg)';
  const u = big ? 12 : 3.4;                          // name size in --u
  const unit = (n) => `calc(var(--u) * ${n})`;
  if (logo.file) return `<img class="${big ? 'logo-lg' : 'logo'}" src="${logo.file.startsWith('assets/') ? '../' + logo.file : logo.file}" alt="" style="${big ? 'height:calc(var(--u) * 26)' : ''}">`;
  const mark = typeof logo.mark === 'string' ? { shape: logo.mark } : (logo.mark && typeof logo.mark === 'object' ? { ...logo.mark } : null);
  if (mark && mark.rotate == null) { let h = 0; for (const ch of name) h = (h * 17 + ch.codePointAt(0)) >>> 0; mark.rotate = [0, -12, 12, 20, -20][h % 5]; }
  if (!name && !logo.icon && !logo.svg && !mark) return '';
  // the symbol at a given size; mono = one flat colour (inside a filled badge/tile)
  const symbol = (sizeU, mono) => {
    const size = unit(sizeU);
    if (mark) return markSVG(Object.assign({}, mark, mono ? { mono } : {}), size, mono || btnText);
    if (logo.svg) return `<span style="display:inline-block;width:${size};height:${size};color:${mono || 'var(--c-accent)'}">${logo.svg}</span>`;
    if (logo.icon) return `<span style="display:inline-block;color:${mono || 'var(--c-accent)'}">${iconSVG(logo.icon, size)}</span>`;
    return '';
  };
  const hasSymbol = !!(mark || logo.svg || logo.icon);
  // defaults vary by brand (a stable hash of the name) so two brands never
  // get the same lockup by accident; a name alone becomes a monogram tile
  let seed = 0;
  for (const ch of name) seed = (seed * 31 + ch.codePointAt(0)) >>> 0;
  const styles = ['fused', 'inline', 'wordmark', 'stacked', 'fused', 'badge'];
  const style = logo.style || (hasSymbol ? styles[seed % styles.length] : 'monogram');
  // the lockup row runs in the NAME's own script: a Latin name on an
  // Arabic page keeps its mark at its first letter, not at the RTL end
  const nameDir = /[؀-ۿ]/.test(name) ? 'rtl' : 'ltr';
  const nameCSS = `font-family:var(--f-logo);font-weight:700;font-size:${unit(u)};line-height:1.3;letter-spacing:-0.02em;color:var(--c-ink);white-space:nowrap;direction:${nameDir}`;
  const first = name.slice(0, 1), rest = name.slice(1);
  const tagline = logo.tagline ? `<span class="caption" style="letter-spacing:0.12em${big ? ';font-size:calc(var(--u) * 3.2);color:var(--c-ink-2)' : ''}">${escapeHTML(logo.tagline)}</span>` : '';
  if (style === 'fused') {
    // the mark IS the first letter: the initial cut into the shape and set
    // on the name's own line - icon and word are one thing
    const m = Object.assign({ shape: 'tile' }, mark || {}, { letter: (mark && mark.letter) || first, letterSize: 50 });
    const sym = markSVG(m, unit(u * 1.5), m.mono || btnText);
    // an Arabic name is never cut: its letters join, so a name without its
    // initial is another word (2026-09-15: "سكر" became "كر") - the mark
    // carries the initial as a monogram and the whole name follows it
    const shown = nameDir === 'rtl' ? name : rest;
    return `<div class="stack" style="gap:${unit(big ? 1.5 : 0.4)}"><div class="row items-center" style="gap:${nameDir === 'rtl' ? '0.18em' : '0.06em'};${nameCSS}"><span style="display:inline-block">${sym}</span><span>${escapeHTML(shown)}</span></div>${tagline}</div>`;
  }
  if (style === 'wordmark') {
    const accentFirst = `<span style="color:var(--c-accent)">${escapeHTML(first)}</span>${escapeHTML(rest)}`;
    return `<div class="stack" style="gap:${unit(big ? 1.5 : 0.4)}"><div class="row items-center" style="gap:0.12em;${nameCSS}">${accentFirst}${hasSymbol ? `<span style="display:inline-block;margin-inline-start:-0.05em;transform:translateY(-0.4em)">${symbol(big ? 8 : 1.8)}</span>` : ''}</div>${tagline}</div>`;
  }
  if (style === 'stacked') {
    return `<div class="stack items-center" style="gap:${unit(big ? 2.5 : 0.7)}">${symbol(big ? 24 : 5)}<span style="${nameCSS}">${escapeHTML(name)}</span>${tagline}</div>`;
  }
  if (style === 'badge') {
    const d = big ? 22 : 6;
    const badge = `<span style="display:inline-grid;place-items:center;width:${unit(d)};height:${unit(d)};border-radius:50%;background:var(--c-accent);color:${btnText}">${symbol(big ? 13 : 3.6, btnText)}</span>`;
    return `<div class="row items-center" style="gap:${unit(big ? 3 : 1.2)}">${badge}<div class="stack" style="gap:${unit(big ? 1 : 0.2)}"><span style="${nameCSS}">${escapeHTML(name)}</span>${tagline}</div></div>`;
  }
  if (style === 'monogram') {
    const d = big ? 24 : 6.4;
    const corner = hasSymbol ? `<span style="position:absolute;right:-12%;bottom:-12%;display:inline-grid;place-items:center;width:46%;height:46%;border-radius:50%;background:var(--c-accent-2);color:var(--c-ink)">${symbol(big ? 7 : 1.9, 'var(--c-ink)')}</span>` : '';
    const tile = `<span style="position:relative;display:inline-grid;place-items:center;width:${unit(d)};height:${unit(d)};border-radius:${unit(big ? 5 : 1.6)};background:var(--c-accent);color:${btnText};font-family:var(--f-logo);font-weight:800;font-size:${unit(big ? 13 : 3.8)};line-height:1">${escapeHTML(first)}${corner}</span>`;
    return `<div class="row items-center" style="gap:${unit(big ? 3.5 : 1.4)}">${tile}<div class="stack" style="gap:${unit(big ? 1 : 0.2)}"><span style="${nameCSS}">${escapeHTML(name)}</span>${tagline}</div></div>`;
  }
  // inline: the symbol fused to the name's first letter
  return `<div class="stack" style="gap:${unit(big ? 1.5 : 0.4)}"><div class="row items-center" style="gap:0;${nameCSS}"><span style="display:inline-block;margin-inline-end:-0.18em;transform:translateY(-0.1em)">${symbol(big ? 17 : 3.8)}</span>${escapeHTML(name)}</div>${tagline}</div>`;
}

// autoSplit packs the headline into 1-3 short lines (about 10 characters
// each): short lines are what let the type run big in a column.
function autoSplit(text, want) {
  const words = text.split(/\s+/).filter(Boolean);
  const total = text.length;
  const target = Math.min(words.length, want || (total <= 12 || words.length <= 2 ? 1 : total <= 24 ? 2 : 3));
  if (target === 1) return [text];
  const perLine = Math.ceil(total / target) + 1;
  const lines = [];
  let cur = "";
  for (const wd of words) {
    if (cur && (cur.length + 1 + wd.length) > perLine && lines.length < target - 1) { lines.push(cur); cur = wd; } else cur = cur ? cur + " " + wd : wd;
  }
  if (cur) lines.push(cur);
  return lines;
}
