/**
 * P2-02-9. THE TWO DASHBOARD LISTS, DRIVEN IN THE RUNNING APPLICATION WITH ROWS IN THEM.
 *
 * WHY IT EXISTS. Both cards were finished code and both were already in the Phase 02
 * screenshots -- and the best-selling one had never once been seen holding a row. The
 * development fixture left `orders.product_id` at its default `'0'` while `HomeController`
 * plucks that column (not `product_json`), so `topSellingProductIds` was `[0]`, nothing
 * matched, and the card rendered `No sales yet`. An empty state is indistinguishable from a
 * healthy card with nothing to show, which is what it is for, so nothing looked wrong at any
 * point.
 *
 * SO THIS ASSERTS THE ROWS, NOT THE CARD. Counting cards is exactly what could not see the
 * defect: the card was always there. Each run reads the two tables' own `<tbody>` rows and
 * fails on zero, on an empty product name, and on a card that fell back to its empty state.
 * It also reads the footers, because both carry a claim PRD-001 exists to keep honest -- the
 * ranking is by HOW MANY ORDERS NAME THE PRODUCT, and the order figures are gross value and
 * not cash collected.
 *
 * usage: node shoot-dashboard-cards-live.cjs <baseUrl> <outDir>
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT = process.argv[3] || path.join(__dirname, '..', 'screenshots', 'p2-02-dashboard');
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';

function setServerState(theme, dir) {
    const repo = path.join(__dirname, '..', '..', '..', '..');
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), '1', theme, dir], {
        cwd: repo,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(repo, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });
}

/* See shoot-dashboard-live.cjs for why this one endpoint is excluded: its raw SQL uses
 * SUBSTRING_INDEX and CURDATE, which SQLite does not have. Production is MySQL. Named
 * explicitly so a 500 anywhere else still fails the run. */
const SQLITE_ONLY_500 = ['/top-brand-category-chart'];

