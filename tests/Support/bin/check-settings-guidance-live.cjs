#!/usr/bin/env node
/**
 * GAP-060 WS5 — the settings instructions, measured in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `DeferredChoiceGuidanceTest` asserts the document a merchant
 * receives: that each control references its own hint, that the hint exists, that it is long enough
 * to be an instruction, and that it is Arabic on the Arabic page. None of that says whether the
 * sentence is READABLE, whether adding it broke a column, or whether it is actually laid out
 * right-to-left rather than merely written in Arabic.
 *
 * FOUR COMBINATIONS, NOT TWO. The admin's dark mode is a STYLESHEET SWAP driven by the stored
 * `cust_darklayout` setting (`layouts/app.blade.php:86`, `:189`, `:193`), not
 * `prefers-color-scheme` — so `colorScheme: 'dark'` on the context yields two identical LIGHT pages
 * and a clean sweep that measured nothing. The captures are produced by the Feature test with the
 * setting really stored, and that test asserts the four are four distinct documents.
 *
 * AND THE CAPTURES ARE WHOLE PAGES. The previous GAP-060 harness produced two false readings from
 * an under-built page: 2 stylesheets where the layout loads 30, and a missing wrapper that declares
 * a custom property, which scored labels at 1.09:1 against the wrong backdrop. So nothing is
 * assembled here — the capture is the real `GET /setting` document with the real AJAX panel spliced
 * into the container the browser would put it in, served beside the real `public/`.
 *
 * WHAT IS MEASURED
 *
 *   A  CONTRAST of every instruction and every legend on every touched panel, against its
 *      COMPOSITED backdrop (the first ancestor-or-self with a non-transparent background), at
 *      1440px and 375px, in all four combinations. 4.5:1 for body text.
 *
 *   B  THE RTL CHECK THAT CANNOT BE FAKED: on the Arabic pages each instruction's inline-START edge
 *      is its RIGHT edge, and it must line up with its container's right edge. A paragraph that is
 *      written in Arabic but stuck in LTR lines up on the LEFT instead and passes every
 *      source assertion.
 *
 *   C  NO HORIZONTAL OVERFLOW at 375px. Twelve paragraphs were added to columns that previously
 *      held a label and a select; this is the assertion that says none of them widened a panel.
 *
 *   D  EVERY `aria-describedby` RESOLVES IN THE LIVE DOM, which is the same question the Feature
 *      test asks of the bytes, asked again of the parsed tree.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - node with a resolvable Playwright (PLAYWRIGHT env var, a global install, or node_modules);
 *   - php on PATH, because the captures come from the Feature test.
 *
 * usage: node tests/Support/bin/check-settings-guidance-live.cjs
 */

'use strict';

const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../../..');
const PORT = Number(process.env.SS_WS5_PORT || 8981);
const MIN_BODY_CONTRAST = 4.5;

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
        } catch (e) { /* next */ }
    }

    throw new Error('PRECONDITION: Playwright is not resolvable. Set PLAYWRIGHT=<path>.');
}

const MIME = {
    '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript',
    '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
    '.gif': 'image/gif', '.svg': 'image/svg+xml', '.webp': 'image/webp', '.ico': 'image/x-icon',
    '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
    '.eot': 'application/vnd.ms-fontobject', '.map': 'application/json',
};

