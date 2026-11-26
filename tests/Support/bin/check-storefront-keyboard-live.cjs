#!/usr/bin/env node
/**
 * GAP-095 / P03-A-04 — STOREFRONT KEYBOARD OPERABILITY, MEASURED IN A REAL BROWSER.
 * =================================================================================================
 *
 * The row's first clause is "measure keyboard operability of the storefront (tab order, focus
 * visibility, operable controls) and record it". `StorefrontKeyboardOperabilityTest` closed three
 * WCAG failures in the markup; none of the three is this. A skip link that is first in the DOM is
 * not a measured TAB ORDER, a focus ring that is declared in a stylesheet is not a ring that is
 * VISIBLE against the thing it is drawn on, and no source assertion can see a <div> that responds
 * to a click and not to Enter.
 *
 * WHAT ONLY A BROWSER CAN ANSWER HERE, and why each needs a real one:
 *
 *   A  TAB ORDER. The sequence is produced by the engine from the DOM, `tabindex`, `display`,
 *      `visibility`, `inert` and the shape of the layout. It is not readable from markup, and in
 *      RTL IT IS NOT A MIRROR OF THE LTR ANSWER — a row that reads correctly left-to-right can tab
 *      in a sequence that makes no sense right-to-left. Measured by pressing Tab, not by listing
 *      selectors.
 *
 *   B  FOCUS VISIBILITY. `:focus-visible` only matches for KEYBOARD focus, so `element.focus()`
 *      from script does not test it — the ring has to be reached by Tab. And the test is not "is a
 *      rule declared" but "does the indicator differ from the unfocused state AND clear 3:1
 *      against what it is drawn on" (WCAG 1.4.11). Tailwind's own `outline-none` is
 *      `outline: 2px solid transparent`: present, inherited, and invisible.
 *
 *   C  OPERABLE CONTROLS. An element that responds to a click but cannot be reached or activated
 *      from the keyboard is only visible as such once the handlers and the computed cursor are in
 *      the live DOM.
 *
 * ------------------------------------------------------------------------------------------------
 *  THE SCRIPTS ARE KEPT. THIS IS THE OPPOSITE DECISION FROM THE MERCHANT-PANEL HARNESS.
 * ------------------------------------------------------------------------------------------------
 *
 * `check-settings-guidance-live.cjs` strips `<script>` because the settings page's own AJAX deletes
 * the panel on failure. Doing that here would destroy the measurement rather than enable it: every
 * storefront layout loads `themes/<theme>/assets/js/tailwind.js`, the runtime JIT CDN, and
 * CLAUDE.md §6 records 270-294 rules injected INLINE AT RUNTIME on a rendered page. A utility beats
 * the theme stylesheet on source order at equal specificity, so a ring colour read without Tailwind
 * is read against a cascade no shopper ever sees. So scripts run, `themes/` and `public/` are both
 * served locally, and INJECTED TAILWIND RULES ARE A PREMISE — asserted per page before a single
 * colour is read.
 *
 * NO EGRESS. The only external reference in any of the four layouts is the Google Fonts stylesheet
 * (`fonts.googleapis.com`, one `<link>` in each). Every request to a host other than the local
 * origin is ABORTED, which makes the run deterministic and keeps it offline; a substituted font
 * changes glyph metrics, not colour, tab order or operability, so nothing measured here depends on
 * it. The abort count is reported so a silent change of that fact is visible.
 *
 * ------------------------------------------------------------------------------------------------
 *  PRECONDITIONS — it fails loudly rather than reporting a clean pass without them
 * ------------------------------------------------------------------------------------------------
 *
 *   - node with a resolvable Playwright (PLAYWRIGHT env var, a global install, or node_modules);
 *   - php on PATH: the captures come from `StorefrontKeyboardMeasurementTest`, which asserts the
 *     landed path, the theme and the direction of every one of them before writing it out.
 *
 * usage: node tests/Support/bin/check-storefront-keyboard-live.cjs
 *        SS_SF_SELFTEST=1 node ...   (positive control: must report failures)
 */

'use strict';

const fs = require('fs');
const http = require('http');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');

const REPO = path.resolve(__dirname, '../../..');
const PORT = Number(process.env.SS_SF_PORT || 8993);

/** WCAG 1.4.11 non-text contrast: a focus indicator is a UI component boundary. */
const MIN_INDICATOR_CONTRAST = 3.0;

