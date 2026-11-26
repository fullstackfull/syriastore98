#!/usr/bin/env node
/**
 * GAP-099 / P03-B-06 — the merchant's saved section order, seen in a real browser, LTR and RTL.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER HERE. `StorefrontSectionOrderRendersTest` asserts the order of the
 * section markers in the bytes the route returns. That is the DOM order, and it is the right thing
 * to assert — but it is not the page. The storefront loads Tailwind's RUNTIME JIT CDN
 * (`themes/<theme>/assets/js/tailwind.js`) and injects its rules at render time, so every section's
 * spacing, its background band and its text colour come into existence only once a browser has run
 * the script. Three things therefore cannot be read out of any file:
 *
 *   1. that the sections still LAY OUT top to bottom in the new order, with no overlap and no
 *      zero-height section — a reordered page is a different sequence of stacking contexts;
 *   2. that the alternating `bg-gray-50` bands have not produced an unreadable pairing now that
 *      different sections are adjacent;
 *   3. that Arabic behaves the same way. Sections stack on the BLOCK axis, which RTL does not
 *      mirror, so the vertical order must be identical in both directions — and this project has
 *      shipped RTL-only defects before (18 contrast failures survived an already-fixed stylesheet).
 *
 * IT RENDERS THROUGH THE FEATURE TEST, not a dev server, for the reason the sibling harness
 * `check-theme-order-keyboard-live.cjs` records: `database/p2dev.sqlite` is zero bytes in a fresh
 * checkout and its fixture does not hold `Manage Themes`, which the save route requires. So the
 * test is run with `SS_HOME_HTML_DIR` and `SS_HOME_ASSET_BASE` set and this script photographs the
 * bytes it writes — the real route, the real save, the real theme, the real composer.
 *
 * THE ASSET BASE IS A LOCAL STATIC SERVER over `public/`, and the captures are served from the same
 * origin, because every stylesheet and script URL in the layout is built with `asset()`. Opening
 * the files over `file://` would leave Tailwind unloaded and measure an unstyled document.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - node with a resolvable Playwright (PLAYWRIGHT env var, a global install, or node_modules);
 *   - php on PATH.
 *
 * usage: node tests/Support/bin/check-storefront-section-order-live.cjs
 */

'use strict';

const fs = require('fs');
const os = require('os');
const http = require('http');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../../..');
const PORT = Number(process.env.SS_PORT || 8913);

/* The nine stylique markers, kept in step with the Feature test by being read out of it rather
   than written down a second time. A drift here would measure a page this script invented. */
const MARKERS = (() => {
    const src = fs.readFileSync(
        path.join(REPO, 'tests/Feature/Storefront/StorefrontSectionOrderRendersTest.php'), 'utf8');
    const block = src.match(/private const MARKERS = \[([\s\S]*?)\];/);

    if (!block) {
        throw new Error('PRECONDITION: StorefrontSectionOrderRendersTest no longer declares MARKERS.');
    }

    const out = {};
    for (const m of block[1].matchAll(/'([a-z_]+)'\s*=>\s*'([^']+)'/g)) {
        out[m[1]] = m[2];
    }
    if (Object.keys(out).length !== 9) {
        throw new Error(`PRECONDITION: expected 9 markers, read ${Object.keys(out).length}.`);
    }

    return out;
})();

const EXPECTED_DEFAULT = ['slider', 'logo', 'collection', 'product', 'category', 'bestseller', 'testimonial', 'blog', 'service'];
const EXPECTED_REORDERED = ['testimonial', 'logo', 'collection', 'bestseller', 'category', 'product', 'slider', 'blog', 'service'];

function playwright() {
    const candidates = [
        process.env.PLAYWRIGHT,
        'playwright',
        '/opt/node-tools/node_modules/playwright',
        path.join(REPO, 'node_modules/playwright'),
    ].filter(Boolean);

    for (const c of candidates) {
        try {
            return require(c);
        } catch (e) { /* try the next one */ }
    }

    throw new Error('PRECONDITION: Playwright is not resolvable. Set PLAYWRIGHT=<path>.');
}

const MIME = {
    '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript',
    '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.ico': 'image/x-icon',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf', '.eot': 'application/vnd.ms-fontobject',
    '.map': 'application/json',
};

