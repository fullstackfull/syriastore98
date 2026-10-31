/**
 * WS C. THE MERCHANT DASHBOARD'S CORRECTNESS FIXES, DRIVEN IN THE RUNNING APPLICATION.
 *
 * WHY A BROWSER AND NOT ONLY PHPUnit. Four of the five things WS C changed are only observable in
 * a rendered document:
 *
 *   - a DUPLICATE element id is legal PHP, legal Blade and legal-looking markup; what it breaks is
 *     the browser's own id resolution, so `aria-labelledby` and `aria-controls` silently point at
 *     whichever copy came first. Only a DOM can answer "does this id resolve, and to what".
 *   - `aria-selected="true"` on five tabs at once is a claim about what a screen reader is told.
 *   - the Arabic attention rows are a GRAMMAR defect: the string that reaches the page under
 *     `dir="rtl"`, at the real font, is the artefact.
 *   - and the storage card's "12MB / 0MB" is a sentence a merchant reads, beside a donut that
 *     said 0% at the same time.
 *
 * WHAT IT MEASURES, PER CONFIGURATION (2 viewports x en/ar x light/dark = 8):
 *
 *   1. the order list appears exactly ONCE (the overview's copy; WorkDo's duplicate is deleted)
 *   2. NO element id on the page appears twice
 *   3. every [role=tab] names an aria-controls element that EXISTS, and each tablist reports
 *      exactly one selected tab
 *   4. the storage card never states a quota of 0MB
 *   5. in Arabic, each attention row's phrase is the form its count requires -- read from the
 *      painted row, not from the translation file
 *
 * THE STATE IS SET SERVER-SIDE, through set-admin-preview-state.php, because the merchant admin
 * layout DERIVES direction from `users.language` and overrides `SITE_RTL` -- a harness that sets
 * only the setting measures ar/rtl twice and en/ltr never. That lesson is the ledger's (P2-01-14),
 * not this file's, and it is why `assertPremise` fails loudly on a mismatched `dir` or theme
 * rather than carrying on.
 *
 * ONE ENDPOINT IS EXCLUDED FROM THE 500 CHECK and it is not a product defect: the twelve queries
 * behind "Top Category / Top Brand -- By Sales" use `SUBSTRING_INDEX` and `CURDATE`, which SQLite
 * does not have. Production is MySQL. A consequence worth stating plainly: on this SQLite
 * instance those two cards can only ever render "No Data Found", so THIS HARNESS CANNOT SEE THE
 * `/100` MONEY FIX. That evidence comes from
 * `tests/Support/Security/bin/verify-dashboard-top-sales-scope-on-mysql.php` and from
 * `DashboardFigureHonestyTest::test_the_sales_figure_is_not_divided_by_one_hundred`, which renders
 * the real partial with a known figure.
 *
 * usage: node shoot-wsc-dashboard-live.cjs <baseUrl> <outDir> [label]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-wsc-dashboard');
const LABEL = process.argv[4] || 'after';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

const SQLITE_ONLY_500 = ['/top-brand-category-chart'];

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

/* The Arabic forms each count must produce, mirroring resources/lang/ar.json. Read from the
 * PAINTED row: the point of doing this in a browser is that the string a merchant sees is the
 * artefact, not the one the translator stored. */
