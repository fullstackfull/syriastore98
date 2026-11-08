/**
 * DIALOG CONTRAST AUDIT -- every first-party confirmation pathway, every element, every combo.
 *
 * Opens the REAL dialog on the REAL screen (no synthesised markup), then reads the computed style
 * of every text and graphic part and composites it against whatever is actually behind it, so an
 * `rgba()` button ground over the dialog's white sheet is measured as the merchant sees it rather
 * than as the declaration reads.
 *
 * usage: node audit-dialog-contrast.cjs --out <dir> [--only key,key] [--label before|after]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const argv = process.argv.slice(2);
const arg = (k, d) => { const i = argv.indexOf('--' + k); return i >= 0 ? argv[i + 1] : d; };
const BASE = arg('base', 'http://127.0.0.1:8899');
const OUT = arg('out', '/tmp/modal-audit');
const LABEL = arg('label', 'before');
const ONLY = (arg('only', '') || '').split(',').map((s) => s.trim()).filter(Boolean);
const REPO = '/home/user/syriastore98';
const STATE_TOOL = path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php');

const COMBOS = [
  { lang: 'en', dir: 'ltr', theme: 'light' },
  { lang: 'en', dir: 'ltr', theme: 'dark' },
  { lang: 'ar', dir: 'rtl', theme: 'light' },
  { lang: 'ar', dir: 'rtl', theme: 'dark' },
];
const WIDTHS = [
  { w: 1440, h: 900, tag: 'desktop' },
  { w: 390, h: 844, tag: 'w390' },
  { w: 320, h: 720, tag: 'w320' },
];

/**
 * The four FIRST-PARTY dialog pathways, by the class of action each one guards. The owner asked
 * for destructive, non-destructive and payment-adjacent to be exercised separately; these are
 * the real ones, found by grepping every `Swal.` site under resources/views and public/js.
 */