/** `public/` plus the capture directory at `/__capture/`. Nothing is written into the repository. */
function serve(captureDir) {
    /* `themes/` IS SERVED FROM THE REPOSITORY ROOT, NOT FROM `public/`, and that matters. The
       layout builds every theme asset as `asset('themes/<theme>/assets/...')`, but the files live
       at `themes/` beside `app/` — `public/themes` does not exist (measured). Without this root the
       477 KB runtime Tailwind bundle 404s, the page renders unstyled, and every computed colour
       reads `rgb(0, 0, 0)` on `rgba(0, 0, 0, 0)` — which looks exactly like a wall of 1.00:1
       contrast failures. That artefact was produced once here before this root was added. */
    const roots = [
        { prefix: '/__capture/', dir: captureDir },
        { prefix: '/themes/', dir: path.join(REPO, 'themes') },
        { prefix: '/', dir: path.join(REPO, 'public') },
    ];

    const server = http.createServer((req, res) => {
        const url = decodeURIComponent(req.url.split('?')[0]);

        for (const root of roots) {
            if (!url.startsWith(root.prefix)) {
                continue;
            }
            const rel = url.slice(root.prefix.length);
            const file = path.resolve(root.dir, rel);

            if (!file.startsWith(path.resolve(root.dir))) {
                break;                                      // no traversal out of either root
            }
            if (fs.existsSync(file) && fs.statSync(file).isFile()) {
                res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });
                res.end(fs.readFileSync(file));

                return;
            }
        }

        res.writeHead(404, { 'content-type': 'text/plain' });
        res.end('not found');
    });

    return new Promise((resolve) => server.listen(PORT, '127.0.0.1', () => resolve(server)));
}

function relativeLuminance([r, g, b]) {
    const f = (c) => {
        const s = c / 255;

        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };

    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

function contrast(a, b) {
    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);

    return (l1 + 0.05) / (l2 + 0.05);
}

function rgb(value) {
    const m = String(value).match(/(\d+(?:\.\d+)?)/g);

    return m && m.length >= 3 ? [Number(m[0]), Number(m[1]), Number(m[2])] : null;
}

