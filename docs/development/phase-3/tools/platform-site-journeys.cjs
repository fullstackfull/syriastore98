/**
 * PHASE 03 M7 — REAL JOURNEYS THROUGH THE PUBLIC SITE, AND EVERY LINK IT OFFERS.
 * =================================================================================================
 *
 * `platform-site-matrix.cjs` visits URLs. This CLICKS, which is a different question: a page can
 * be perfect and still be unreachable, and a link can be present and still go nowhere. Both are
 * invisible to a per-URL capture.
 *
 * WHAT IT DOES.
 *
 *   1. THREE JOURNEYS a visitor actually takes, each a sequence of clicks with an assertion after
 *      every step. They are written as a person would describe them — "I want to know what this
 *      costs", "I am stuck on publishing a theme", "is this available in Arabic" — rather than as
 *      a list of routes, because a journey that is green step by step can still be nonsense end to
 *      end.
 *
 *   2. A LINK CRAWL of every internal href the eleven public surfaces offer, in both languages.
 *      Every one is followed and must answer 200. A `route()` behind a stale `Route::has()`, a
 *      typo in a `related:` id, a category renamed on one side only — all of them produce a link
 *      that looks fine in the markup.
 *
 *   3. EVERY IN-PAGE ANCHOR resolved against the ids on that page. A table of contents whose links
 *      go nowhere is the specific failure M6 was built to avoid, and it cannot be seen without
 *      running the page.
 *
 *   4. CORE WEB VITALS (LCP, CLS) per surface.
 *      THESE ARE LAB NUMBERS AND NOT A USER-EXPERIENCE CLAIM. `php artisan serve` on localhost:
 *      no network, no TLS, no CDN, no HTTP/2, no opcache, SQLite rather than MySQL. INP is a field
 *      metric and is not measured at all. They are a regression baseline, which is the same thing
 *      M2-E's numbers are and the same caveat it records.
 *
 * usage: node docs/development/phase-3/tools/platform-site-journeys.cjs [--base URL] [--out DIR]
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const argOf = (n, d) => { const i = args.indexOf(n); return i === -1 ? d : args[i + 1]; };
const BASE = argOf('--base', 'http://127.0.0.1:8899').replace(/\/$/, '');
const OUT = argOf('--out', 'docs/development/phase-3/evidence/m7-platform-matrix');

const SURFACES = {
    en: ['/platform', '/platform/features', '/platform/pricing', '/platform/getting-started',
         '/platform/faq', '/platform/contact', '/platform/legal', '/platform/docs',
         '/platform/docs/your-storefront', '/platform/docs/your-storefront/edit-your-theme',
         '/platform/docs/search?q=theme'],
    ar: ['/platform', '/platform/features', '/platform/pricing', '/platform/getting-started',
         '/platform/faq', '/platform/contact', '/platform/legal', '/platform/docs',
         '/platform/docs/your-storefront', '/platform/docs/your-storefront/edit-your-theme',
         '/platform/docs/search?q=%D9%82%D8%A7%D9%84%D8%A8'],
};

/** A journey is a list of [what the visitor clicks, what must then be true]. */
const JOURNEYS = [
    {
        name: 'I want to know what this costs',
        steps: [
            { start: '/platform' },
            { click: 'a[href$="/platform/pricing"]', expectUrl: '/platform/pricing', expectText: 'Plans' },
            { click: 'a[href$="/platform/faq"]', expectUrl: '/platform/faq', expectText: 'Questions people ask' },
        ],
    },
    {
        name: 'I am stuck on publishing a theme',
        steps: [
            { start: '/platform' },
            { click: 'a[href$="/platform/docs"]', expectUrl: '/platform/docs', expectText: 'How to use Store Syria' },
            { click: 'a[href$="/platform/docs/your-storefront"]', expectUrl: '/platform/docs/your-storefront', expectText: 'Your storefront' },
            { click: 'a[href$="/preview-and-publish"]', expectUrl: '/preview-and-publish', expectText: 'Publishing applies the whole draft at once' },
        ],
    },
    {
        name: 'I am deciding whether to sign up',
        steps: [
            { start: '/platform' },
            { click: 'a[href$="/platform/getting-started"]', expectUrl: '/platform/getting-started', expectText: 'From signing up to a shop that is open' },
            { click: 'a[href$="/platform/contact"]', expectUrl: '/platform/contact', expectText: 'Talk to us' },
            { click: 'a[href$="/platform/legal"]', expectUrl: '/platform/legal', expectText: 'Legal information' },
        ],
    },
];

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    const report = { journeys: [], links: [], anchors: [], vitals: [] };

    // ---------------------------------------------------------------- journeys (English)
    {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const boot = await context.newPage();
        await boot.goto(`${BASE}/change-languages/en`, { waitUntil: 'networkidle' });
        await boot.close();

        for (const journey of JOURNEYS) {
            const page = await context.newPage();
            const trace = [];
            let ok = true;

            try {
                for (const step of journey.steps) {
                    if (step.start) {
                        await page.goto(BASE + step.start, { waitUntil: 'networkidle' });
                        trace.push({ step: `open ${step.start}`, url: page.url(), ok: true });
                        continue;
                    }

                    /* `:visible` IS LOAD-BEARING. The site renders its navigation TWICE — a
                       sidebar/header for wide screens and a <details> disclosure for narrow ones —
                       so the markup is shared and CSS decides which is shown. Without this filter
                       `.first()` lands on the hidden copy and the click times out against an
                       element that is present, correct and invisible. Measured: that is exactly
                       what the first run of this harness did, and it reads as a broken link. */
                    const link = page.locator(`${step.click}:visible`).first();
                    const count = await link.count();
                    if (count === 0) {
                        trace.push({ step: `click ${step.click}`, ok: false, why: 'no such link on the page' });
                        ok = false;
                        break;
                    }

                    await Promise.all([
                        page.waitForLoadState('networkidle'),
                        link.click(),
                    ]);

                    const url = page.url();
                    const body = await page.evaluate(() => document.body.innerText);
                    const urlOk = url.includes(step.expectUrl);
                    const textOk = body.includes(step.expectText);

                    trace.push({
                        step: `click ${step.click}`, url, ok: urlOk && textOk,
                        why: urlOk ? (textOk ? null : `text absent: ${step.expectText}`) : `url is not ${step.expectUrl}`,
                    });

                    if (!urlOk || !textOk) { ok = false; break; }
                }
            } catch (e) {
                trace.push({ step: 'threw', ok: false, why: e.message });
                ok = false;
            }

            report.journeys.push({ name: journey.name, ok, trace });
            await page.close();
        }

        await context.close();
    }

    // ------------------------------------------------- link crawl + anchors + vitals, per locale
    for (const locale of ['en', 'ar']) {
        const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
        const boot = await context.newPage();
        await boot.goto(`${BASE}/change-languages/${locale}`, { waitUntil: 'networkidle' });
        await boot.close();

        const seen = new Set();

        for (const surface of SURFACES[locale]) {
            const page = await context.newPage();
            await page.goto(BASE + surface, { waitUntil: 'networkidle' });

            const found = await page.evaluate(() => {
                const out = { links: [], anchors: [], ids: [] };
                document.querySelectorAll('a[href]').forEach((a) => {
                    const href = a.getAttribute('href');
                    if (!href) return;
                    if (href.startsWith('#')) out.anchors.push(href.slice(1));
                    else out.links.push(a.href);
                });
                document.querySelectorAll('[id]').forEach((el) => out.ids.push(el.id));
                return out;
            });

            /* In-page anchors are checked HERE, against this page's own ids. */
            const ids = new Set(found.ids);
            for (const anchor of new Set(found.anchors)) {
                const decoded = decodeURIComponent(anchor);
                report.anchors.push({
                    locale, surface, anchor: decoded,
                    ok: ids.has(anchor) || ids.has(decoded),
                });
            }

            /* Core Web Vitals, lab. */
            const vitals = await page.evaluate(() => new Promise((resolve) => {
                let lcp = 0, cls = 0;
                try {
                    new PerformanceObserver((l) => {
                        for (const e of l.getEntries()) lcp = Math.max(lcp, e.startTime);
                    }).observe({ type: 'largest-contentful-paint', buffered: true });
                    new PerformanceObserver((l) => {
                        for (const e of l.getEntries()) if (!e.hadRecentInput) cls += e.value;
                    }).observe({ type: 'layout-shift', buffered: true });
                } catch { /* an engine without these observers reports zeros, which the row shows */ }
                setTimeout(() => resolve({ lcp: Math.round(lcp), cls: Number(cls.toFixed(4)) }), 1200);
            }));
            report.vitals.push({ locale, surface, ...vitals });

            for (const href of new Set(found.links)) {
                if (!href.startsWith(BASE)) continue;        // off-origin is the matrix tool's job
                const key = `${locale} ${href}`;
                if (seen.has(key)) continue;
                seen.add(key);
                const res = await context.request.get(href, { maxRedirects: 5 });
                report.links.push({ locale, from: surface, href: href.replace(BASE, ''), status: res.status() });
            }

            await page.close();
        }

        await context.close();
    }

    await browser.close();

    const brokenLinks = report.links.filter((l) => l.status !== 200);
    const brokenAnchors = report.anchors.filter((a) => !a.ok);
    const failedJourneys = report.journeys.filter((j) => !j.ok);

    const summary = {
        base: BASE,
        journeys: report.journeys.length,
        journeysFailed: failedJourneys.length,
        internalLinksFollowed: report.links.length,
        brokenLinks: brokenLinks.length,
        inPageAnchorsChecked: report.anchors.length,
        brokenAnchors: brokenAnchors.length,
        worstLcpMs: report.vitals.reduce((m, v) => Math.max(m, v.lcp), 0),
        worstCls: report.vitals.reduce((m, v) => Math.max(m, v.cls), 0),
    };

    fs.writeFileSync(path.join(OUT, 'journeys.json'), JSON.stringify({ summary, ...report }, null, 2));
    console.log(JSON.stringify(summary, null, 2));

    for (const j of failedJourneys) {
        console.log(`JOURNEY FAILED  ${j.name}`);
        for (const t of j.trace) console.log(`    ${t.ok ? 'ok ' : 'XX '} ${t.step}  ${t.why || ''}`);
    }
    for (const l of brokenLinks) console.log(`BROKEN LINK  ${l.locale} ${l.from} -> ${l.href}  ${l.status}`);
    for (const a of brokenAnchors) console.log(`BROKEN ANCHOR  ${a.locale} ${a.surface} #${a.anchor}`);

    console.log('\n  THE VITALS ARE LAB NUMBERS: artisan serve on localhost, no TLS, no CDN, no');
    console.log('  HTTP/2, no opcache, SQLite not MySQL. A regression baseline, not a claim about');
    console.log('  what a visitor in Damascus experiences. INP is a field metric and is not here.');

    process.exit(summary.journeysFailed === 0 && summary.brokenLinks === 0 && summary.brokenAnchors === 0 ? 0 : 1);
})();