(async () => {
    const browser = await chromium.launch();
    const results = [];
    let failures = 0;

    for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
        for (const locale of ['en', 'ar']) {
            for (const theme of ['light', 'dark']) {
                setServerState(theme, locale === 'ar' ? 'rtl' : 'ltr');

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
                await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
                if (page.url().includes('/login')) throw new Error('login failed');

                await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});
                await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(400);

                const tag = `${vp.name}-${locale}-${theme}`;

                /*
                 * THE CARDS ARE FOUND BY `data-ss-list`, AND THE FIRST VERSION OF THIS CHECK
                 * GOT IT WRONG IN A WAY THAT PASSED. It looked each table up by a fragment of
                 * its caption -- and the best-selling caption is "Products appearing most often
                 * in this store's orders" while the orders caption is "The four most recent
                 * orders", so a search for "orders" matched the ORDERS table first and reported
                 * it as the best-selling one. Four of eight configurations read the same card
                 * twice and the run said `0 problems`. The Arabic four only escaped because the
                 * captions are translated there, so neither fragment matched at all and a
                 * positional fallback answered instead.
                 *
                 * A caption is the table's translated screen-reader name. It cannot identify a
                 * table in a check that runs in two languages, and position in the row is a
                 * layout detail that a responsive reflow is entitled to change. The attribute is
                 * the only anchor that is neither.
                 */
                const read = await page.evaluate(() => {
                    function listOf(name) {
                        const table = document.querySelector(`table[data-ss-list="${name}"]`);
                        if (!table) {
                            // The card may still be present and showing its empty state, which
                            // is the exact condition this run exists to refuse -- so say which.
                            const cards = [...document.querySelectorAll('.ss-card')];
                            return { table: false, cardsOnPage: cards.length, rows: 0, cells: [] };
                        }

                        const card = table.closest('.ss-card');
                        const rows = [...table.querySelectorAll('tbody tr')];

                        /*
                         * THE FOOTER MUST SIT ON THE CARD'S BOTTOM EDGE. `.ss-row` stretches
                         * its cards to the tallest one, so the card holding FEWER rows used to
                         * leave its sunken footer band floating mid-card with empty surface
                         * below it. That only became visible once these two lists held
                         * different row counts -- 4 orders beside 5 products -- which is the
                         * same day the best-selling card first held any rows at all.
                         */
                        const footerEl = card.querySelector('.ss-card__footer');
                        const gap = footerEl
                            ? card.getBoundingClientRect().bottom - footerEl.getBoundingClientRect().bottom
                            : null;

                        return {
                            table: true,
                            rows: rows.length,
                            footerGap: gap === null ? null : Math.round(gap),
                            cells: rows.map((tr) => [...tr.querySelectorAll('th, td')]
                                .map((c) => (c.textContent || '').trim())),
                            footer: ((card.querySelector('.ss-card__footer') || {}).textContent || '').trim(),
                            title: ((card.querySelector('.ss-card__title') || {}).textContent || '').trim(),
                            dir: getComputedStyle(card).direction,
                        };
                    }

                    return {
                        emptyStates: document.querySelectorAll('.ss-empty').length,
                        best: listOf('best-selling'),
                        orders: listOf('latest-orders'),
                    };
                });

                const best = read.best;
                const orders = read.orders;

                /*
                 * PREMISE: the two readings are not the same card. That is the failure the
                 * caption lookup produced, and it is cheap to make impossible rather than to
                 * remember -- two different titles, and two tables the DOM agrees are distinct.
                 */
                const distinct = await page.evaluate(() =>
                    document.querySelectorAll('table[data-ss-list]').length);

                await page.screenshot({ path: path.join(OUT, `09-lists-${tag}.png`), fullPage: false });

                // The pair of cards on their own, which is what a reviewer actually compares.
                const pair = page.locator('.ss-row').filter({ has: page.locator('table') }).last();
                if (await pair.count() > 0) {
                    await pair.screenshot({ path: path.join(OUT, `09-lists-pair-${tag}.png`) }).catch(() => {});
                }

                /*
                 * THE ASSERTIONS. Rows, not cards; and a non-empty first cell, because a table
                 * of blank names is a populated table by every structural measure.
                 */
                const problems = [];
                if (!best.table) { problems.push('best-selling card has no table (it fell back to its empty state)'); }
                if (best.rows < 1) { problems.push(`best-selling table has ${best.rows} rows`); }
                if (best.rows > 5) { problems.push(`best-selling table has ${best.rows} rows, more than the controller's take(5)`); }
                if (best.cells.some((c) => !c[0])) { problems.push('a best-selling row has an empty product name'); }
                if (best.cells.some((c) => !c[1])) { problems.push('a best-selling row has an empty price'); }
                if (!orders.table) { problems.push('latest-orders card has no table'); }
                if (orders.rows < 1) { problems.push(`latest-orders table has ${orders.rows} rows`); }
                if (orders.rows > 4) { problems.push(`latest-orders table has ${orders.rows} rows, more than the controller's limit of 4`); }
                if (orders.cells.some((c) => !c[0])) { problems.push('a latest-order row has an empty reference'); }
                if (!best.footer) { problems.push('the best-selling card states no ranking measure'); }
                if (!orders.footer) { problems.push('the latest-orders card states no value qualification'); }
                if (distinct !== 2) { problems.push(`${distinct} tables carry data-ss-list, expected 2`); }
                // 2px of slack for the 1px border and sub-pixel layout; the defect was 40px.
                for (const [name, card] of [['best-selling', best], ['latest-orders', orders]]) {
                    if (card.table && card.footerGap !== null && card.footerGap > 2) {
                        problems.push(`${name}'s footer floats ${card.footerGap}px above the card's bottom edge`);
                    }
                }
                if (best.table && orders.table && best.title === orders.title) {
                    problems.push('both readings came back from the same card');
                }
                if (errors.length) { problems.push(...errors); }

                failures += problems.length;
                results.push({ tag, bestRows: best.rows, orderRows: orders.rows, problems });

                console.log(`${tag}: best=${best.rows} rows "${best.title}", orders=${orders.rows} rows "${orders.title}"`
                    + ` | ${problems.length ? 'FAIL ' + problems.join('; ') : 'ok'}`);
                if (best.cells.length) { console.log(`    top seller: ${JSON.stringify(best.cells[0])}`); }

                await ctx.close();
            }
        }
    }

    await browser.close();

    console.log(`\n${results.length} configurations, ${failures} problems`);
    process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
