/**
 * PHASE 03 M7 — ACCESSIBILITY AND PAGE-WEIGHT OVER THE PUBLIC PLATFORM SURFACES.
 * =================================================================================================
 *
 * The same 11 surfaces as `platform-site-matrix.cjs`, in both languages and both appearances, run
 * through axe-core's WCAG 2.0/2.1 A and AA rule set — the same tag selection
 * `verify-a11y-matrix.cjs` uses, so the Phase 03 storefront result and this one are comparable.
 *
 * WHAT A PASS MEANS, EXACTLY, AND IT IS NARROWER THAN IT SOUNDS. Zero violations of the rules axe
 * can AUTOMATE, which is roughly a third of WCAG. Keyboard order, focus management, whether a
 * label actually DESCRIBES its control, and whether the Arabic reads like Arabic are not measured
 * here and are not claimed. The storefront milestones record the same limitation; repeating it is
 * deliberate, because "0 violations" is the number people quote.
 *
 * TWO RULES ARE REPORTED SEPARATELY RATHER THAN FOLDED IN. `region` and `landmark-one-main` are
 * axe BEST-PRACTICE rules with no WCAG tag; M2-E recorded four of them outstanding across the
 * vendored themes. They are run so a regression is visible, and counted apart so they cannot
 * inflate or deflate the WCAG number.
 *
 * PAGE WEIGHT is measured here rather than in a separate tool because the browser is already open
 * and the number is only meaningful beside the a11y run: these are static public pages with two
 * local stylesheets and no script, so a sudden jump means something was added that should not have
 * been.
 *
 * usage: node docs/development/phase-3/tools/platform-site-a11y.cjs [--base URL] [--out DIR]
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const AXE_PATH = path.join(process.cwd(), 'node_modules/axe-core/axe.min.js');

const args = process.argv.slice(2);
const argOf = (n, d) => { const i = args.indexOf(n); return i === -1 ? d : args[i + 1]; };

const BASE = argOf('--base', 'http://127.0.0.1:8899').replace(/\/$/, '');
const OUT = argOf('--out', 'docs/development/phase-3/evidence/m7-platform-matrix');

const URLS = {
    en: [
        '/platform', '/platform/features', '/platform/pricing', '/platform/getting-started',
        '/platform/faq', '/platform/contact', '/platform/legal',
        '/platform/docs', '/platform/docs/your-storefront',
        '/platform/docs/your-storefront/edit-your-theme', '/platform/docs/search?q=theme',
    ],
    ar: [
        '/platform', '/platform/features', '/platform/pricing', '/platform/getting-started',
        '/platform/faq', '/platform/contact', '/platform/legal',
        '/platform/docs', '/platform/docs/your-storefront',
        '/platform/docs/your-storefront/edit-your-theme',
        '/platform/docs/search?q=%D9%82%D8%A7%D9%84%D8%A8',
    ],
};

const BEST_PRACTICE_ONLY = new Set(['region', 'landmark-one-main', 'landmark-unique', 'heading-order']);

(async () => {
    if (!fs.existsSync(AXE_PATH)) {
        console.error(`axe-core is not installed at ${AXE_PATH}.`);
        process.exit(2);
    }
    const AXE = fs.readFileSync(AXE_PATH, 'utf8');

    fs.mkdirSync(OUT, { recursive: true });

    const browser = await chromium.launch();
    const rows = [];

    for (const scheme of ['light', 'dark']) {
        for (const locale of ['en', 'ar']) {
            const context = await browser.newContext({
                colorScheme: scheme,
                viewport: { width: 1280, height: 900 },
            });

            /* The product's own switch, not a written cookie: EncryptCookies discards a raw one.
               See the note in platform-site-matrix.cjs — it cost a run. */
            const boot = await context.newPage();
            await boot.goto(`${BASE}/change-languages/${locale}`, { waitUntil: 'networkidle' });
            await boot.close();

            for (const url of URLS[locale]) {
                const page = await context.newPage();

                /* KEYED BY URL RATHER THAN SUMMED PER EVENT, so a response that fires twice is
                   counted once. THE TOTAL DID NOT CHANGE when this was corrected, and that is the
                   useful part of the record: 650,917 bytes across 11 resources on the heaviest
                   page was already the honest figure, and a hand-check that disagreed had simply
                   measured the ENGLISH page, which loads four fewer font files. The number is
                   365 KB of design system, 28 KB of platform stylesheet and 257 KB of
                   self-hosted woff2 -- four Arabic weights and four Latin. */
                const bySize = new Map();
                page.on('response', (r) => {
                    try {
                        const len = r.headers()['content-length'];
                        bySize.set(r.url(), len ? Number(len) : 0);
                    } catch { /* a response that cannot report its size is not worth failing over */ }
                });

                await page.goto(BASE + url, { waitUntil: 'networkidle', timeout: 30000 });
                await page.addScriptTag({ content: AXE });

                const violations = await page.evaluate(async () => {
                    const r = await window.axe.run(document, {
                        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] },
                        resultTypes: ['violations'],
                    });
                    return r.violations.map((v) => ({
                        id: v.id,
                        impact: v.impact,
                        tags: v.tags,
                        n: v.nodes.length,
                        sample: v.nodes.slice(0, 3).map((n) => ({
                            target: n.target.join(' ').slice(0, 120),
                            html: n.html.slice(0, 120).replace(/\s+/g, ' '),
                        })),
                    }));
                });

                const wcag = violations.filter(
                    (v) => !BEST_PRACTICE_ONLY.has(v.id) && v.tags.some((t) => t.startsWith('wcag'))
                );
                const bestPractice = violations.filter((v) => !wcag.includes(v));

                rows.push({
                    url, locale, scheme,
                    wcagViolations: wcag,
                    bestPracticeViolations: bestPractice,
                    wcagCount: wcag.reduce((n, v) => n + v.n, 0),
                    bestPracticeCount: bestPractice.reduce((n, v) => n + v.n, 0),
                    transferredBytes: [...bySize.values()].reduce((a, b) => a + b, 0),
                    resources: bySize.size,
                });

                await page.close();
            }

            await context.close();
        }
    }

    await browser.close();

    const summary = {
        base: BASE,
        loads: rows.length,
        wcagViolationNodes: rows.reduce((n, r) => n + r.wcagCount, 0),
        bestPracticeViolationNodes: rows.reduce((n, r) => n + r.bestPracticeCount, 0),
        distinctWcagRules: [...new Set(rows.flatMap((r) => r.wcagViolations.map((v) => v.id)))],
        distinctBestPracticeRules: [...new Set(rows.flatMap((r) => r.bestPracticeViolations.map((v) => v.id)))],
        heaviestPageBytes: rows.reduce((m, r) => Math.max(m, r.transferredBytes), 0),
    };

    fs.writeFileSync(path.join(OUT, 'a11y.json'), JSON.stringify({ summary, rows }, null, 2));

    console.log(JSON.stringify(summary, null, 2));
    for (const r of rows.filter((x) => x.wcagCount > 0)) {
        for (const v of r.wcagViolations) {
            console.log(`WCAG  ${r.scheme}/${r.locale} ${r.url}  ${v.id} (${v.impact}) x${v.n}`);
            for (const s of v.sample) console.log(`        ${s.target}`);
        }
    }
    for (const r of rows.filter((x) => x.bestPracticeCount > 0)) {
        for (const v of r.bestPracticeViolations) {
            console.log(`best-practice  ${r.scheme}/${r.locale} ${r.url}  ${v.id} x${v.n}`);
        }
    }

    console.log('\n  ZERO WCAG VIOLATIONS IS NOT AN ACCESSIBILITY PASS: axe automates roughly a');
    console.log('  third of WCAG. Keyboard order, focus management and whether a label describes');
    console.log('  its control still need a person.');

    process.exit(summary.wcagViolationNodes === 0 ? 0 : 1);
})();
