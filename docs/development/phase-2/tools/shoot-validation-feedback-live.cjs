/**
 * F271. THE VALIDATION MESSAGES A MERCHANT COULD NOT SEE, DRIVEN IN THE RUNNING APPLICATION.
 *
 * WHY A BROWSER IS THE ONLY HONEST EVIDENCE HERE. The defect is `display: none`. The message text
 * was always in the HTML — PHPUnit could read it, and a test that only asserted its presence
 * passed against the defect, which is exactly how this shipped. What was wrong is that the shipped
 * theme hides `.invalid-feedback` (`public/assets/css/style.css:3297`) unless a sibling carries
 * `.is-invalid` (`:3320-3325`), and no merchant form set it. So the question "can the merchant read
 * this?" has no answer outside a layout engine.
 *
 * WHAT IT MEASURES, PER CONFIGURATION (2 viewports x en/ar x light/dark = 8):
 *
 *   1. PREMISE: the form rendered and the direction/theme are the ones asked for
 *   2. PREMISE: a fresh form shows NO summary and NO field marked as failed
 *   3. after a refused submit the summary is in the layout and has non-zero height
 *   4. every inline message under a failed field has non-zero height — the actual bug
 *   5. the message's colour meets 4.5:1 against what is painted behind it (both themes)
 *   6. the summary's links resolve to a control that exists on the page
 *   7. no horizontal page scroll at 390px
 *
 * BEFORE/AFTER. `--label before` is run with the view reverted (git stash of the two views), so
 * the pair is a real comparison rather than the same state twice. The `before` run is EXPECTED to
 * fail checks 3-6; that is the point, and the script exits 0 for a `before` label so the failures
 * read as the baseline rather than as a broken harness.
 *
 * usage: node shoot-validation-feedback-live.cjs <baseUrl> <outDir> [label]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-wsf-validation');
const LABEL = process.argv[4] || 'after';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

function setServerState(theme, locale) {
    const repo = path.join(__dirname, '..', '..', '..', '..');
    return execFileSync('php', [
        path.join(__dirname, 'set-admin-preview-state.php'),
        '1', theme, locale === 'ar' ? 'rtl' : 'ltr', locale, EMAIL,
    ], {
        cwd: repo,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(repo, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        encoding: 'utf8',
    }).trim();
}

/* Relative luminance and contrast, per WCAG 2.1. Copied rather than imported because this file
 * has to run standalone from the repo root with no build step. */
function luminance(rgb) {
    const c = rgb.map((v) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}

function parseRgb(value) {
    const m = String(value).match(/rgba?\(([^)]+)\)/);
    if (!m) { return null; }
    const parts = m[1].split(',').map((x) => parseFloat(x.trim()));
    return { rgb: parts.slice(0, 3), alpha: parts.length > 3 ? parts[3] : 1 };
}