const ARABIC_FORMS = {
    pending: { 1: 'طلب بانتظار التأكيد', 2: 'طلبان بانتظار التأكيد', many: 'طلبات بانتظار التأكيد' },
    stock: { 1: 'منتج نفد من المخزون', 2: 'منتجان نفدا من المخزون', many: 'منتجات نفدت من المخزون' },
    returns: { 1: 'طلب مُرتجَع', 2: 'طلبان مُرتجَعان', many: 'طلبات مُرتجَعة' },
};

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    const rows = [];
    let failures = 0;

    const check = (cfg, name, ok, detail) => {
        rows.push({ cfg, name, ok, detail });
        if (!ok) { failures += 1; }
    };

    for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                const cfg = `${LABEL}-${vp.name}-${locale}-${theme}`;
                const stateLine = setServerState(theme, locale);

                const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(String(e)));
                page.on('response', (r) => {
                    if (r.status() < 500) { return; }
                    if (SQLITE_ONLY_500.some((u) => r.url().includes(u))) { return; }
                    errors.push(`${r.status()} ${r.url()}`);
                });

                await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
                await page.fill('input[name="email"]', EMAIL);
                await page.fill('input[name="password"]', PASSWORD);
                await Promise.all([
                    page.waitForLoadState('domcontentloaded'),
                    page.click('button[type="submit"]'),
                ]);
                if (page.url().includes('/login')) { throw new Error(`login failed (${cfg})`); }

                await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(600);

                /* ---------------------------------------------------------- premises first */
                const premise = await page.evaluate(() => ({
                    dir: document.documentElement.getAttribute('dir') || '',
                    bg: getComputedStyle(document.body).backgroundColor,
                    title: (document.querySelector('h1, h2, .ss-page-header__title') || {}).textContent || '',
                }));
                const wantDir = locale === 'ar' ? 'rtl' : 'ltr';
                check(cfg, 'premise: the document direction is the one asked for',
                    premise.dir === wantDir, `dir="${premise.dir}" wanted "${wantDir}" | ${stateLine}`);

                /* --------------------------------------------------- 1. the order list, once */
                const lists = await page.evaluate(() => ({
                    overview: document.querySelectorAll('[data-ss-list="latest-orders"]').length,
                    /* BOTH SPELLINGS, AND THE ARABIC ONE IS THE REAL ar.json VALUE.
                     * The first version of this check guessed "الطلبات الأخيرة"; the shipped
                     * translation is "أوامر حديثة", so the Arabic half of the BEFORE run reported
                     * zero WorkDo copies against a page that had one -- the check passed for the
                     * wrong reason in four of eight configurations. Both heading levels are read
                     * too, because the WorkDo block uses <h4> and nothing guarantees it stays one. */
                    workdo: Array.from(document.querySelectorAll('h1, h2, h3, h4, h5'))
                        .filter((h) => /Recent Orders|أوامر حديثة/.test(h.textContent || '')).length,
                }));
                check(cfg, 'the order list is rendered exactly once',
                    lists.overview === 1 && lists.workdo === 0,
                    `overview copies=${lists.overview} workdo copies=${lists.workdo}`);

                /* ---------------------------------------------------- 2. ids resolve uniquely */
                const dupIds = await page.evaluate(() => {
                    const seen = {};
                    document.querySelectorAll('[id]').forEach((el) => {
                        const id = el.getAttribute('id');
                        if (!id) { return; }
                        seen[id] = (seen[id] || 0) + 1;
                    });
                    return Object.keys(seen).filter((id) => seen[id] > 1);
                });
                check(cfg, 'no element id appears twice', dupIds.length === 0, dupIds.join(', ') || 'none');

                /* ------------------------------------------------------------- 3. the tablists */
                const tabs = await page.evaluate(() => {
                    const out = { total: 0, unresolved: [], lists: {} };
                    document.querySelectorAll('[role="tab"]').forEach((t) => {
                        out.total += 1;
                        const controls = t.getAttribute('aria-controls');
                        if (!controls || !document.getElementById(controls)) {
                            out.unresolved.push(`${t.id || '(no id)'} -> ${controls || '(none)'}`);
                        }
                    });
                    document.querySelectorAll('[role="tablist"]').forEach((l) => {
                        out.lists[l.id || '(no id)'] =
                            l.querySelectorAll('[role="tab"][aria-selected="true"]').length;
                    });
                    return out;
                });
                check(cfg, 'premise: the period tablists rendered', tabs.total >= 10,
                    `${tabs.total} tabs found`);
                check(cfg, 'every tab names a panel that exists', tabs.unresolved.length === 0,
                    tabs.unresolved.join('; ') || 'all resolve');
                const badLists = Object.keys(tabs.lists).filter((k) => tabs.lists[k] !== 1);
                check(cfg, 'each tablist reports exactly one selected tab', badLists.length === 0,
                    JSON.stringify(tabs.lists));

                /* ------------------------------------------------------------ 4. the storage card */
                const storage = await page.evaluate(() => {
                    const head = Array.from(document.querySelectorAll('.card-header h4'))
                        .find((h) => /Storage Status|حالة التخزين/.test(h.textContent || ''));
                    if (!head) { return null; }
                    const card = head.closest('.dashboard-card');
                    return {
                        text: (head.textContent || '').replace(/\s+/g, ' ').trim(),
                        hasDonut: !!(card && card.querySelector('#device-chart')),
                    };
                });
                check(cfg, 'premise: the storage card rendered', storage !== null,
                    storage ? storage.text : 'card not found');
                if (storage) {
                    /* THE FIXTURE PLAN IS ON THE UNLIMITED SENTINEL (`plans.storage_limit = -1`),
                     * which is exactly what made the BEFORE run print "Storage Status (MB / -1MB)"
                     * over a 0% donut. So on this instance the card must say "unlimited" and must
                     * show no donut; a quota of 0MB or a negative quota is a failure in any
                     * configuration. */
                    check(cfg, 'the storage card states no quota of zero and no negative quota',
                        !/\/\s*-?0*MB/.test(storage.text) && !/-\d/.test(storage.text), storage.text);
                    check(cfg, 'an unlimited plan is named as unlimited, with no percentage donut',
                        /unlimited|غير محدود/.test(storage.text) && !storage.hasDonut, storage.text);
                }

                /* ------------------------------------------- 5. the Arabic attention row grammar */
                const attention = await page.evaluate(() => Array.from(
                    document.querySelectorAll('.ss-attention__item'),
                ).map((li) => ({
                    count: ((li.querySelector('.ss-attention__count') || {}).textContent || '').trim(),
                    text: ((li.querySelector('.ss-attention__text') || {}).textContent || '').trim(),
                })));

                if (locale === 'ar' && attention.length > 0) {
                    const wrong = [];
                    attention.forEach((row) => {
                        const n = parseInt(row.count.replace(/[^0-9]/g, ''), 10);
                        const family = Object.keys(ARABIC_FORMS)
                            .find((k) => Object.values(ARABIC_FORMS[k]).some((f) => row.text === f));
                        if (!family) { wrong.push(`unrecognised phrase "${row.text}"`); return; }
                        const forms = ARABIC_FORMS[family];
                        const want = n === 1 ? forms[1] : (n === 2 ? forms[2] : forms.many);
                        if (row.text !== want) { wrong.push(`n=${n} got "${row.text}" want "${want}"`); }
                    });
                    check(cfg, 'every Arabic attention row uses the form its count requires',
                        wrong.length === 0, wrong.join('; ') || attention.map((r) => `${r.count} ${r.text}`).join(' | '));
                } else if (locale === 'ar') {
                    check(cfg, 'premise: the attention block rendered in Arabic', false,
                        'no .ss-attention__item on the page, so the grammar was not measured');
                }

                check(cfg, 'no page error and no unexplained 500', errors.length === 0,
                    errors.join('; ') || 'none');

                /* ------------------------------------------------------------------ the artefacts */
                await page.screenshot({ path: path.join(OUT, `${cfg}-full.png`), fullPage: true });

                for (const [name, selector] of [
                    ['attention', '.ss-attention'],
                    ['storage', '.dashboard-card:has(#device-chart), .card-header:has(h4)'],
                    ['sales-cards', '.dash-category-tab'],
                ]) {
                    const el = await page.$(selector);
                    if (el) {
                        await el.screenshot({ path: path.join(OUT, `${cfg}-${name}.png`) }).catch(() => {});
                    }
                }

                await ctx.close();
            }
        }
    }

    await browser.close();

    const report = rows.map((r) => `${r.ok ? 'PASS' : 'FAIL'}  ${r.cfg}  ${r.name}  [${r.detail}]`).join('\n');
    fs.writeFileSync(path.join(OUT, `${LABEL}-report.txt`),
        `${report}\n\n${rows.length} checks, ${failures} failed\n`);
    console.log(report);
    console.log(`\n${rows.length} checks, ${failures} failed`);
    console.log(`artefacts: ${OUT}`);
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(2); });