const SCENARIOS = [
  {
    key: 'A-shared-destructive',
    cls: 'destructive',
    title: 'Shared .show_confirm -- delete (custom.js, 124 triggers / 107 views)',
    path: '/abandon-carts-handled',
    expect: { en: 'Abandoned', ar: 'عربات' },
    open: async (page) => {
      // The abandoned-cart delete button OPTS IN to the repaired tone, so to photograph the
      // DEFAULT pathway the opt-in attribute is removed in the page first. This measures the
      // shared default that the other ~120 triggers get, on a real trigger, real form, real page.
      await page.evaluate(() => {
        document.querySelectorAll('.show_confirm[data-confirm-tone]')
          .forEach((el) => el.removeAttribute('data-confirm-tone'));
      });
      await page.locator('.show_confirm').first().click({ timeout: 8000 });
    },
  },
  {
    key: 'A2-shared-optin',
    cls: 'destructive (opt-in tone, already repaired in PKG-I)',
    title: 'Shared .show_confirm -- delete WITH data-confirm-tone="danger"',
    path: '/abandon-carts-handled',
    expect: { en: 'Abandoned', ar: 'عربات' },
    open: async (page) => { await page.locator('.show_confirm[data-confirm-tone="danger"]').first().click({ timeout: 8000 }); },
  },
  {
    key: 'C-refund-cancel',
    cls: 'payment-adjacent (terminal)',
    title: 'Cancel refund request (.cancel-button, order/refund_details.blade.php)',
    path: null,
    // The detail id is `Crypt::encrypt`ed, so the real link is followed from the real list rather
    // than guessed: /refund-request/1 redirects to the LIST with a toast, which is the
    // dashboard-redirect trap in a smaller size.
    resolvePath: async (page) => {
      await page.goto(BASE + '/refund-request', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2200);
      return await page.evaluate(() => {
        const a = document.querySelector('a[href*="refund-request/"]');
        return a ? a.getAttribute('href') : null;
      });
    },
    expect: { en: '', ar: '' },
    open: async (page) => {
      const n = await page.locator('.cancel-button').count();
      if (n === 0) throw new Error('no .cancel-button on this refund (status may not allow it)');
      await page.locator('.cancel-button').first().click({ timeout: 8000 });
    },
  },
  {
    key: 'D-refund-amount',
    cls: 'payment-adjacent (terminal)',
    title: 'Refund the amount (#refund-amount, order/refund_details.blade.php)',
    path: null,
    resolvePath: async (page) => {
      await page.goto(BASE + '/refund-request', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2200);
      return await page.evaluate(() => {
        const a = document.querySelector('a[href*="refund-request/"]');
        return a ? a.getAttribute('href') : null;
      });
    },
    expect: { en: '', ar: '' },
    open: async (page) => {
      const n = await page.locator('#refund-amount').count();
      if (n === 0) throw new Error('no #refund-amount control on this refund');
      await page.locator('#refund-amount').first().click({ timeout: 8000 });
    },
  },
  {
    key: 'B-order-replace',
    cls: 'non-destructive',
    title: 'Order replace (.order_replace, order/view.blade.php)',
    path: null, // resolved at runtime: the first order whose state renders a replace trigger
    // `order-view/{id}` takes a Crypt::encrypt'ed id, so a numeric one throws DecryptException and
    // lands on the dashboard. The real hrefs come from the real list, and `.order_replace` is
    // state-gated (Gate::allows('updateStatus') AND eligibility()===REPLACED), so several are tried.
    resolvePath: async (page) => {
      await page.goto(BASE + '/order', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2600);
      const hrefs = await page.evaluate(() => [...document.querySelectorAll('a[href*="order-view"]')].map((a) => a.getAttribute('href')));
      for (const h of hrefs.slice(0, 12)) {
        await page.goto(h.startsWith('http') ? h : BASE + h, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1400);
        if (await page.locator('.order_replace').count() > 0) return h;
      }
      return null;
    },
    expect: { en: '', ar: '' },
    open: async (page) => {
      // The trigger may be absent when the order is not in a replaceable state, so the dialog is
      // opened through the real handler by synthesising only the CLICK on a real trigger if one
      // exists; otherwise the row is reported PREMISE-FAIL rather than faked.
      const n = await page.locator('.order_replace').count();
      if (n === 0) { throw new Error('no .order_replace trigger on this order'); }
      await page.locator('.order_replace').first().click({ timeout: 8000 });
    },
  },
];

const srgb = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
const lum = ([r, g, b]) => 0.2126 * srgb(r) + 0.7152 * srgb(g) + 0.0722 * srgb(b);
const ratio = (a, b) => { const la = lum(a), lb = lum(b); const hi = Math.max(la, lb), lo = Math.min(la, lb); return (hi + 0.05) / (lo + 0.05); };