(async () => {
    const captureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ss-section-order-'));
    const shotDir = process.env.SS_SHOT_DIR || fs.mkdtempSync(path.join(os.tmpdir(), 'ss-section-order-png-'));
    let failures = 0;
    let checks = 0;
    /** Per capture: heading text -> contrast ratio, so the two orders can be compared directly. */
    const measured = {};
    const note = (ok, message) => {
        checks += 1;
        if (!ok) {
            failures += 1;
        }
        console.log(`${ok ? '  ok  ' : '  FAIL'} ${message}`);
    };

    console.log('Rendering the real route through the Feature test...');
    execFileSync('php', [
        'artisan', 'test', 'tests/Feature/Storefront/StorefrontSectionOrderRendersTest.php',
    ], {
        cwd: REPO,
        stdio: 'inherit',
        env: Object.assign({}, process.env, {
            SS_HOME_HTML_DIR: captureDir,
            SS_HOME_ASSET_BASE: `http://127.0.0.1:${PORT}`,
        }),
    });

    const captures = ['en-default', 'en-reordered', 'ar-default', 'ar-reordered'];
    for (const name of captures) {
        const f = path.join(captureDir, `home-${name}.html`);
        if (!fs.existsSync(f)) {
            throw new Error(`PRECONDITION: the test wrote no capture for ${name}.`);
        }
    }

    const server = await serve(captureDir);
    const { chromium } = playwright();
    const browser = await chromium.launch();

    for (const name of captures) {
        const expected = name.endsWith('reordered') ? EXPECTED_REORDERED : EXPECTED_DEFAULT;
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });

        await page.goto(`http://127.0.0.1:${PORT}/__capture/home-${name}.html`, { waitUntil: 'load' });
        await page.waitForTimeout(1200);                    // Tailwind's JIT injects after load

        console.log(`\n=== ${name} ===`);

        const dir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
        note(dir === (name.startsWith('ar') ? 'rtl' : 'ltr'),
            `document dir is ${dir} (expected ${name.startsWith('ar') ? 'rtl' : 'ltr'})`);

        const injected = await page.evaluate(() => {
            let n = 0;
            for (const sheet of document.styleSheets) {
                try {
                    if (!sheet.href) { n += sheet.cssRules.length; }
                } catch (e) { /* cross-origin */ }
            }

            return { rules: n, tailwind: typeof window.tailwind !== 'undefined' };
        });
        note(injected.tailwind, `window.tailwind is defined (runtime JIT really loaded)`);
        note(injected.rules > 100, `${injected.rules} rules injected inline at runtime`);

        /* THE LAID-OUT ORDER, from getBoundingClientRect and not from the DOM, because that is the
           claim: the merchant's order is what a shopper SEES. */
        const boxes = await page.evaluate((markers) => {
            const out = {};
            const html = document.documentElement.outerHTML;

            for (const [block, marker] of Object.entries(markers)) {
                const at = html.indexOf(marker);
                if (at === -1) { out[block] = null; continue; }

                /* Find the element whose own markup carries the marker, then take its painted box. */
                const all = Array.from(document.querySelectorAll('section, section *'));
                const hit = all.find((el) => el.outerHTML.includes(marker));
                if (!hit) { out[block] = null; continue; }

                const section = hit.closest('section') || hit;
                const r = section.getBoundingClientRect();
                const cs = getComputedStyle(section);
                out[block] = {
                    top: r.top + window.scrollY, height: r.height, width: r.width,
                    background: cs.backgroundColor,
                };
            }

            return out;
        }, MARKERS);

        const missing = Object.entries(boxes).filter(([, v]) => v === null).map(([k]) => k);
        note(missing.length === 0, `all nine sections present (missing: ${missing.join(',') || 'none'})`);

        const laidOut = Object.entries(boxes)
            .filter(([, v]) => v !== null)
            .sort((a, b) => a[1].top - b[1].top)
            .map(([k]) => k);

        note(JSON.stringify(laidOut) === JSON.stringify(expected),
            `painted top-to-bottom order\n         got      ${laidOut.join(' > ')}\n         expected ${expected.join(' > ')}`);

        for (const [block, box] of Object.entries(boxes)) {
            if (box === null) { continue; }
            note(box.height > 10, `${block} has a painted height (${Math.round(box.height)}px)`);
            note(box.width > 600, `${block} spans the viewport (${Math.round(box.width)}px)`);
        }

        /* No two sections may overlap: a reordering must not create a stacking collision. */
        const sorted = Object.entries(boxes).filter(([, v]) => v !== null).sort((a, b) => a[1].top - b[1].top);
        let overlaps = 0;
        for (let i = 1; i < sorted.length; i += 1) {
            if (sorted[i][1].top + 1 < sorted[i - 1][1].top + sorted[i - 1][1].height) {
                overlaps += 1;
                console.log(`         overlap: ${sorted[i - 1][0]} and ${sorted[i][0]}`);
            }
        }
        note(overlaps === 0, `no two sections overlap vertically`);

        /* CONTRAST, over the headings the reordering moves. The bands alternate — stylique paints
           `bg-gray-50` on some sections and nothing on others — so a section that has changed
           neighbours may have changed background.

           IMAGE-BACKED HEADINGS ARE REPORTED AS NOT ASSESSABLE, NOT AS FAILURES, and that is a
           correction to this instrument rather than a concession. A category name inside the
           collection and category grids is `text-white` over an absolutely-positioned `<img>` with
           a `bg-black bg-opacity-20` overlay as a SIBLING, so walking ancestors for a background
           colour finds the section's own `bg-gray-50` and computes white-on-near-white: five
           headings read 1.05:1 that way, in the DEFAULT order as well as the reordered one. The
           backdrop is a merchant-supplied photograph; its contrast is a real question and it is
           not this change's, which is why it is named here instead of being averaged away. */
        const headings = await page.evaluate(() => Array.from(document.querySelectorAll('section h2, section h3'))
            .filter((el) => el.textContent.trim() !== '' && el.getClientRects().length > 0)
            .map((el) => {
                let bg = 'rgba(0, 0, 0, 0)';
                for (let n = el; n; n = n.parentElement) {
                    const c = getComputedStyle(n).backgroundColor;
                    if (c && !/rgba\(0, 0, 0, 0\)|transparent/.test(c)) { bg = c; break; }
                }

                /* IS ANYTHING PAINTED BEHIND THIS TEXT THAT IS NOT A BACKGROUND COLOUR?
                   The first version of this test looked for an `<img>` with an `absolute` class as
                   a SIBLING and missed the case that actually matters: in both category grids the
                   image is IN FLOW and the text is the absolutely-positioned thing on top of it.
                   So the question is asked from the text's side — is this text in an absolutely
                   positioned box whose positioned container also holds an image — which covers the
                   slider (absolute text, absolute image) and the grids (absolute text, in-flow
                   image) with one rule. */
                let imageBacked = false;
                for (let n = el; n && n.tagName !== 'SECTION'; n = n.parentElement) {
                    const cs = getComputedStyle(n);

                    if (cs.backgroundImage && cs.backgroundImage !== 'none') { imageBacked = true; break; }

                    if (cs.position === 'absolute' || cs.position === 'fixed') {
                        const host = n.offsetParent || n.parentElement;
                        if (host && host.querySelector('img')) { imageBacked = true; break; }
                    }
                }

                return {
                    text: el.textContent.trim().slice(0, 36),
                    colour: getComputedStyle(el).color,
                    background: bg,
                    imageBacked,
                    size: parseFloat(getComputedStyle(el).fontSize),
                    weight: getComputedStyle(el).fontWeight,
                };
            }));

        note(headings.length >= 5, `${headings.length} visible section headings measured`);

        let worst = { ratio: 99, text: '-' };
        const ratios = {};
        let unassessable = 0;

        for (const h of headings) {
            const fg = rgb(h.colour);
            const bg = rgb(h.background);
            if (!fg || !bg) { continue; }
            const ratio = contrast(fg, bg);
            const large = h.size >= 24 || (h.size >= 18.66 && Number(h.weight) >= 700);
            const floor = large ? 3.0 : 4.5;
            ratios[h.text] = ratio.toFixed(2);

            if (h.imageBacked) {
                unassessable += 1;
                console.log(`  n/a  "${h.text}" over an image backdrop — computed-style contrast `
                    + `(${ratio.toFixed(2)}:1 on ${h.background}) is not the real pairing`);
                continue;
            }

            if (ratio < worst.ratio) { worst = { ratio, text: h.text }; }
            note(ratio >= floor,
                `"${h.text}" ${ratio.toFixed(2)}:1 on ${h.background} (floor ${floor}, ${Math.round(h.size)}px/${h.weight})`);
        }
        console.log(`         worst assessable heading contrast: ${worst.ratio.toFixed(2)}:1 ("${worst.text}"); `
            + `${unassessable} over an image backdrop`);
        measured[name] = ratios;

        const shot = path.join(shotDir, `home-${name}.png`);
        await page.screenshot({ path: shot, fullPage: true });
        console.log(`         screenshot: ${shot}`);

        await page.close();
    }

    /* THE COMPARISON THIS CHANGE IS ACTUALLY ACCOUNTABLE FOR. Reordering moves sections between
       the alternating background bands, so the question is not "is the storefront accessible" — it
       is "did reordering change any heading's pairing". Every heading present in both captures must
       read the same ratio in both, in each locale. */
    for (const locale of ['en', 'ar']) {
        const a = measured[`${locale}-default`] || {};
        const b = measured[`${locale}-reordered`] || {};
        const shared = Object.keys(a).filter((k) => k in b);

        note(shared.length >= 8, `${locale}: ${shared.length} headings present in both orders`);

        const moved = shared.filter((k) => a[k] !== b[k]);
        note(moved.length === 0,
            `${locale}: reordering changed no heading's contrast`
            + (moved.length ? `\n         changed: ${moved.map((k) => `"${k}" ${a[k]} -> ${b[k]}`).join('; ')}` : ''));
    }

    await browser.close();
    server.close();

    console.log(`\n${checks} checks, ${failures} failed`);
    console.log(`screenshots in ${shotDir}`);
    process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
    console.error(e);
    process.exit(2);
});