function contrast(fg, bg) {
    const a = luminance(fg) + 0.05;
    const b = luminance(bg) + 0.05;
    return a > b ? a / b : b / a;
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    const rows = [];
    let failures = 0;

    const check = (cfg, name, ok, detail) => {
        rows.push({ cfg, name, ok, detail: detail || '' });
        if (!ok) { failures += 1; }
    };

    for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                const cfg = `${vp.name}-${locale}-${theme}`;
                const stateLine = setServerState(theme, locale);

                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', PASSWORD);
                await Promise.all([
                    page.waitForLoadState('domcontentloaded'),
                    page.click('button[type="submit"]'),
                ]);
                if (page.url().includes('/login')) { throw new Error(`login failed (${cfg})`); }

                await page.goto(`${BASE}/order/create`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(400);

                /* ------------------------------------------------- 1. the form really rendered */
                const premise = await page.evaluate(() => ({
                    dir: document.documentElement.getAttribute('dir') || '',
                    theme: document.documentElement.getAttribute('data-ss-theme') || 'light',
                    form: !!document.querySelector('#order-form'),
                    customer: !!document.querySelector('[name="customer_id"]'),
                }));
                const wantDir = locale === 'ar' ? 'rtl' : 'ltr';
                check(cfg, 'premise: the manual order form rendered',
                    premise.form && premise.customer, JSON.stringify(premise));
                check(cfg, 'premise: direction and theme are the ones asked for',
                    premise.dir === wantDir && premise.theme === theme,
                    `dir="${premise.dir}" theme="${premise.theme}" wanted "${wantDir}"/"${theme}" | ${stateLine}`);

                /* --------------------------------------- 2. a fresh form is not painted as failed */
                const fresh = await page.evaluate(() => ({
                    summaries: document.querySelectorAll('[data-ss-error-summary]').length,
                    marked: document.querySelectorAll('.is-invalid').length,
                }));
                check(cfg, 'a form nobody submitted shows no summary and no failed field',
                    fresh.summaries === 0 && fresh.marked === 0, JSON.stringify(fresh));

                await page.screenshot({
                    path: path.join(OUT, `${LABEL}-${cfg}-01-fresh.png`),
                    fullPage: false,
                });

                /* -------------------------------------------------- the refusal, really submitted */
                await page.evaluate(() => {
                    const sel = document.querySelector('[name="customer_id"]');
                    if (sel) { sel.value = ''; sel.removeAttribute('required'); }
                    document.querySelectorAll('#order-form [required]').forEach((el) => el.removeAttribute('required'));
                    document.querySelectorAll('#order-form [name^="products"]').forEach((el) => el.remove());
                });
                await Promise.all([
                    page.waitForLoadState('networkidle'),
                    page.click('#order-form input[type="submit"], #order-form button[type="submit"]'),
                ]);
                await page.waitForTimeout(500);

                await page.screenshot({
                    path: path.join(OUT, `${LABEL}-${cfg}-02-refused.png`),
                    fullPage: false,
                });

                /* ------------------------------------------------- 3. the summary is really visible */
                const summary = await page.evaluate(() => {
                    const el = document.querySelector('[data-ss-error-summary]');
                    if (!el) { return { present: false }; }
                    const r = el.getBoundingClientRect();
                    const links = Array.from(el.querySelectorAll('a[href^="#"]'));
                    return {
                        present: true,
                        height: Math.round(r.height),
                        display: getComputedStyle(el).display,
                        links: links.length,
                        resolved: links.filter((a) => {
                            const id = a.getAttribute('href').slice(1);
                            return !!document.getElementById(id) || !!document.querySelector(`[name="${id}"]`);
                        }).length,
                    };
                });
                check(cfg, 'the refused submit renders a summary with real height',
                    summary.present && summary.height > 0 && summary.display !== 'none',
                    JSON.stringify(summary));
                check(cfg, "every summary link resolves to a control that exists",
                    summary.present && summary.links > 0 && summary.links === summary.resolved,
                    JSON.stringify(summary));

                /* -------------------------- 4 & 5. THE DEFECT ITSELF: the inline message's height */
                const inline = await page.evaluate(() => {
                    const out = [];
                    document.querySelectorAll('.invalid-feedback').forEach((el) => {
                        const r = el.getBoundingClientRect();
                        const cs = getComputedStyle(el);
                        /* walk up for the first non-transparent background actually painted */
                        let bg = 'rgba(0, 0, 0, 0)';
                        let node = el;
                        while (node && node !== document.documentElement) {
                            const c = getComputedStyle(node).backgroundColor;
                            if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') { bg = c; break; }
                            node = node.parentElement;
                        }
                        out.push({
                            text: (el.textContent || '').trim().slice(0, 60),
                            height: Math.round(r.height),
                            display: cs.display,
                            color: cs.color,
                            bg,
                        });
                    });
                    return out;
                });

                const withText = inline.filter((m) => m.text.length > 0);
                check(cfg, 'at least one inline message is on the page to be measured',
                    withText.length > 0, `${inline.length} .invalid-feedback nodes, ${withText.length} with text`);

                const hidden = withText.filter((m) => m.height === 0 || m.display === 'none');
                check(cfg, 'every inline message under a failed field has real height',
                    withText.length > 0 && hidden.length === 0,
                    hidden.length
                        ? `${hidden.length} of ${withText.length} are display:none — the merchant cannot read them: `
                          + JSON.stringify(hidden.slice(0, 2))
                        : `${withText.length} visible`);

                /* CONTRAST IS ONLY MEANINGFUL ON A MESSAGE THAT IS PAINTED, and a `display:none`
                 * one is not -- so in a `before` run this loop has nothing to measure and would
                 * pass vacuously. The measured COUNT is therefore part of the result line, and
                 * an `after` run that measured zero is reported as a failure rather than a pass. */
                const lowContrast = [];
                const measurable = withText.filter((x) => x.height > 0);
                for (const m of measurable) {
                    const fg = parseRgb(m.color);
                    const bg = parseRgb(m.bg);
                    if (!fg || !bg) { continue; }
                    const ratio = contrast(fg.rgb, bg.rgb);
                    if (ratio < 4.5) { lowContrast.push({ text: m.text, ratio: ratio.toFixed(2), color: m.color, bg: m.bg }); }
                }
                check(cfg, 'the message colour meets 4.5:1 against what is painted behind it',
                    lowContrast.length === 0 && (LABEL === 'before' || measurable.length > 0),
                    `${measurable.length} measured; ` + JSON.stringify(lowContrast.slice(0, 3)));

                /* ------------------------------------------------------ 6. the field is marked red */
                const marked = await page.evaluate(() => ({
                    count: document.querySelectorAll('.is-invalid').length,
                    customer: !!document.querySelector('[name="customer_id"].is-invalid'),
                }));
                check(cfg, 'the control that failed is marked, which is what reveals its message',
                    marked.customer, JSON.stringify(marked));

                /* ------------------------------------------------------ 7. no horizontal overflow */
                const overflow = await page.evaluate(() => ({
                    scrollWidth: document.documentElement.scrollWidth,
                    clientWidth: document.documentElement.clientWidth,
                }));
                check(cfg, 'no horizontal page scroll',
                    overflow.scrollWidth <= overflow.clientWidth + 1, JSON.stringify(overflow));

                check(cfg, 'no uncaught page errors', errors.length === 0, errors.slice(0, 2).join(' | '));

                await ctx.close();
            }
        }
    }

    await browser.close();

    for (const r of rows) {
        console.log(`${r.ok ? 'ok   ' : 'FAIL '} ${r.cfg}  ${r.name}${r.detail ? '  -- ' + r.detail : ''}`);
    }
    console.log(`\n${rows.length} checks, ${failures} failures  (label: ${LABEL}, screenshots in ${OUT})`);

    /* A `before` run is SUPPOSED to fail: it is the baseline for the comparison. Exiting non-zero
     * there would make a correct baseline look like a broken harness. */
    process.exit(LABEL === 'before' ? 0 : (failures === 0 ? 0 : 1));
})();