function setState(theme, dir, lang) {
  // positional: <storeId> <light|dark> <ltr|rtl> <lang> <email>.  The three variables are PREFIXED
  // for this child process only and never exported -- serve-dev.sh records what exporting them
  // into a shell that then runs the suite does to the fixture.
  const out = execFileSync('php', [STATE_TOOL, '1', theme, dir, lang, 'merchant@example.test'], {
    cwd: path.dirname(STATE_TOOL),
    env: {
      ...process.env,
      DB_CONNECTION: process.env.DB_CONNECTION || 'sqlite',
      DB_DATABASE: process.env.DB_DATABASE || path.join(REPO, 'database/p2dev.sqlite'),
      CACHE_STORE: process.env.CACHE_STORE || 'file',
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  process.stdout.write('    state: ' + String(out).trim().split('\n').pop() + '\n');
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const rows = [];
  const browser = await chromium.launch();

  for (const combo of COMBOS) {
    setState(combo.theme, combo.dir, combo.lang);
    for (const width of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: width.w, height: width.h }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      // sign in
      await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', 'merchant@example.test');
      await page.fill('input[name="password"]', 'password');
      await page.click('button[type="submit"]');
      await page.waitForLoadState('domcontentloaded');
      await page.waitForTimeout(1200);

      for (const sc of SCENARIOS) {
        if (ONLY.length && !ONLY.includes(sc.key)) continue;
        const id = `${sc.key}__${combo.lang}-${combo.theme}-${width.tag}`;
        const row = { id, key: sc.key, cls: sc.cls, ...combo, width: width.tag, premise: [], measures: [] };
        try {
          let target = sc.path;
          if (!target && sc.resolvePath) target = await sc.resolvePath(page);
          if (!target) throw new Error('could not resolve a path for this scenario');
          if (!target.startsWith('http')) target = BASE + (target.startsWith('/') ? target : '/' + target);
          await page.goto(target, { waitUntil: 'domcontentloaded' });
          await page.waitForTimeout(1800);

          const url = page.url();
          if (/\/login/.test(url)) throw new Error('PREMISE: not signed in (' + url + ')');
          const dir = await page.getAttribute('html', 'dir');
          if ((dir || 'ltr') !== combo.dir) throw new Error(`PREMISE: dir=${dir} wanted ${combo.dir}`);
          const themeAttr = await page.evaluate(() => document.documentElement.getAttribute('data-ss-theme') || 'light');
          if (themeAttr !== combo.theme) throw new Error(`PREMISE: theme=${themeAttr} wanted ${combo.theme}`);
          row.premise.push('signed-in', 'dir=' + dir, 'theme=' + themeAttr, 'url=' + url.replace(BASE, ''));

          await sc.open(page);
          await page.waitForSelector('.swal2-popup', { timeout: 8000 });
          await page.waitForTimeout(900);
          row.premise.push('dialog-open');

          // Measure every part of the dialog, compositing each background down the ancestor chain.
          row.measures = await page.evaluate(() => {
            const parse = (s) => {
              const m = String(s).match(/rgba?\(([^)]+)\)/);
              if (!m) return null;
              const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
              return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
            };
            const over = (fg, bg) => ({
              r: fg.r * fg.a + bg.r * (1 - fg.a),
              g: fg.g * fg.a + bg.g * (1 - fg.a),
              b: fg.b * fg.a + bg.b * (1 - fg.a),
              a: 1,
            });
            // walk up until the composited alpha reaches 1
            const groundOf = (el) => {
              let acc = null, node = el;
              while (node && node.nodeType === 1) {
                const bg = parse(getComputedStyle(node).backgroundColor);
                if (bg && bg.a > 0) acc = acc ? over(acc, bg) : bg;
                if (acc && acc.a >= 0.999) return acc;
                node = node.parentElement;
              }
              const page = parse(getComputedStyle(document.body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
              return acc ? over(acc, page) : page;
            };
            const parts = [
              ['title', '.swal2-popup .swal2-title'],
              ['body', '.swal2-popup .swal2-html-container'],
              ['confirm', '.swal2-popup .swal2-confirm'],
              ['cancel', '.swal2-popup .swal2-cancel'],
              ['close', '.swal2-popup .swal2-close'],
              ['icon-ring', '.swal2-popup .swal2-icon'],
              ['icon-glyph', '.swal2-popup .swal2-icon .swal2-icon-content'],
            ];
            const out = [];
            for (const [name, sel] of parts) {
              const el = document.querySelector(sel);
              if (!el) { out.push({ name, present: false }); continue; }
              const cs = getComputedStyle(el);
              const rect = el.getBoundingClientRect();
              const ink = parse(cs.color);
              const ground = groundOf(el.parentElement ? el : el);
              const selfBg = parse(cs.backgroundColor);
              // for a button, its OWN background composited over the sheet is the ground
              let g = ground;
              if (selfBg && selfBg.a > 0 && selfBg.a < 1) {
                let behind = null, node = el.parentElement;
                while (node && node.nodeType === 1) {
                  const bb = parse(getComputedStyle(node).backgroundColor);
                  if (bb && bb.a > 0) { behind = behind ? over(behind, bb) : bb; }
                  if (behind && behind.a >= 0.999) break;
                  node = node.parentElement;
                }
                if (!behind) behind = { r: 255, g: 255, b: 255, a: 1 };
                g = over(selfBg, behind);
              } else if (selfBg && selfBg.a >= 1) {
                g = selfBg;
              }
              out.push({
                name, present: true,
                text: (el.textContent || '').trim().slice(0, 40),
                ink: ink ? [Math.round(ink.r), Math.round(ink.g), Math.round(ink.b)] : null,
                inkAlpha: ink ? ink.a : null,
                ground: g ? [Math.round(g.r), Math.round(g.g), Math.round(g.b)] : null,
                declaredBg: cs.backgroundColor,
                borderColor: cs.borderTopColor,
                w: Math.round(rect.width), h: Math.round(rect.height),
                fontSize: cs.fontSize, fontWeight: cs.fontWeight,
                cls: el.className,
              });
            }
            const popup = document.querySelector('.swal2-popup');
            out.push({ name: '_sheet', present: !!popup, ground: popup ? (() => { const g = groundOf(popup); return [Math.round(g.r), Math.round(g.g), Math.round(g.b)]; })() : null });
            return out;
          });

          /*
           * THE THRESHOLD IS NOT 4.5 FOR EVERYTHING, and the first run of this script reported two
           * findings that a correct threshold does not support.
           *
           *   - a 0x0 element is NOT RENDERED. `.swal2-close` measured 1.61:1 and is 0x0 px,
           *     because `showCloseButton` is never set on any pathway. Reporting it as a defect
           *     would be reporting a colour nobody sees.
           *   - WCAG 1.4.3 puts LARGE text (>=24px, or >=18.66px bold) at 3:1, and 1.4.11 puts
           *     graphics and UI-component boundaries at 3:1. The warning icon is both.
           *
           * The icon still fails at 2.01:1 under the 3:1 rule, so the finding survives the
           * correction -- which is why the correction is worth making rather than convenient.
           */
          for (const m of row.measures) {
            if (!m.present || !m.ink || !m.ground) continue;
            if ((m.w === 0 || m.h === 0)) { m.rendered = false; continue; }
            m.rendered = true;
            m.ratio = Number(ratio(m.ink, m.ground).toFixed(2));
            const px = parseFloat(m.fontSize || '16');
            const bold = (parseInt(m.fontWeight || '400', 10) >= 700);
            const graphic = /icon/.test(m.name);
            m.threshold = graphic || px >= 24 || (bold && px >= 18.66) ? 3 : 4.5;
            m.pass = m.ratio >= m.threshold;
          }

          const shot = path.join(OUT, `${LABEL}__${id}.png`);
          const popup = page.locator('.swal2-popup').first();
          await popup.screenshot({ path: shot });
          row.shot = shot;
          row.ok = true;
        } catch (e) {
          row.ok = false;
          row.error = String(e.message || e).slice(0, 220);
        }
        rows.push(row);
        const flag = row.ok ? (row.measures.some((m) => m.pass === false) ? 'FAIL' : 'pass') : 'PREMISE-FAIL';
        console.log(`[${flag}] ${id}${row.ok ? '' : '  -- ' + row.error}`);
        if (row.ok) {
          for (const m of row.measures) {
            if (m.ratio !== undefined) {
              console.log(`        ${m.pass ? ' ok ' : 'FAIL'}  ${m.name.padEnd(10)} ink rgb(${m.ink.join(',')}) on rgb(${m.ground.join(',')}) -> ${m.ratio}:1  (needs ${m.threshold}:1)   "${m.text}"`);
            } else if (m.present === false) {
              console.log(`        ---   ${m.name.padEnd(10)} absent`);
            } else if (m.rendered === false) {
              console.log(`        ---   ${m.name.padEnd(10)} present but 0x0 -- not rendered, not measured`);
            }
          }
        }
        // dismiss
        try { await page.keyboard.press('Escape'); await page.waitForTimeout(300); } catch (e) {}
      }
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, `${LABEL}-results.json`), JSON.stringify(rows, null, 2));
  const failed = rows.filter((r) => r.ok && r.measures.some((m) => m.pass === false));
  const premise = rows.filter((r) => !r.ok);
  console.log(`\n=== ${LABEL}: ${rows.length} rows, ${failed.length} with a failing element, ${premise.length} premise-fail ===`);
})();