/** Enough presses to leave any storefront header and reach content; pages here use 120-260. */
const MAX_TAB_PRESSES = Number(process.env.SS_SF_MAX_TABS || 420);

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
    '.mjs': 'text/javascript', '.json': 'application/json', '.png': 'image/png',
    '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.gif': 'image/gif', '.svg': 'image/svg+xml',
    '.webp': 'image/webp', '.ico': 'image/x-icon', '.woff': 'font/woff', '.woff2': 'font/woff2',
    '.ttf': 'font/ttf', '.eot': 'application/vnd.ms-fontobject', '.map': 'application/json',
};

/**
 * `public/`, `themes/` and the capture directory.
 *
 * `themes/` IS SERVED FROM THE REPOSITORY ROOT, NOT FROM `public/`: the layouts build every theme
 * asset as `asset('themes/<theme>/assets/...')` while the files live at `themes/` beside `app/`,
 * and `public/themes` does not exist. Without this root the 477KB runtime Tailwind bundle 404s, the
 * page renders with the theme sheet alone, and every colour reading is against the wrong cascade.
 */
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
                res.writeHead(200, {
                    'content-type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream',
                });

                /* The capture's asset origin is rewritten at serve time. `asset()` builds ABSOLUTE
                   urls from APP_URL (`http://localhost` under .env.testing), so without this every
                   stylesheet and the Tailwind bundle request port 80 and fail — which does not look
                   like a 404, it looks like a wall of contrast failures the capture invented. The
                   merchant-panel harness learned this the expensive way: 264 of 420 checks "failed"
                   with every colour rgb(0,0,0) on rgba(0,0,0,0). SCRIPTS ARE NOT STRIPPED HERE. */
                if (file.endsWith('.html')) {
                    res.end(
                        fs.readFileSync(file, 'utf8')
                            .split('http://localhost').join(`http://127.0.0.1:${PORT}`)
                            .split('https://localhost').join(`http://127.0.0.1:${PORT}`)
                    );

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

/** `[r,g,b,a]`, or null when the string names no colour. Alpha matters: transparent is a failure. */
function rgba(value) {
    const m = String(value).match(/(\d+(?:\.\d+)?)/g);

    if (!m || m.length < 3) {
        return null;
    }

    return [Number(m[0]), Number(m[1]), Number(m[2]), m.length > 3 ? Number(m[3]) : 1];
}

module.exports = { relativeLuminance, contrast, rgba };

/* ================================================================== the in-page instrument =====
   Injected once per page, so the enumeration, the "is it focusable" rule and the composited
   backdrop are one definition rather than three. */
const INSTRUMENT = String.raw`
window.__sfkb = (function () {
    var STAMP = 'data-ss-kb';

    function backdrop(node) {
        for (var n = node; n && n.nodeType === 1; n = n.parentElement) {
            var bg = getComputedStyle(n).backgroundColor;
            var m = String(bg).match(/[\d.]+/g);

            if (m && (m.length < 4 || Number(m[3]) > 0.05)) {
                return bg;
            }
        }

        return getComputedStyle(document.body).backgroundColor;
    }

    function rendered(e) {
        var cs = getComputedStyle(e);
        var r = e.getBoundingClientRect();

        return cs.display !== 'none' && cs.visibility !== 'hidden'
            && cs.opacity !== '0' && r.width > 0 && r.height > 0;
    }

    function describe(e) {
        var r = e.getBoundingClientRect();
        var cs = getComputedStyle(e);

        return {
            stamp: e.getAttribute(STAMP),
            tag: e.tagName.toLowerCase(),
            type: e.getAttribute('type') || null,
            id: e.id || null,
            cls: String(e.className || '').slice(0, 60),
            role: e.getAttribute('role') || null,
            tabindex: e.getAttribute('tabindex'),
            href: (e.getAttribute('href') || '').slice(0, 40) || null,
            /* AN ACCESSIBLE NAME, AND EVERY MECHANISM THAT REALLY SUPPLIES ONE. Two instrument
               gaps were found here by checking the markup instead of trusting the reading, and
               each would have become a page of invented WCAG findings:

                 - a logo link is named by its <img alt>, so textContent alone called three named
                   anchors per page nameless;
                 - A FORM CONTROL IS NAMED BY ITS <label for>, which is the PRIMARY mechanism and
                   was missing entirely. That produced 18 "nameless" findings -- #email, #password
                   and #remember on the login page of all three themes in both languages -- and
                   login.blade.php:35, :42 and :53 each carry a correct label-for. Tab,
                   the markup and the screen reader were all fine; the instrument was not. */
            name: (function () {
                var labelled = e.getAttribute('aria-labelledby');
                var byId = '';

                if (labelled) {
                    labelled.split(/\s+/).forEach(function (id) {
                        var t = id && document.getElementById(id);
                        byId += t ? ' ' + (t.textContent || '') : '';
                    });
                }

                var byLabel = '';

                if (e.id) {
                    var lbl = document.querySelector('label[for="' + CSS.escape(e.id) + '"]');
                    byLabel = lbl ? (lbl.textContent || '') : '';
                }

                if (!byLabel.trim() && e.closest) {
                    var wrap = e.closest('label');
                    byLabel = wrap ? (wrap.textContent || '') : '';
                }

                var img = e.querySelector ? e.querySelector('img[alt]') : null;
                var svgTitle = e.querySelector ? e.querySelector('svg title') : null;

                return (e.getAttribute('aria-label')
                    || byId.trim()
                    || byLabel.trim()
                    || (e.textContent || '').trim().replace(/\s+/g, ' ')
                    || (img ? img.getAttribute('alt') : '')
                    || (svgTitle ? svgTitle.textContent : '')
                    || e.getAttribute('title')
                    || (e.tagName === 'INPUT' ? (e.getAttribute('placeholder') || '') : '')
                    || '').trim().replace(/\s+/g, ' ').slice(0, 40);
            })(),
            top: Math.round(r.top + window.scrollY),
            left: Math.round(r.left),
            right: Math.round(r.right),
            width: Math.round(r.width),
            height: Math.round(r.height),
            /* OFF-SCREEN BUT STILL TABBABLE -- the finding the first run mis-described as a
               vertical inversion. A closed off-canvas panel translated past the viewport edge is
               RENDERED, so the engine keeps it in the tab order: focus leaves the visible page and
               a sighted keyboard user has no idea where it went (WCAG 2.4.3 and 2.4.7). Measured
               against the LAYOUT viewport, not the scroll position, because horizontal placement
               off the right edge is how every one of these is hidden. */
            offscreen: (r.right <= 0 || r.left >= window.innerWidth
                || r.bottom <= -window.scrollY),
            viewportWidth: window.innerWidth,
            outline: cs.outlineStyle + '|' + cs.outlineWidth + '|' + cs.outlineColor
                + '|' + cs.outlineOffset,
            boxShadow: cs.boxShadow,
            borderColor: cs.borderTopColor + ' ' + cs.borderRightColor + ' '
                + cs.borderBottomColor + ' ' + cs.borderLeftColor,
            borderWidth: cs.borderTopWidth + ' ' + cs.borderRightWidth + ' '
                + cs.borderBottomWidth + ' ' + cs.borderLeftWidth,
            background: cs.backgroundColor,
            color: cs.color,
            backdrop: backdrop(e),
            /* THE SURFACE AN OFFSET OUTLINE IS ACTUALLY DRAWN ON. An outline is painted OUTSIDE
               the border box, so with a positive outline-offset the ring sits on the PARENT's
               background with a gap of that background between it and the element. Measuring it
               against the element's own fill is the same class of error as measuring a modal's
               label against the page instead of the modal: it reads a real colour off the wrong
               surface. public/css/storefront-keyboard.css:117 sets +2px for the general rule and
               :91 a NEGATIVE offset for the inset one, so the sign decides which surface counts. */
            parentBackdrop: e.parentElement ? backdrop(e.parentElement)
                : getComputedStyle(document.body).backgroundColor,
            textDecoration: cs.textDecorationLine + ' ' + cs.textDecorationThickness,
        };
    }

    /* Every element the engine could stop on, stamped so identity across two passes is exact
       rather than matched on a selector that may not be unique. */
    function stampTabbables() {
        var selector = 'a[href], area[href], button, input, select, textarea, summary,'
            + ' iframe, object, embed, audio[controls], video[controls],'
            + ' [contenteditable], [tabindex]';
        var all = Array.prototype.slice.call(document.querySelectorAll(selector));
        var n = 0;
        var out = [];

        all.forEach(function (e) {
            if (e.disabled) {
                return;
            }
            if (e.getAttribute('tabindex') === '-1' && !e.hasAttribute('data-ss-kb-force')) {
                return;
            }
            if (e.closest('[inert]') || e.closest('[aria-hidden="true"]')) {
                return;
            }
            if (e.type === 'hidden') {
                return;
            }
            /* NOT RENDERED MEANS NOT TABBABLE, AND LEAVING THESE IN INVENTED 180 FINDINGS.
               The first run enumerated the closed language dropdown's six links on every page --
               Arabic, Danish, German, English, Spanish, French -- and then reported all of them as
               "tabbable but never reached". Tab was right and the enumeration was wrong: an element
               in a display:none dropdown is correctly skipped by the engine. Excluding them is what
               makes UNREACHED mean something, namely a VISIBLE control the keyboard cannot get to. */
            if (!rendered(e)) {
                return;
            }

            e.setAttribute(STAMP, String(n));
            out.push(describe(e));
            n++;
        });

        return out;
    }

    /* The injected-rule premise: how many CSS rules exist in stylesheets with NO href, which is
       what the runtime JIT writes into the document. */
    function injectedRuleCount() {
        var total = 0;

        Array.prototype.slice.call(document.styleSheets).forEach(function (sheet) {
            if (sheet.href) {
                return;
            }
            try {
                total += sheet.cssRules ? sheet.cssRules.length : 0;
            } catch (e) { /* cross-origin; cannot be ours */ }
        });

        return total;
    }

    function activeDescription() {
        var e = document.activeElement;

        if (!e || e === document.body || e === document.documentElement) {
            return null;
        }

        var d = describe(e);

        d.matchesFocusVisible = (function () {
            try {
                return e.matches(':focus-visible');
            } catch (err) {
                return null;
            }
        })();
        d.rendered = rendered(e);

        return d;
    }

    /* C — anything that behaves like a control without being one.
       A pointer cursor alone is not evidence (a decorative element may carry one), so an element
       counts only when it has a real click affordance: an inline handler. (No backticks anywhere
       in this instrument: it is a template literal, and one inside it terminates the string --
       which is how this file first refused to parse, for the second time in two harnesses.) */
    function clickOnlyControls() {
        var out = [];
        var focusable = 'a[href],area[href],button,input,select,textarea,summary,iframe,object,'
            + 'embed,audio[controls],video[controls],[contenteditable]';

        Array.prototype.slice.call(document.querySelectorAll('[onclick]')).forEach(function (e) {
            if (e.matches(focusable) || e.hasAttribute('tabindex')) {
                return;
            }
            if (!rendered(e)) {
                return;
            }

            var d = describe(e);
            d.why = 'inline onclick, not focusable';
            d.hasKeyHandler = !!(e.getAttribute('onkeydown') || e.getAttribute('onkeypress')
                || e.getAttribute('onkeyup'));
            out.push(d);
        });

        return out;
    }

    return {
        stampTabbables: stampTabbables,
        activeDescription: activeDescription,
        injectedRuleCount: injectedRuleCount,
        clickOnlyControls: clickOnlyControls,
        dir: function () {
            return document.documentElement.getAttribute('dir') || 'ltr';
        },
        tailwindPresent: function () {
            return typeof window.tailwind !== 'undefined';
        },
        countTabbables: function () {
            return document.querySelectorAll('[' + STAMP + ']').length;
        },
        describeStamp: function (n) {
            var e = document.querySelector('[' + STAMP + '="' + n + '"]');

            return e ? describe(e) : null;
        },
    };
})();
`;

/**
 * Does the focused snapshot differ from the unfocused one in a way a sighted keyboard user sees?
 *
 * FOUR PROPERTIES, because the three themes use three different mechanisms and a check that only
 * looked at `outline` would report the box-shadow ones as having no indicator at all.
 */
function indicatorDelta(before, after) {
    const changed = [];

    if (before.outline !== after.outline) {
        changed.push('outline');
    }
    if (before.boxShadow !== after.boxShadow) {
        changed.push('box-shadow');
    }
    if (before.borderColor !== after.borderColor || before.borderWidth !== after.borderWidth) {
        changed.push('border');
    }
    if (before.background !== after.background) {
        changed.push('background');
    }
    if (before.textDecoration !== after.textDecoration) {
        changed.push('text-decoration');
    }

    return changed;
}

/**
 * The indicator's own colour, whether it can be seen at all, AND WHICH SURFACE IT IS DRAWN ON.
 *
 * THE SURFACE IS THE PART THE FIRST RUN GOT WRONG, and it is the same class of error the
 * merchant-panel harness made with a modal: it read a real colour against the wrong thing. An
 * outline is painted OUTSIDE the border box, so with `outline-offset: 2px`
 * (`public/css/storefront-keyboard.css:117`, the general rule) the ring sits on the PARENT's
 * background, separated from the element by 2px of that same parent background. Only a NEGATIVE
 * offset (`:91`) draws it inside, on the element's own fill.
 *
 * Measuring 66 brand-coloured buttons against their own fill reported 1.02:1 to 1.68:1 — a real
 * ratio between two real colours that are never adjacent on screen.
 */
function indicatorColour(after) {
    const parts = String(after.outline).split('|');
    const style = parts[0];
    const width = parseFloat(parts[1] || '0');
    const colour = rgba(parts[2] || '');
    const offset = parseFloat(parts[3] || '0');

    if (style !== 'none' && width > 0 && colour) {
        /* `outline: 2px solid transparent` — Tailwind's own `outline-none` — is present,
           inherited and INVISIBLE. Alpha is the whole question. */
        return {
            kind: 'outline',
            colour,
            invisible: colour[3] < 0.1,
            /* >= 0 draws outside the element, so the parent's background is what it is on. */
            drawnOn: offset >= 0 ? 'parent' : 'self',
            offset,
        };
    }

    const shadow = rgba(String(after.boxShadow));

    if (shadow && String(after.boxShadow) !== 'none') {
        /* A box-shadow ring is painted outside the border box too, unless it is `inset`. */
        return {
            kind: 'box-shadow',
            colour: shadow,
            invisible: shadow[3] < 0.1,
            drawnOn: /\binset\b/.test(String(after.boxShadow)) ? 'self' : 'parent',
            offset: null,
        };
    }

    return null;
}

/**
 * Is the tab sequence in reading order?
 *
 * ROWS FIRST, THEN THE INLINE AXIS, AND THE INLINE AXIS DEPENDS ON DIRECTION — which is the whole
 * reason this is measured per direction rather than once. In LTR reading order runs left to right,
 * so a later stop should not be to the LEFT of an earlier one on the same row. In RTL it runs right
 * to left, so the comparison inverts. A check that sorted by `left` in both would report every
 * correct Arabic page as broken and every broken one as correct.
 *
 * ROW BANDING IS NECESSARY, NOT COSMETIC. Controls in one visual row have slightly different `top`
 * values (a 40px button beside a 24px icon), so an exact comparison would call every row an
 * inversion. The band is the taller of the two elements, which is the smallest honest tolerance.
 */
function orderInversions(sequence, direction) {
    const inversions = [];

    for (let i = 1; i < sequence.length; i++) {
        const prev = sequence[i - 1];
        const cur = sequence[i];

        /* An element that is off-screen has no meaningful place in reading order, and counting it
           here would report the same defect twice under two names. It is reported on its own. */
        if (prev.offscreen || cur.offscreen) {
            continue;
        }

        /* SAME ROW MEANS THE RECTS GENUINELY OVERLAP VERTICALLY -- not "their tops are within the
           taller one's height", which the first version used and which MERGED ADJACENT ROWS. It
           reported the stylique header's cart toggle (y=27, h=20, so 27-47) and the category bar's
           first button (y=75, h=48, so 75-123) as one row with a 48px band, and called the
           transition between two genuinely different rows a reading-order inversion -- in both
           directions, on every page. The two rects do not overlap by a single pixel. */
        const overlap = Math.min(prev.top + prev.height, cur.top + cur.height)
            - Math.max(prev.top, cur.top);
        const sameRow = overlap > 0.5 * Math.min(prev.height, cur.height);

        if (!sameRow) {
            if (cur.top + cur.height <= prev.top) {
                inversions.push({
                    kind: 'row',
                    from: prev,
                    to: cur,
                    detail: `tab moved UP the page, from y=${prev.top} to y=${cur.top}`,
                });
            }

            continue;   // a genuine move to a later row
        }

        /* Same row: the inline axis must follow the reading direction. */
        if (direction === 'rtl') {
            if (cur.right > prev.right + 2) {
                inversions.push({
                    kind: 'inline-rtl',
                    from: prev,
                    to: cur,
                    detail: `on one row, tab moved RIGHTWARD (x-right ${prev.right} -> ${cur.right}) `
                        + 'on a right-to-left page, so focus travels against the reading direction',
                });
            }
        } else if (cur.left < prev.left - 2) {
            inversions.push({
                kind: 'inline-ltr',
                from: prev,
                to: cur,
                detail: `on one row, tab moved LEFTWARD (x-left ${prev.left} -> ${cur.left}) on a `
                    + 'left-to-right page, so focus travels against the reading direction',
            });
        }
    }

    return inversions;
}

function short(d) {
    const name = d.name ? ` "${d.name}"` : '';
    const id = d.id ? `#${d.id}` : '';

    return `${d.tag}${id}${name}`.slice(0, 64);
}

async function main() {
    const { chromium } = playwright();
    const captureDir = fs.mkdtempSync(path.join(os.tmpdir(), 'sfkb-'));
    const selfTest = process.env.SS_SF_SELFTEST === '1';

    console.log('Capturing the storefront surfaces through the Feature test...');
    execFileSync(
        'php',
        ['artisan', 'test', 'tests/Feature/Storefront/StorefrontKeyboardMeasurementTest.php',
            '--filter', 'test_every_measured_surface_is_the_surface_the_theme_and_the_direction_it_claims'],
        {
            cwd: REPO,
            stdio: 'inherit',
            env: Object.assign({}, process.env, { SS_STOREFRONT_HTML_DIR: captureDir }),
        }
    );

    const captures = fs.readdirSync(captureDir).filter((f) => f.endsWith('.html')).sort();

    if (captures.length === 0) {
        throw new Error('PRECONDITION: the Feature test wrote no captures, so this run proves nothing.');
    }

    console.log(`\n${captures.length} captures. Self-test: ${selfTest ? 'ON' : 'off'}\n`);

    const server = await serve(captureDir);
    const browser = await chromium.launch();

    let checks = 0;
    let failures = 0;
    let externalAborted = 0;
    const findings = {
        noIndicator: [], weakIndicator: [], offscreenFocus: [], nameless: [],
        clickOnly: [], order: [], unreached: [],
    };
    const perPage = [];

    try {
        for (const capture of captures) {
            const m = capture.match(/^storefront-(.+)-(stylique|greentic|techzonix)-(en|ar)\.html$/);

            if (!m) {
                throw new Error(`unparseable capture name: ${capture}`);
            }

            const [, surface, theme, lang] = m;
            const label = `${surface} ${theme}/${lang}`;
            const context = await browser.newContext({
                viewport: { width: 1440, height: 900 },
                deviceScaleFactor: 1,
            });
            const page = await context.newPage();

            /* NO EGRESS. The only off-origin reference in any layout is the Google Fonts
               stylesheet; a substituted font changes glyph metrics, not colour, order or
               operability. The count is reported so a new external host cannot appear silently. */
            await page.route('**/*', (route) => {
                const url = route.request().url();

                if (url.startsWith(`http://127.0.0.1:${PORT}`) || url.startsWith('data:')
                    || url.startsWith('blob:') || url.startsWith('about:')) {
                    return route.continue();
                }

                externalAborted++;

                return route.abort();
            });

            await page.goto(`http://127.0.0.1:${PORT}/__capture/${capture}`, { waitUntil: 'load' });
            await page.waitForTimeout(400);     // the runtime JIT writes its sheet after load
            await page.addScriptTag({ content: INSTRUMENT });

            /* ---------------------------------------------------- premises, before any reading */
            const dir = await page.evaluate('window.__sfkb.dir()');
            const tailwind = await page.evaluate('window.__sfkb.tailwindPresent()');
            const injected = await page.evaluate('window.__sfkb.injectedRuleCount()');

            checks += 3;

            if (dir !== (lang === 'ar' ? 'rtl' : 'ltr')) {
                failures++;
                console.log(`  FAIL ${label}: document dir is "${dir}", so this is not the ${lang} reading`);
            }

            if (!tailwind) {
                failures++;
                console.log(`  FAIL ${label}: window.tailwind is undefined — the runtime JIT did not `
                    + 'run, so every colour below is against the theme sheet alone');
            }

            if (injected < 50) {
                failures++;
                console.log(`  FAIL ${label}: only ${injected} rules were injected at runtime. `
                    + 'CLAUDE.md §6 measures 270-294 on a rendered storefront page; a reading taken '
                    + 'without them is against a cascade no shopper sees.');
            }

            /* THE POSITIVE CONTROL. Two injections, one per category, so a silent-zero run cannot
               read as clean: a transparent outline everywhere (Tailwind's own `outline-none`, which
               is a 2.4.7 failure that LOOKS like a declared ring), and a div that responds to a
               click and to nothing else. */
            if (selfTest) {
                /* THE CONTROL HAS TO BEAT THE FOCUS LAYER, AND THE FIRST VERSION DID NOT.
                   It used `*:focus-visible` with `!important` — specificity (0,1,0) — against
                   `public/css/storefront-keyboard.css`'s `a:focus-visible` and its siblings, which
                   are (0,1,1) and ALSO `!important`. Two important declarations are settled by
                   specificity, so the control lost: the run reported click-only 0 -> 30 while
                   no-indicator stayed at 0 and weak-indicator did not move. It exercised one
                   detector of three and read as validating all three, which is worse than having
                   no control at all.
                   That is precisely the inert-repair failure this project has measured three times
                   in product CSS, reproduced here in the instrument. `html body` lifts each
                   selector to (0,1,3) and the control bites. */
                const kill = ['a', 'button', 'input', 'select', 'textarea', 'summary',
                    '[tabindex]', '[role="button"]', '[role="link"]']
                    .map((sel) => `html body ${sel}:focus-visible, html body ${sel}:focus`)
                    .join(', ');

                await page.addStyleTag({
                    content: `${kill} { outline: 2px solid transparent !important;`
                        + ' box-shadow: none !important; }',
                });
                await page.evaluate(`(function () {
                    var d = document.createElement('div');
                    d.setAttribute('onclick', 'void 0');
                    d.textContent = 'self-test pseudo control';
                    d.style.cssText = 'width:120px;height:40px;cursor:pointer';
                    (document.querySelector('main') || document.body).prepend(d);
                })()`);
            }

            /* ------------------------------------------------- A/B: enumerate, then Tab through */
            const before = await page.evaluate('window.__sfkb.stampTabbables()');
            const byStamp = new Map(before.map((d) => [d.stamp, d]));

            checks++;

            if (before.length < 5) {
                failures++;
                console.log(`  FAIL ${label}: only ${before.length} tabbable elements were found, `
                    + 'so the enumeration is broken and every reading below would be vacuous');
                await context.close();
                continue;
            }

            await page.evaluate('document.body.focus && document.body.focus()');
            await page.evaluate('window.scrollTo(0, 0)');

            const sequence = [];
            const seen = new Set();

            for (let i = 0; i < MAX_TAB_PRESSES; i++) {
                await page.keyboard.press('Tab');

                const active = await page.evaluate('window.__sfkb.activeDescription()');

                if (!active) {
                    break;              // focus left the document
                }
                if (active.stamp !== null && seen.has(active.stamp)) {
                    break;              // wrapped
                }
                if (active.stamp !== null) {
                    seen.add(active.stamp);
                }

                sequence.push(active);
            }

            checks++;

            if (sequence.length < 5) {
                failures++;
                console.log(`  FAIL ${label}: Tab produced only ${sequence.length} stops from `
                    + `${before.length} tabbable elements, so the walk is broken`);
                await context.close();
                continue;
            }

            /* B — focus visibility, per stop, against its own composited backdrop. */
            let pageNoIndicator = 0;
            let pageWeak = 0;
            const ratios = [];

            let pageOffscreen = 0;
            let pageNameless = 0;

            for (const after of sequence) {
                const unfocused = after.stamp !== null ? byStamp.get(after.stamp) : null;

                if (!unfocused) {
                    continue;           // a stop on something the enumeration never stamped
                }

                checks++;

                /* FOCUS THAT LEAVES THE VISIBLE PAGE. No ring, however well it contrasts, helps a
                   user whose focus is 300px past the right edge of the window. */
                if (after.offscreen) {
                    pageOffscreen++;
                    findings.offscreenFocus.push({
                        label,
                        el: short(after),
                        detail: `rect x=${after.left}..${after.right} y=${after.top} is outside the `
                            + `${after.viewportWidth}px viewport, so focus moves somewhere the `
                            + 'user cannot see',
                    });
                }

                /* A control with no accessible name is unusable to a screen-reader user even when
                   the keyboard reaches it perfectly (WCAG 4.1.2 / 2.4.4). */
                if (after.name === '') {
                    pageNameless++;
                    findings.nameless.push({
                        label,
                        el: short(after),
                        detail: `href=${after.href || '-'} cls="${after.cls}"`,
                    });
                }

                const delta = indicatorDelta(unfocused, after);
                const colour = indicatorColour(after);

                if (delta.length === 0) {
                    pageNoIndicator++;
                    findings.noIndicator.push({ label, el: short(after) });
                    continue;
                }

                if (!colour || colour.invisible) {
                    pageNoIndicator++;
                    findings.noIndicator.push({
                        label,
                        el: short(after),
                        note: colour
                            ? `indicator present but ${colour.kind} alpha ${colour.colour[3]}`
                            : `changed ${delta.join('+')} with no measurable indicator colour`,
                    });
                    continue;
                }

                const surface = colour.drawnOn === 'parent' ? after.parentBackdrop : after.backdrop;
                const ratio = contrast(colour.colour.slice(0, 3), rgba(surface).slice(0, 3));
                const otherSurface = colour.drawnOn === 'parent' ? after.backdrop : after.parentBackdrop;
                const otherRatio = contrast(colour.colour.slice(0, 3), rgba(otherSurface).slice(0, 3));

                ratios.push(ratio);

                if (ratio < MIN_INDICATOR_CONTRAST) {
                    pageWeak++;
                    findings.weakIndicator.push({
                        label,
                        el: short(after),
                        ratio,
                        /* Both surfaces are reported, with the decisive one named, so a reader can
                           see which comparison the verdict rests on rather than taking it on
                           trust — the first run's figures were the OTHER one. */
                        detail: `${colour.kind} offset ${colour.offset} drawn on the ${colour.drawnOn}: `
                            + `ring ${colour.colour.slice(0, 3).join(',')} on ${surface} = ${ratio.toFixed(2)}:1`
                            + ` (against the ${colour.drawnOn === 'parent' ? 'element' : 'parent'}`
                            + ` it would read ${otherRatio.toFixed(2)}:1)`,
                    });
                }
            }

            /* A — tab order against reading order. */
            const inversions = orderInversions(sequence, dir);

            checks++;

            for (const inv of inversions.slice(0, 6)) {
                findings.order.push({
                    label,
                    detail: inv.detail,
                    from: short(inv.from),
                    to: short(inv.to),
                    kind: inv.kind,
                });
            }

            /* Anything enumerated as tabbable that Tab never reached. */
            const unreached = before.filter((d) => !seen.has(d.stamp));

            checks++;

            /* C — click-only controls. */
            const clickOnly = await page.evaluate('window.__sfkb.clickOnlyControls()');

            checks++;

            for (const c of clickOnly) {
                findings.clickOnly.push({ label, el: short(c), why: c.why, keys: c.hasKeyHandler });
            }

            for (const u of unreached.slice(0, 6)) {
                findings.unreached.push({ label, el: short(u) });
            }

            perPage.push({
                label,
                dir,
                injected,
                tabbable: before.length,
                stops: sequence.length,
                noIndicator: pageNoIndicator,
                weak: pageWeak,
                offscreen: pageOffscreen,
                nameless: pageNameless,
                inversions: inversions.length,
                unreached: unreached.length,
                clickOnly: clickOnly.length,
                minRatio: ratios.length ? Math.min(...ratios) : null,
            });

            await context.close();
        }
    } finally {
        await browser.close();
        server.close();
        fs.rmSync(captureDir, { recursive: true, force: true });
    }

    /* ------------------------------------------------------------------------------- the report */
    console.log('\nPER PAGE');
    console.log('surface/theme/lang                 dir  inj  tabbable stops  noInd weak offsc namel inver unre click  minRing');

    for (const p of perPage) {
        console.log(
            p.label.padEnd(34)
            + p.dir.padEnd(5)
            + String(p.injected).padEnd(5)
            + String(p.tabbable).padEnd(9)
            + String(p.stops).padEnd(7)
            + String(p.noIndicator).padEnd(6)
            + String(p.weak).padEnd(5)
            + String(p.offscreen).padEnd(6)
            + String(p.nameless).padEnd(6)
            + String(p.inversions).padEnd(6)
            + String(p.unreached).padEnd(5)
            + String(p.clickOnly).padEnd(7)
            + (p.minRatio === null ? 'n/a' : p.minRatio.toFixed(2) + ':1')
        );
    }

    const totals = {
        noIndicator: findings.noIndicator.length,
        weak: findings.weakIndicator.length,
        offscreenFocus: findings.offscreenFocus.length,
        nameless: findings.nameless.length,
        order: findings.order.length,
        clickOnly: findings.clickOnly.length,
        unreached: findings.unreached.length,
    };

    for (const [name, list] of Object.entries(findings)) {
        if (list.length === 0) {
            continue;
        }

        console.log(`\n${name.toUpperCase()} — ${list.length} finding(s), first 12:`);

        for (const f of list.slice(0, 12)) {
            const extra = f.ratio !== undefined ? ` ${f.ratio.toFixed(2)}:1` : '';
            const note = f.note || f.detail || f.why || '';
            /* ORDER findings name BOTH ends. The first run printed an empty subject for all 72 of
               them, which made them uncountable as defects: "tab moved UP the page" is not
               actionable without knowing from what to what. */
            const ends = f.from ? ` [${f.from} -> ${f.to}]` : '';
            console.log(`  ${f.label}: ${f.el || ''}${extra}${note ? ' — ' + note : ''}${ends}`);
        }
    }

    console.log(`\n${checks} checks on ${perPage.length} pages. `
        + `${failures} premise/structural failure(s). ${externalAborted} off-origin request(s) aborted.`);
    console.log('FINDINGS  no-indicator=' + totals.noIndicator
        + '  weak-indicator=' + totals.weak
        + '  offscreen-focus=' + totals.offscreenFocus
        + '  nameless=' + totals.nameless
        + '  order-inversions=' + totals.order
        + '  click-only=' + totals.clickOnly
        + '  unreached=' + totals.unreached);

    const total = failures + totals.noIndicator + totals.weak + totals.offscreenFocus
        + totals.nameless + totals.clickOnly + totals.unreached + totals.order;

    if (selfTest) {
        console.log('\nSELF-TEST: the run must NOT be clean. '
            + (total > 0 ? 'OK — it reported findings.' : 'BROKEN — it reported nothing.'));
        process.exit(total > 0 ? 0 : 1);
    }

    process.exit(total === 0 ? 0 : 1);
}

if (require.main === module) {
    main().catch((e) => {
        console.error(String(e && e.stack ? e.stack : e));
        process.exit(2);
    });
}