function serve(captureDir) {
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
            const file = path.resolve(root.dir, url.slice(root.prefix.length));

            if (!file.startsWith(path.resolve(root.dir))) {
                break;
            }
            if (fs.existsSync(file) && fs.statSync(file).isFile()) {
                res.writeHead(200, { 'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream' });

                /* THE CAPTURE'S ASSET ORIGIN IS REWRITTEN AT SERVE TIME, and this is the single
                   most important line in the harness.

                   `asset()` builds ABSOLUTE urls from APP_URL, which is `http://localhost` under
                   .env.testing — so every one of the thirty stylesheets requested port 80 and
                   failed. A `<base>` element does not help: absolute urls ignore it. The first run
                   of this harness therefore reported 264 of 420 checks FAILED, every single colour
                   reading `rgb(0, 0, 0)` on `rgba(0, 0, 0, 0)`, which is not a contrast failure at
                   all -- it is an unstyled document, and the sibling harness's own comment warns
                   about exactly this shape. Rewriting the origin is what makes the measurement a
                   measurement. */
                if (file.endsWith('.html')) {
                    let body = fs.readFileSync(file, 'utf8')
                        .split('http://localhost').join(`http://127.0.0.1:${PORT}`)
                        .split('https://localhost').join(`http://127.0.0.1:${PORT}`);

                    /* AND THE PAGE'S OWN SCRIPTS ARE REMOVED, for a measured reason rather than for
                       convenience. `setting/index.blade.php` fetches the active tab by AJAX on
                       ready, and its failure path is
                       `$('#pills-tabContent').empty().append($empty)` -- so against a static
                       capture the request fails and the page DELETES the panel that was spliced
                       into it. Second run of this harness: every page reported "the instrument
                       found NO hint or legend", 72 of 72 checks failed, and nothing was being
                       measured at all.

                       NOTHING UNDER MEASUREMENT DEPENDS ON THEM. The subject is text colour against
                       a composited backdrop, which is CSS; the admin's dark mode is server-side
                       markup (`data-ss-theme` on <body> at layouts/app.blade.php:86 plus the
                       style-dark.css link at :189/:193), not a client toggle; and direction comes
                       from the document's own `dir`. The thirty stylesheets are untouched, which is
                       the thing the first run got wrong. */
                    body = body.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');

                    res.end(body);

                    return;
                }

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

/* ================================================================== the in-page instrument ======
   One function, injected once, so every reading uses the same definitions of "the subject", "its
   painted backdrop" and "its container". */
const INSTRUMENT = `
window.__ws5 = (function () {
    /* The first ancestor-or-self with a background that actually paints. A paragraph whose own
       background is transparent is otherwise scored against rgba(0,0,0,0), which reads as a wall
       of 1.00:1 failures the capture itself invented. */
    function backdrop(node) {
        for (var n = node; n && n.nodeType === 1; n = n.parentElement) {
            var bg = getComputedStyle(n).backgroundColor;
            var m = String(bg).match(/[\\d.]+/g);

            if (m && (m.length < 4 || Number(m[3]) > 0.05)) {
                return bg;
            }
        }

        return getComputedStyle(document.body).backgroundColor;
    }

    function visible(e) {
        var cs = getComputedStyle(e);
        var r = e.getBoundingClientRect();

        return cs.display !== 'none' && cs.visibility !== 'hidden'
            && Number(cs.opacity) > 0.05 && r.width > 0 && r.height > 0;
    }

    /* Only the panel that was spliced in; the chrome around it is not this slice's work. */
    function panel() {
        return document.getElementById('pills-tabContent') || document.body;
    }

    function subjects() {
        var scope = panel();
        var out = [];

        scope.querySelectorAll('.ss-field__hint, legend').forEach(function (e) {
            if (!visible(e) || (e.textContent || '').trim() === '') {
                return;
            }

            var cs = getComputedStyle(e);
            var r = e.getBoundingClientRect();
            var host = e.parentElement;
            var hr = host ? host.getBoundingClientRect() : r;
            var hcs = host ? getComputedStyle(host) : cs;

            out.push({
                kind: e.classList.contains('ss-field__hint') ? 'hint' : 'legend',
                id: e.id || null,
                tag: e.tagName.toLowerCase(),
                text: (e.textContent || '').trim().slice(0, 40),
                color: cs.color,
                backdrop: backdrop(e),
                fontSize: cs.fontSize,
                direction: cs.direction,
                /* Inline-start edge, resolved for the element's own direction. The RTL check that
                   cannot be faked: a stuck-LTR paragraph matches on the left instead. */
                startEdge: cs.direction === 'rtl' ? Math.round(r.right) : Math.round(r.left),
                hostStartEdge: cs.direction === 'rtl'
                    ? Math.round(hr.right - parseFloat(hcs.paddingRight || '0'))
                    : Math.round(hr.left + parseFloat(hcs.paddingLeft || '0')),
                width: Math.round(r.width),
                height: Math.round(r.height),
            });
        });

        return out;
    }

    function describedBy() {
        var scope = panel();
        var dangling = [];
        var orphans = [];
        var refs = 0;

        scope.querySelectorAll('[aria-describedby]').forEach(function (e) {
            (e.getAttribute('aria-describedby') || '').split(/\\s+/).forEach(function (id) {
                if (!id) {
                    return;
                }
                refs++;

                if (!document.getElementById(id)) {
                    dangling.push(id);
                }
            });
        });

        scope.querySelectorAll('[id$="-help"], [id$="-group-help"]').forEach(function (e) {
            if (!scope.querySelector('[aria-describedby~="' + e.id + '"]')) {
                orphans.push(e.id);
            }
        });

        return { refs: refs, dangling: dangling, orphans: orphans };
    }

    /* THE OVERFLOW CHECK IS DIFFERENTIAL, and that is a correction rather than a convenience.
       An ABSOLUTE assertion reported four failures on the currency panel at 375px -- and measuring
       the same page with the hints removed gave the IDENTICAL document width (426px both ways).
       The cause is the Store Currency Rates table: .ss-table-wrap is 301px around a 434px table,
       which is the design system's own horizontally-scrolling table pattern and predates this
       slice entirely. (No backticks in this comment: the instrument is a template literal, and one
       inside it terminates the string -- which is how this note first crashed the harness.) An absolute threshold therefore answers "does this panel overflow", when the
       question this slice has to answer is "did adding twelve paragraphs and four hints make it
       overflow". So the page is measured twice: with the instructions, and with them removed. */
    function overflow() {
        var before = document.documentElement.scrollWidth;
        var hints = Array.prototype.slice.call(panel().querySelectorAll('.ss-field__hint'));
        var parked = hints.map(function (e) { return [e, e.nextSibling, e.parentNode]; });

        hints.forEach(function (e) { e.remove(); });

        var without = document.documentElement.scrollWidth;

        parked.forEach(function (t) { t[2].insertBefore(t[0], t[1]); });

        return {
            documentScroll: before,
            withoutInstructions: without,
            clientWidth: document.documentElement.clientWidth,
            hintCount: hints.length,
            dir: document.documentElement.getAttribute('dir') || 'ltr',
        };
    }

    return { subjects: subjects, describedBy: describedBy, overflow: overflow };
})();
`;

async function main() {
    const { chromium } = playwright();
    const captureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'ws5-settings-'));

    console.log('Rendering the four combinations through the Feature test...');
    execFileSync(
        'php',
        ['artisan', 'test', 'tests/Feature/Merchant/DeferredChoiceGuidanceTest.php',
            '--filter', 'test_the_touched_settings_panels_render_in_all_four_combinations'],
        {
            cwd: REPO,
            stdio: 'inherit',
            env: Object.assign({}, process.env, { SS_SETTINGS_HTML_DIR: captureDir }),
        }
    );

    const captures = fs.readdirSync(captureDir).filter((f) => f.endsWith('.html')).sort();

    if (captures.length === 0) {
        throw new Error('PRECONDITION: the Feature test wrote no captures, so this run proves nothing.');
    }

    console.log(`\n${captures.length} captures.\n`);

    const server = await serve(captureDir);
    const browser = await chromium.launch();

    let checks = 0;
    let failures = 0;
    let rtlChecks = 0;
    let refTotal = 0;
    const worst = [];
    const preexisting = [];

    try {
        for (const widthName of ['1440', '375']) {
            const width = Number(widthName);

            for (const capture of captures) {
                const [, tab, lang, theme] = capture.match(/^settings-(.+)-(en|ar)-(light|dark)\.html$/) || [];

                if (!tab) {
                    throw new Error(`unparseable capture name: ${capture}`);
                }

                const context = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
                const page = await context.newPage();

                await page.goto(`http://127.0.0.1:${PORT}/__capture/${capture}`, { waitUntil: 'load' });

                /* THE POSITIVE CONTROL. `SS_WS5_SELFTEST=1` breaks both of the two readings this
                   harness exists for -- the hint's colour and its direction -- so a run that still
                   reports "0 failed" is a rubber stamp and not an instrument. It is a flag rather
                   than a separate script because the thing being tested is this file's own
                   measurement, and a copy of it would drift.

                   This harness has already produced two genuine false readings and both are
                   recorded above: an unstyled page (264 of 420 "failures", every colour
                   rgb(0,0,0) on transparent) and a panel deleted by the page's own AJAX (72 of 72,
                   nothing measured). Those were caught by the readings being implausible; this
                   control is so that a quiet one would be caught too. */
                if (process.env.SS_WS5_SELFTEST === '1') {
                    await page.addStyleTag({
                        content: '#pills-tabContent .ss-field__hint {'
                            + ' color: #f2f3f5 !important;'
                            + ' background: #ffffff !important;'
                            + ' direction: ltr !important; }',
                    });
                }

                await page.addScriptTag({ content: INSTRUMENT });

                const subjects = await page.evaluate('window.__ws5.subjects()');
                const refs = await page.evaluate('window.__ws5.describedBy()');
                const over = await page.evaluate('window.__ws5.overflow()');

                refTotal += refs.refs;

                if (refs.dangling.length) {
                    failures++;
                    console.log(`  FAIL ${tab} ${lang}/${theme} @${width}: dangling aria-describedby -> ${refs.dangling.join(', ')}`);
                }

                if (refs.orphans.length) {
                    failures++;
                    console.log(`  FAIL ${tab} ${lang}/${theme} @${width}: orphan hint -> ${refs.orphans.join(', ')}`);
                }

                /* C — the instructions must not widen the page. 1px of tolerance for subpixel
                   rounding. Pre-existing overflow is REPORTED and not counted, with the figure, so
                   it cannot be mistaken for a clean page either. */
                checks++;

                if (over.documentScroll > over.withoutInstructions + 1) {
                    failures++;
                    console.log(`  FAIL ${tab} ${lang}/${theme} @${width}: the instructions widen the `
                        + `page from ${over.withoutInstructions} to ${over.documentScroll} `
                        + `(viewport ${over.clientWidth})`);
                } else if (over.documentScroll > over.clientWidth + 1) {
                    preexisting.push(`${tab} ${lang}/${theme} @${width}: ${over.documentScroll} > `
                        + `${over.clientWidth}, unchanged with the ${over.hintCount} instructions removed`);
                }

                if (subjects.length === 0) {
                    failures++;
                    console.log(`  FAIL ${tab} ${lang}/${theme} @${width}: the instrument found NO hint or legend `
                        + 'in the panel, so every contrast reading for this page would be vacuous');
                }

                for (const s of subjects) {
                    const ink = rgb(s.color);
                    const bg = rgb(s.backdrop);

                    checks++;

                    if (!ink || !bg) {
                        failures++;
                        console.log(`  FAIL ${tab} ${lang}/${theme} @${width}: unreadable colours on `
                            + `${s.kind} ${s.id || s.text} (${s.color} on ${s.backdrop})`);
                        continue;
                    }

                    const ratio = contrast(ink, bg);
                    worst.push({ ratio, where: `${tab} ${lang}/${theme} @${width} ${s.kind} ${s.id || s.text}` });

                    if (ratio < MIN_BODY_CONTRAST) {
                        failures++;
                        console.log(`  FAIL ${tab} ${lang}/${theme} @${width}: ${s.kind} `
                            + `${s.id || '"' + s.text + '"'} is ${ratio.toFixed(2)}:1 `
                            + `(${s.color} on ${s.backdrop}), needs ${MIN_BODY_CONTRAST}`);
                    }

                    /* B — the RTL check. Only for Arabic pages, and only for hints, whose host is
                       the column they were added to. A legend's own box is the fieldset. */
                    if (lang === 'ar' && s.kind === 'hint') {
                        rtlChecks++;
                        checks++;

                        if (s.direction !== 'rtl') {
                            failures++;
                            console.log(`  FAIL ${tab} ar/${theme} @${width}: hint ${s.id} computes `
                                + `direction:${s.direction} on the Arabic page, so it is written in `
                                + 'Arabic and laid out left to right');
                        } else if (Math.abs(s.startEdge - s.hostStartEdge) > 2) {
                            failures++;
                            console.log(`  FAIL ${tab} ar/${theme} @${width}: hint ${s.id} starts at `
                                + `${s.startEdge} but its container starts at ${s.hostStartEdge}`);
                        }
                    }
                }

                await context.close();
            }
        }
    } finally {
        await browser.close();
        server.close();
        fs.rmSync(captureDir, { recursive: true, force: true });
    }

    worst.sort((a, b) => a.ratio - b.ratio);

    console.log('\nLowest five contrast readings:');
    for (const w of worst.slice(0, 5)) {
        console.log(`  ${w.ratio.toFixed(2)}:1  ${w.where}`);
    }
    console.log(`Highest: ${worst.length ? worst[worst.length - 1].ratio.toFixed(2) : 'n/a'}:1`);

    if (preexisting.length) {
        console.log('\nPre-existing overflow, unchanged by this slice (reported, not counted):');
        for (const p of preexisting) {
            console.log(`  ${p}`);
        }
    }

    console.log(`\n${checks} checks, ${failures} failed. `
        + `${worst.length} contrast readings, ${rtlChecks} RTL edge checks, `
        + `${refTotal} aria-describedby references resolved.`);

    process.exit(failures === 0 ? 0 : 1);
}

main().catch((e) => {
    console.error(String(e && e.stack ? e.stack : e));
    process.exit(2);
});
