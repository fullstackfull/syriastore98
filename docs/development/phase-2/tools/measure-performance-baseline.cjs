/**
 * MASTER PROMPT SECTION 6 — "compare actual frontend performance against a measured baseline".
 *
 * WHY IT EXISTS. `PHASE-02-COVERAGE-AUDIT-2026-10-25.md`'s re-derivation named this as one of two
 * obligations genuinely not met: *"There is no artifact anywhere in the repository: no Lighthouse
 * run, no LCP, no TTFB. Not started, not deferred by decision, and not previously recorded as
 * missing."* This produces the artifact, so the comparison the section asks for has something to
 * compare against.
 *
 * WHAT IT IS AND IS NOT. It is a BASELINE measured in THIS container: a 1-vCPU-class cloud sandbox
 * running `php artisan serve` (a single-process dev server with no opcache warm, no CDN, no HTTP/2,
 * no gzip) against a SQLite fixture. The absolute numbers therefore say nothing about production
 * and must not be quoted as if they did. What they are good for is the thing the section asks for:
 * a recorded starting point on identical hardware, so a later run on the same harness can show
 * whether a change made a merchant screen slower.
 *
 * WHY MEDIAN OF THREE, not one run. A cold first navigation pays for route-cache population,
 * view compilation and the autoloader; measured here, run 1 was routinely 2-4x run 2 on the same
 * URL. A single-run baseline would therefore record compilation cost as page cost and every later
 * comparison would read as a huge improvement. Run 1 is treated as a warm-up and DISCARDED, and
 * the median of the next three is recorded — stated here because a mean would let one outlier
 * (a GC pause in the dev server) set the baseline.
 *
 * WHY BOTH DIRECTIONS. `layouts/app.blade.php` picks between style.css, style-dark.css,
 * rtl-style.css and rtl-style-dark.css SERVER-SIDE, so LTR and RTL do not load the same bytes.
 * A single-direction baseline would be a baseline for half the merchants.
 *
 * WHAT IS DELIBERATELY NOT HERE. No Lighthouse score. Lighthouse's own numbers are a function of
 * its simulated-throttling model, and running it against a dev server in a shared sandbox produces
 * a figure that varies more with neighbour load than with the code. The raw primitives it derives
 * its score FROM — TTFB, LCP, CLS, transfer size, request count, long tasks — are what is recorded,
 * because those are the ones a later run can meaningfully compare.
 *
 * usage: node measure-performance-baseline.cjs [baseUrl] [outJson]
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const OUT_JSON = process.argv[3]
    || path.join(__dirname, '..', 'evidence', 'performance-baseline', 'baseline.json');

const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';
const REPO = path.join(__dirname, '..', '..', '..', '..');
const RUNS = 4;          // run 1 is a discarded warm-up
const KEEP = 3;          // median of the remaining three

function setServerState(theme, dir) {
    execFileSync('php', [path.join(__dirname, 'set-preview-state.php'), '1', theme, dir], {
        cwd: REPO,
        env: Object.assign({}, process.env, {
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
            CACHE_STORE: 'file',
        }),
        stdio: 'pipe',
    });
}

/* Web-vitals primitives. `largest-contentful-paint` and `layout-shift` are buffered entry types,
   so an observer registered after load still sees them -- which matters because the navigation has
   already happened by the time this evaluates. */
const VITALS = `(function () {
    return new Promise(function (resolve) {
        var lcp = 0, cls = 0, longTasks = 0, longTaskMs = 0;

        try {
            new PerformanceObserver(function (l) {
                l.getEntries().forEach(function (e) { if (e.startTime > lcp) lcp = e.startTime; });
            }).observe({ type: 'largest-contentful-paint', buffered: true });
        } catch (e) {}

        try {
            new PerformanceObserver(function (l) {
                l.getEntries().forEach(function (e) { if (!e.hadRecentInput) cls += e.value; });
            }).observe({ type: 'layout-shift', buffered: true });
        } catch (e) {}

        try {
            new PerformanceObserver(function (l) {
                l.getEntries().forEach(function (e) { longTasks++; longTaskMs += e.duration; });
            }).observe({ type: 'longtask', buffered: true });
        } catch (e) {}

        setTimeout(function () {
            var nav = performance.getEntriesByType('navigation')[0] || {};
            var paints = performance.getEntriesByType('paint');
            var fcp = (paints.find(function (p) { return p.name === 'first-contentful-paint'; }) || {}).startTime || 0;

            /* BYTES COME FROM THE RESOURCE TIMING API, not from counting response bodies.
               The first version summed content-length over Playwright response events and the
               figures moved by up to 80% between runs of the SAME url -- because runs 2..4 were
               served from the browser cache, so the listener saw far fewer bytes, and a response
               still in flight when the listener detached was not counted at all. A baseline with
               80% noise cannot detect a regression.

               decodedBodySize is what the page WEIGHS and is unaffected by cache state, so it is
               the baseline figure. transferSize is recorded beside it because the gap between
               them is the compression and cache story, and a reader should be able to see both.

               (No backticks in this comment, deliberately: a backtick here ends the enclosing
               template literal and the file stops parsing. It happened on the first draft of this
               very block, which is the fourth time in this phase -- see check-order-detail-live.cjs
               for the same note.) */
            var res = performance.getEntriesByType('resource');
            var decoded = nav.decodedBodySize || 0, transfer = nav.transferSize || 0;
            res.forEach(function (r) {
                decoded += r.decodedBodySize || 0;
                transfer += r.transferSize || 0;
            });

            resolve({
                decodedBytes: decoded,
                transferBytes: transfer,
                resources: res.length,
                ttfb: Math.round(nav.responseStart || 0),
                domContentLoaded: Math.round(nav.domContentLoadedEventEnd || 0),
                load: Math.round(nav.loadEventEnd || 0),
                fcp: Math.round(fcp),
                lcp: Math.round(lcp),
                cls: Math.round(cls * 1e4) / 1e4,
                longTasks: longTasks,
                longTaskMs: Math.round(longTaskMs),
                domNodes: document.getElementsByTagName('*').length,
            });
        }, 1200);
    });
})()`;

