/**
 * The merchant DataTable's two empty states, in four combinations each.  P2.5-WAVE-6a
 *
 *   php tests/Browser/render-datatable-empty.php /tmp/dt && \
 *     ln -s "$PWD/public/js" /tmp/dt/js && ln -s "$PWD/public/assets" /tmp/dt/assets && \
 *     ln -s "$PWD/public/css" /tmp/dt/css && ln -s "$PWD/public/fonts" /tmp/dt/fonts && \
 *     php -S 127.0.0.1:8912 -t /tmp/dt & \
 *   node tests/Browser/p25-datatable-empty.mjs
 *
 * WHY THIS EXISTS AND WHAT IT CAUGHT. A Unit test reads source and a DOM probe reads the built
 * tree; neither can see a cascade or a missing glyph. Three defects in this change survived both
 * and were found here, two of them only by LOOKING at the picture:
 *
 *   1. `td.dataTables-empty { padding: 0 }` DID NOT WIN. The plugin's
 *      `.dataTable-table > tbody > tr > td` is 0-1-3 against its 0-1-1, so the cell computed
 *      `8px 10px` while the sheet declared `0` -- and the Unit test asserting the declaration
 *      passed. This script asserts the COMPUTED value.
 *   2. "Search..." and "entries per page" WERE STILL ENGLISH on the Arabic page, directly above a
 *      now-Arabic empty state. The probe reported the empty state correct and said nothing about
 *      the furniture around it.
 *   3. `ti ti-search-off` DOES NOT EXIST in the bundled Tabler font, so the no-results state drew
 *      an EMPTY CIRCLE. The `<i>` was present with the right class, so every assertion passed.
 *
 * The `noResults` state needs rows to filter away, so the renderer writes each page twice -- once
 * empty, once with three fixture rows -- and this types a term matching none of them, with real
 * keystrokes: the library listens per keystroke, and Playwright's `fill()` sets the value without
 * firing what it listens for (the first version did that and measured nothing).
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const BASE = process.env.DT_BASE || 'http://127.0.0.1:8912';
const OUT = process.env.SHOT_DIR || 'docs/development/phase-2.5/evidence/wave-6a';
const COMBINATIONS = [['en', 'light'], ['en', 'dark'], ['ar', 'light'], ['ar', 'dark']];

const browser = await chromium.launch({ args: ['--no-sandbox'] });
let fails = 0;

const check = (label, condition, detail) => {
    if (!condition) {
        fails++;
        console.log(`  FAIL ${label}: ${detail}`);
    }
};

const readState = (page) => page.evaluate(() => {
    const cell = document.querySelector('td.dataTables-empty');
    if (!cell) return { found: false };
    const q = (s) => cell.querySelector(s);
    const top = document.querySelector('.dataTable-top');
    return {
        found: true,
        colspan: cell.getAttribute('colspan'),
        component: !!q('.ss-empty'),
        title: q('.ss-empty__title') ? q('.ss-empty__title').textContent.trim() : null,
        subtitle: q('.ss-page-header__subtitle') ? q('.ss-page-header__subtitle').textContent.trim() : null,
        iconClass: q('.ss-empty__icon i') ? q('.ss-empty__icon i').className : null,
        // An icon-font class that matches no :before rule renders nothing. The glyph's own box
        // has a width only when the font resolved it.
        iconWidth: q('.ss-empty__icon i') ? q('.ss-empty__icon i').getBoundingClientRect().width : 0,
        padding: getComputedStyle(cell).padding,
        textAlign: getComputedStyle(cell).textAlign,
        furniture: top ? top.innerText.replace(/\s+/g, ' ').trim() : null,
        dir: document.documentElement.getAttribute('dir'),
    };
});

const ARABIC = /[؀-ۿ]/;

for (const [page_, suffix, drive] of [['custom-domain', 'nothing-yet', null], ['populated', 'no-results', 'zzzzqqq']]) {
    for (const [locale, theme] of COMBINATIONS) {
        const page = await browser.newPage({ viewport: { width: 900, height: 460 }, deviceScaleFactor: 2 });
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
        page.on('response', (r) => { if (r.status() === 404) errors.push('404: ' + r.url()); });

        await page.goto(`${BASE}/${page_}-${locale}-${theme}.html`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(300);

        if (drive) {
            await page.click('.dataTable-input');
            await page.type('.dataTable-input', drive, { delay: 50 });
            await page.waitForTimeout(400);
        }

        const s = await readState(page);
        const where = `${suffix} ${locale}-${theme}`;

        check(where, s.found, 'no td.dataTables-empty — the library did not reach its empty state');
        check(where, s.component, 'the cell does not carry the .ss-empty component');
        check(where, !!s.title && !!s.subtitle, 'the state has no title or no subtitle');
        check(where, s.padding === '0px', `cell padding computed ${s.padding}, not 0px — the plugin's own rule is winning`);
        check(where, s.textAlign === 'center', `cell text-align computed ${s.textAlign}`);
        check(where, s.iconWidth > 0, `the icon ${s.iconClass} has zero width — that glyph is not in the bundled font and renders as an empty circle`);
        check(where, s.dir === (locale === 'ar' ? 'rtl' : 'ltr'), `dir is ${s.dir}`);
        check(where, errors.length === 0, `console/network errors: ${errors.join(' | ')}`);

        // The whole point of the Arabic half: the state AND the furniture around it.
        if (locale === 'ar') {
            check(where, ARABIC.test(s.title || ''), `the title is not Arabic: ${s.title}`);
            check(where, ARABIC.test(s.subtitle || ''), `the subtitle is not Arabic: ${s.subtitle}`);
            check(where, ARABIC.test(s.furniture || ''), `the search box and pager are still English: ${s.furniture}`);
        }

        await page.screenshot({ path: `${OUT}/${suffix === 'nothing-yet' ? 'custom-domain' : 'no-results'}-${locale}-${theme}.png` });
        console.log(`${where.padEnd(26)} ${s.title} | ${s.iconClass} w=${s.iconWidth} pad=${s.padding}`);
        await page.close();
    }
}

await browser.close();
console.log(fails === 0 ? 'ALL OK' : `${fails} CHECKS FAILED`);
process.exit(fails === 0 ? 0 : 1);