const SCREENS = [
    { name: 'dashboard', url: '/dashboard' },
    { name: 'order-list', url: '/order' },
    { name: 'product-list', url: '/product' },
    { name: 'customer-list', url: '/customer' },
    { name: 'coupon-list', url: '/coupon' },
    { name: 'settings', url: '/setting' },
];

function median(xs) {
    const s = xs.slice().sort((a, b) => a - b);
    return s.length % 2 ? s[(s.length - 1) / 2] : Math.round((s[s.length / 2 - 1] + s[s.length / 2]) / 2);
}

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });
    const results = [];
    let measured = 0, skipped = 0;

    for (const dir of ['ltr', 'rtl']) {
        setServerState('light', dir);
        const locale = dir === 'rtl' ? 'ar' : 'en';

        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await ctx.newPage();

        await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name="email"]', EMAIL);
        await page.fill('input[name="password"]', PASSWORD);
        await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
        if (page.url().includes('/login')) throw new Error('login failed');
        await page.goto(`${BASE}/change-language/${locale}`, { waitUntil: 'domcontentloaded' }).catch(() => {});

        for (const screen of SCREENS) {
            const runs = [];

            for (let i = 0; i < RUNS; i++) {
                const resp = await page.goto(`${BASE}${screen.url}`, { waitUntil: 'load' }).catch(() => null);
                const v = await page.evaluate(VITALS);
                runs.push(Object.assign({ status: resp ? resp.status() : 0 }, v));
            }

            if (runs[0].status !== 200) {
                console.log(`  skip  ${dir} ${screen.name} — HTTP ${runs[0].status}`);
                skipped++;
                continue;
            }

            /* Run 1 discarded: it pays for view compilation and route-cache population. */
            const kept = runs.slice(-KEEP);
            const agg = { screen: screen.name, url: screen.url, direction: dir };
            for (const k of ['ttfb', 'fcp', 'lcp', 'domContentLoaded', 'load', 'longTasks',
                             'longTaskMs', 'resources', 'decodedBytes', 'transferBytes', 'domNodes']) {
                agg[k] = median(kept.map((r) => r[k]));
            }
            agg.cls = Math.max(...kept.map((r) => r.cls));
            agg.coldTtfb = runs[0].ttfb;   // kept so the warm-up cost is visible rather than hidden
            results.push(agg);
            measured++;

            console.log(`  ok    ${dir} ${screen.name.padEnd(14)} ttfb=${String(agg.ttfb).padStart(5)}ms  `
                + `lcp=${String(agg.lcp).padStart(5)}ms  load=${String(agg.load).padStart(5)}ms  `
                + `cls=${agg.cls}  ${agg.resources} res  ${Math.round(agg.decodedBytes / 1024)} KiB  `
                + `(cold ttfb ${runs[0].ttfb}ms)`);
        }

        await ctx.close();
    }

    await browser.close();

    const out = {
        measuredAt: new Date().toISOString(),
        commit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: REPO, encoding: 'utf8' }).trim(),
        environment: {
            note: 'cloud sandbox, php artisan serve, SQLite fixture, no opcache warm, no gzip, no HTTP/2, no CDN. '
                + 'Absolute values are NOT production figures; they are a same-hardware starting point for comparison.',
            node: process.version,
            php: execFileSync('php', ['-r', 'echo PHP_VERSION;'], { cwd: REPO, encoding: 'utf8' }).trim(),
            cpus: require('os').cpus().length,
            method: `median of ${KEEP} runs after discarding 1 warm-up navigation; CLS is the worst of the ${KEEP}`,
        },
        screens: results,
    };

    fs.mkdirSync(path.dirname(OUT_JSON), { recursive: true });
    fs.writeFileSync(OUT_JSON, JSON.stringify(out, null, 2) + '\n');

    console.log(`\nmeasured: ${measured}   skipped: ${skipped}`);
    console.log(`baseline: ${OUT_JSON}`);
    process.exit(measured > 0 ? 0 : 1);
})();
