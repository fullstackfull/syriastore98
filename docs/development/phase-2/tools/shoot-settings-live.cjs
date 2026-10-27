/*
 * The SETTINGS screen, measured in a real browser.
 * =================================================================================================
 *
 * P2-05-7 and P2-05-8 carry four acceptance conditions that only a browser can answer, and the
 * static tests in `tests/Unit/Ui/` answer none of them:
 *
 *   1. no stored credential reaches the DOM (a source scan proves the Blade, not the page: a
 *      value injected by JavaScript after load does not appear in either);
 *   2. every form control has an accessible name, with no dangling `for`;
 *   3. the panels carry no pre-design-system classes;
 *   4. text meets WCAG AA (4.5:1), in LIGHT and DARK, in LTR and RTL.
 *
 * Each tab is fetched by AJAX into `#pills-tabContent`, so none of this is in the page source: the
 * tab must be CLICKED and the response waited for. That is why this harness exists rather than a
 * `view()->render()` assertion.
 *
 * WHAT THE FOUR CONFIGURATIONS REALLY ARE, because getting this wrong produced a false reading
 * earlier in this phase. Theme and direction are SERVER-side (`layouts/app.blade.php` picks
 * between four stylesheets from the store's `cust_darklayout` and `SITE_RTL`), so setting
 * `data-theme` or `dir` from the browser proves nothing. Theme is flipped through
 * `set-preview-state.php`. Direction is flipped by changing the LOGGED-IN USER's language,
 * because `layouts/app.blade.php:48-50` overrides `SITE_RTL` to 'on' whenever
 * `auth()->user()->language` is `ar` or `he` -- direction follows the USER, not the store. Both
 * are restored on the way out, and each screenshot is HASHED so four identical files cannot pass
 * as four configurations.
 *
 * CONTRAST IS MEASURED, NOT ASSERTED. Every text node's computed colour is resolved against the
 * first ancestor with a non-transparent background and the WCAG 2.x ratio computed. A node under
 * 4.5:1 is reported with its text, so a failure names the element instead of a count. The earlier
 * finding that drove this: the mail-driver fragment forced `background-color: #e9ecef` on readonly
 * inputs from its own `<script>`, which against the dark theme's muted foreground is 1.84:1.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 THAT ACTUALLY SERVES THE ASSETS. This cost a whole run: started as
 *
 *         php -S 127.0.0.1:8899 -t public vendor/.../resources/server.php
 *
 *     every page answered 200 with jQuery's URL returning an EMPTY body, so no script ran, the
 *     tab AJAX never fired and all 8 tabs reported zero controls in all 4 configurations.
 *     Laravel's dev router computes `$publicPath = getcwd()`, which is the directory `php -S`
 *     was LAUNCHED from, not its `-t` root — so `file_exists()` missed every static file and
 *     the router fell through to a non-existent `index.php`. Launch from INSIDE `public/`:
 *
 *         cd public && DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite \
 *           CACHE_STORE=file SESSION_DRIVER=file PHP_CLI_SERVER_WORKERS=10 \
 *           php -S 127.0.0.1:8899 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
 *
 *     `SESSION_DRIVER=file` because `p2dev.sqlite` has no `sessions` table, and
 *     `PHP_CLI_SERVER_WORKERS` because one worker cannot serve ~30 assets per page.
 *     `php artisan serve` is NOT a substitute here: it does not pass `DB_DATABASE` through to
 *     its worker, so it serves the wrong database.
 *
 *     The `minControls` floors below are what turned that silent nothing into 102 named
 *     failures instead of a clean pass over empty pages. They are the most valuable line in
 *     this file.
 *   - that database seeded by `database/seed-p2dev.php`, which must include the two GATE F tables
 *     the settings screen reads -- `currency_rates` and `otp_integrations`. Neither has a
 *     migration, and without them those tabs answer "something went wrong" and this harness would
 *     report zero controls rather than a failure.
 *
 * usage: node docs/development/phase-2/tools/shoot-settings-live.cjs
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const crypto = require('crypto');

const BASE = 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const OUT = REPO + '/docs/development/phase-2/evidence/p2-05-7';
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite', CACHE_STORE: 'file',
});

/* The tabs this harness drives, by their `data-tab`. `minControls` is a PREMISE, not a goal: a tab
   whose AJAX failed renders an error string, and without a floor on the control count that reads
   as a clean pass. The numbers are the counts measured once the tab really rendered.

   `as` IS NOT A DETAIL. The rail shows a DIFFERENT SET OF TABS to each principal --
   `index.blade.php` gates one block on `type == 'super admin'` and another on `type == 'admin'`.
   Storage, Cookie, Cache, reCAPTCHA, Chat GPT, Style and SEO are OPERATOR-ONLY; a merchant never
   sees them. Driving them as the merchant does not fail with "forbidden", it fails with
   "selector not found", which reads like a broken harness rather than the wrong login. So each
   row names the principal it needs and the harness logs in as that one. */
const MERCHANT = { email: 'merchant@example.test', who: 'merchant' };

/* The OPERATOR tabs cannot be driven live in a preview container, and the reason is not a
   permission check. `app/Http/Middleware/XSS.php` redirects EVERY `super admin` to the Laravel
   Updater whenever a migration file is missing from the `migrations` table; the preview database
   has all 70 WorkDo modules active and 96 of their migrations unrecorded, so the operator cannot
   open any admin page. Running 96 vendored module migrations on SQLite for a screenshot is not a
   trade worth making and `packages/workdo/` is not ours to edit, so those panels are rendered
   through real Blade by `render-settings-panel.php` and audited from `file://` instead.

   THAT IS WEAKER EVIDENCE AND IS LABELLED AS SUCH in the output: it proves the panel's markup and
   the real stylesheets in a real browser, not the route, the permission gate or the AJAX. */

const TABS = [
    { tab: 'email_setting',     minControls: 1,  label: 'Email',    as: MERCHANT, thenControls: 8 },
    { tab: 'brand_setting',     minControls: 10, label: 'Brand',    as: MERCHANT },
    { tab: 'system_setting',    minControls: 6,  label: 'System',   as: MERCHANT },
    { tab: 'twilio_setting',    minControls: 5,  label: 'Twilio',   as: MERCHANT },
    { tab: 'stock_setting',     minControls: 2,  label: 'Stock',    as: MERCHANT },
    { tab: 'otp_setting',       minControls: 8,  label: 'OTP',      as: MERCHANT },
    { tab: 'currency_setting',  minControls: 30, label: 'Currency', as: MERCHANT },
    /* The payment tab is where F117's largest exposure lived: 52 gateways, 108 fields, 58 of
       them secrets, all rendered at once. It is in the rail's UNGATED block, so a merchant sees
       it -- which is why it had to be reachable here rather than only in the rendered set. */
    { tab: 'payment_setting',   minControls: 250, label: 'Payment', as: MERCHANT },
];

/* Operator-only panels, rendered rather than clicked. `panel` is a key of
   `render-settings-panel.php`'s own table. */
const RENDERED_PANELS = [
    { panel: 'storage',   minControls: 17, label: 'Storage' },
    { panel: 'chatgpt',   minControls: 2,  label: 'ChatGPT' },
    { panel: 'style',     minControls: 1,  label: 'Style' },
    { panel: 'cookie',    minControls: 1,  label: 'Cookie' },
    { panel: 'cache',     minControls: 0,  label: 'Cache' },
    /* The cache panel has no form CONTROL at all -- it is a button and a figure. `minControls`
       is a premise about rendering, so for this one the premise is asserted on the panel's
       `ss-*` element count instead, below. */
    { panel: 'recaptcha', minControls: 2,  label: 'reCAPTCHA' },
];

/* Classes from before the design system. A panel still carrying one has not been migrated. */
const LEGACY = ['form-control', 'form-group', 'form-label', 'col-form-label', 'btn-badge',
                'card-body', 'card-header', 'card-footer', 'btn-outline-primary', 'btn-check'];

function setState(theme) {
    execFileSync('php', [REPO + '/docs/development/phase-2/tools/set-preview-state.php', '1', theme, 'ltr'],
        { cwd: REPO, env: ENV, stdio: 'pipe' });
}
function setUserLanguage(email, lang) {
    /* Direction follows the USER's language, so this is the only switch that moves it -- and it
       has to be set on the user who will be LOGGED IN, not on a fixed one. `User` is `Cachable`,
       so the cache is flushed or the layout reads the pre-write row. */
    execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "' + email + '")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();'
        + ' echo "language=", $u->language, "\\n";'],
        { cwd: REPO, env: ENV, stdio: 'pipe' });
}

async function login(p, email) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', email);
    await p.fill('input[name="password"]', 'password');
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
    if (p.url().includes('/login')) throw new Error('login failed for ' + email);
}

/* Everything measured inside one tab panel, in one pass, so the page is read once. */
function auditPanel(legacyClasses) {
    const panel = document.getElementById('pills-tabContent');
    if (!panel) return { error: 'no #pills-tabContent' };

    const controls = Array.from(panel.querySelectorAll('input, select, textarea'))
        .filter((el) => el.type !== 'hidden' && el.name !== '_token' && el.type !== 'submit');

    const ids = new Set(Array.from(panel.querySelectorAll('[id]')).map((e) => e.id));
    const dangling = Array.from(panel.querySelectorAll('label[for]'))
        .map((l) => l.getAttribute('for'))
        .filter((f) => f && !ids.has(f));

    /* The accessible name, resolved the way a screen reader does: aria-label, then
       aria-labelledby, then a <label for>, then a wrapping <label>. */
    const unnamed = [];
    for (const el of controls) {
        const byLabel = el.id ? panel.querySelector('label[for="' + CSS.escape(el.id) + '"]') : null;
        const name = (el.getAttribute('aria-label') || '').trim()
            || (el.getAttribute('aria-labelledby') || '').split(/\s+/)
                 .map((i) => (document.getElementById(i) || {}).textContent || '').join(' ').trim()
            || (byLabel ? byLabel.textContent.trim() : '')
            || (el.closest('label') ? el.closest('label').textContent.trim() : '');
        if (!name) unnamed.push(el.name || el.id || el.tagName.toLowerCase());
    }

    /* A credential field must not carry a value. Read from the LIVE DOM, so a value written by
       script after load is caught too. */
    const credRe = /(secret|api_key|apikey|password|token|private_key|access_key|client_secret|_sid|salt|signature|merchant_key|credential)/i;
    const credsWithValue = controls
        .filter((el) => credRe.test(el.name || '') && String(el.value || '').length > 0)
        .map((el) => el.name);

    const legacy = [];
    for (const el of panel.querySelectorAll('[class]')) {
        for (const c of legacyClasses) {
            if (el.classList.contains(c)) { legacy.push(c); break; }
        }
    }

    return {
        controls: controls.length,
        ss: panel.querySelectorAll('[class*="ss-"]').length,
        unnamed,
        dangling,
        credsWithValue,
        legacy: Array.from(new Set(legacy)),
    };
}

/* WCAG 2.x contrast of every text node in the panel against its effective background. */
function measureContrast() {
    const lum = (rgb) => {
        const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
        return 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
    };
    const parse = (s) => {
        const m = /rgba?\(([^)]+)\)/.exec(s || '');
        if (!m) return null;
        const p = m[1].split(',').map((x) => parseFloat(x));
        return { rgb: [p[0], p[1], p[2]], a: p.length > 3 ? p[3] : 1 };
    };
    const bgOf = (el) => {
        for (let n = el; n && n !== document.documentElement.parentNode; n = n.parentElement) {
            const c = parse(getComputedStyle(n).backgroundColor);
            if (c && c.a > 0.5) return c.rgb;
        }
        return [255, 255, 255];
    };
    const panel = document.getElementById('pills-tabContent');
    if (!panel) return { error: 'no panel' };
    const out = [];
    const walker = document.createTreeWalker(panel, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        const text = n.textContent.trim();
        if (text.length < 2) continue;
        const el = n.parentElement;
        if (!el || !el.getClientRects().length) continue;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        /* VISUALLY HIDDEN TEXT IS NOT RENDERED TO A SIGHTED READER, so its contrast is not a
           WCAG question at all. `.ss-visually-hidden` is the 1px-clip technique: the text is in
           the accessibility tree on purpose and nobody ever sees it. Measuring it reported ten
           "failures" per theme on the brand panel's colour swatches -- every one of them a
           screen-reader name doing exactly its job. Detected by the clip rectangle rather than
           by the class name, so a different implementation of the same idea is also skipped. */
        if (el.closest('.ss-visually-hidden')
            || (cs.clip !== 'auto' && cs.clip !== '' && parseFloat(cs.width) <= 1)) continue;
        const fg = parse(cs.color);
        if (!fg) continue;
        /* Placeholder text and a readonly input's own value are read through .value, not a text
           node, so they are measured separately below. */
        const bg = bgOf(el);
        const l1 = lum(fg.rgb), l2 = lum(bg);
        const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        const px = parseFloat(cs.fontSize) || 16;
        const large = px >= 24 || (px >= 18.66 && parseInt(cs.fontWeight, 10) >= 700);
        const need = large ? 3 : 4.5;
        if (ratio < need) {
            out.push({ text: text.slice(0, 48), ratio: Math.round(ratio * 100) / 100, need,
                       cls: el.className && el.className.toString().slice(0, 60) });
        }
    }
    /* Inputs: their VALUE is not a text node. A readonly input is where the forced-background
       defect lived, so measure each input's own colour against its own background. */
    for (const el of panel.querySelectorAll('input, select, textarea')) {
        if (!el.getClientRects().length) continue;
        /* A CHECKBOX HAS NO TEXT, so its `color` is not a contrast question either. Including
           them reported every switch, swatch and radio on every panel at 1.6:1 -- the colour
           the browser happens to compute for a control that renders no glyph. Their visible
           affordance is a border and a fill, which is a non-text contrast question (1.4.11,
           3:1) this harness does not claim to measure. A file input is skipped for the same
           reason: what is drawn is the browser's own button. */
        if (['checkbox', 'radio', 'file', 'range', 'color', 'hidden'].includes(el.type)) continue;
        const cs = getComputedStyle(el);
        const fg = parse(cs.color); if (!fg) continue;
        const bg = bgOf(el);
        const l1 = lum(fg.rgb), l2 = lum(bg);
        const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        if (ratio < 4.5) {
            out.push({ text: '[input ' + (el.name || el.id) + ' value]',
                       ratio: Math.round(ratio * 100) / 100, need: 4.5,
                       cls: (el.readOnly ? 'readonly ' : '') + String(el.className).slice(0, 50) });
        }
    }
    return { failures: out };
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch();
    const results = [];
    const shots = {};
    let failed = 0, checks = 0;
    const check = (ok, what) => { checks++; if (!ok) { failed++; results.push('FAIL ' + what); } };

    try {
        for (const theme of ['light', 'dark']) {
            for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
              for (const principal of [MERCHANT]) {
                const mine = TABS.filter((t) => t.as === principal);
                if (!mine.length) continue;
                setState(theme);
                setUserLanguage(principal.email, lang);
                const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
                const page = await ctx.newPage();
                await login(page, principal.email);
                await page.goto(BASE + '/setting', { waitUntil: 'domcontentloaded' });

                /* PREMISE: the stylesheet the layout picked must match the configuration asked
                   for, or everything below is measured against the wrong theme. */
                const sheets = await page.evaluate(() => Array.from(document.styleSheets)
                    .map((s) => (s.href || '').split('/').pop()).filter(Boolean));
                const wantDark = theme === 'dark', wantRtl = dir === 'rtl';
                const hasDark = sheets.some((h) => /dark/.test(h));
                const hasRtl = sheets.some((h) => /^rtl-/.test(h));
                check(hasDark === wantDark, `${theme}/${dir}: dark stylesheet loaded=${hasDark}, wanted ${wantDark} (${sheets.join(',')})`);
                check(hasRtl === wantRtl, `${theme}/${dir}: rtl stylesheet loaded=${hasRtl}, wanted ${wantRtl}`);
                const htmlDir = await page.getAttribute('html', 'dir');
                check((htmlDir === 'rtl') === wantRtl, `${theme}/${dir}: <html dir="${htmlDir}">`);

                /* The tab rail's own markup: one panel container, a closed list, and an
                   aria-selected that agrees with the visual active state. */
                const rail = await page.evaluate(() => ({
                    panels: document.querySelectorAll('#pills-tabContent').length,
                    tablists: document.querySelectorAll('[role="tablist"]').length,
                    tabsInList: document.querySelectorAll('[role="tablist"] [role="tab"]').length,
                    tabsTotal: document.querySelectorAll('[role="tab"]').length,
                    selected: document.querySelectorAll('[role="tab"][aria-selected="true"]').length,
                    searchNamed: (() => {
                        const i = document.getElementById('tab-search');
                        if (!i) return 'absent';
                        const l = document.querySelector('label[for="tab-search"]');
                        return (i.getAttribute('aria-label') || (l && l.textContent.trim()) || '') || 'UNNAMED';
                    })(),
                }));
                check(rail.panels === 1, `${theme}/${dir}: #pills-tabContent appears ${rail.panels}x (duplicate id)`);
                /* Every tab must be INSIDE the tablist. The absolute count differs by principal
                   (the rail is gated), so the property asserted is the ratio, with a floor that
                   only a rendered rail can meet. */
                check(rail.tabsInList === rail.tabsTotal && rail.tabsTotal >= 10,
                      `${theme}/${dir} ${principal.who}: ${rail.tabsInList} of ${rail.tabsTotal} tabs are inside the tablist (unclosed <ul> drops them)`);
                check(rail.selected === 1, `${theme}/${dir}: ${rail.selected} tabs report aria-selected=true`);
                check(rail.searchNamed !== 'UNNAMED' && rail.searchNamed !== 'absent',
                      `${theme}/${dir}: the settings search box is ${rail.searchNamed}`);

                for (const t of mine) {
                    const sel = `[data-tab="${t.tab}"]`;

                    /* EMPTY THE PANEL FIRST. This cost a whole run of false readings: the wait
                       below is a count, and the AJAX replaces the panel's HTML wholesale, so
                       until it lands the PREVIOUS tab's content is still there and satisfies
                       the count. The payment tab (311 controls, 52 gateways) is slow enough to
                       lose that race, and every "Payment" measurement in the previous run was
                       actually of the Currency panel -- including a contrast failure on
                       currency table headers reported against payment. Clearing it means a
                       timeout reads an EMPTY panel and fails the premise, which is the honest
                       outcome. */
                    await page.evaluate(() => {
                        const el = document.getElementById('pills-tabContent');
                        if (el) el.innerHTML = '';
                    });

                    await page.click(sel);
                    await page.waitForFunction(
                        (n) => { const p = document.getElementById('pills-tabContent');
                                 return p && p.querySelectorAll('input,select,textarea').length >= n; },
                        t.minControls, { timeout: 45000 },
                    ).catch(() => {});

                    /* The email tab renders ONLY its driver select; the mail fields come from a
                       SECOND request fired by that select's `change`. Without this the fragment
                       this phase rewrote is never measured at all. */
                    if (t.tab === 'email_setting') {
                        await page.selectOption('#email_setting', 'smtp').catch(() => {});
                        await page.waitForFunction(
                            () => document.querySelectorAll('#pills-tabContent [name="mail_host"]').length > 0,
                            null, { timeout: 30000 },
                        ).catch(() => {});
                    }

                    const a = await page.evaluate(auditPanel, LEGACY);
                    const c = await page.evaluate(measureContrast);

                    check(!a.error, `${theme}/${dir} ${t.label}: ${a.error || ''}`);
                    /* `thenControls` is the floor AFTER a second fetch the tab triggers itself
                       (today only email's mail-driver fields). */
                    const floor = t.thenControls ?? t.minControls;
                    check((a.controls || 0) >= floor,
                          `${theme}/${dir} ${t.label}: ${a.controls} controls, expected >= ${floor} (the tab probably did not render)`);
                    check((a.unnamed || []).length === 0,
                          `${theme}/${dir} ${t.label}: controls with no accessible name: ${(a.unnamed || []).join(', ')}`);
                    check((a.dangling || []).length === 0,
                          `${theme}/${dir} ${t.label}: label[for] pointing at nothing: ${(a.dangling || []).join(', ')}`);
                    check((a.credsWithValue || []).length === 0,
                          `${theme}/${dir} ${t.label}: credential fields carrying a value: ${(a.credsWithValue || []).join(', ')}`);
                    check((a.legacy || []).length === 0,
                          `${theme}/${dir} ${t.label}: pre-design-system classes: ${(a.legacy || []).join(', ')}`);
                    check((c.failures || []).length === 0,
                          `${theme}/${dir} ${t.label}: below AA: ` + (c.failures || [])
                              .map((f) => `"${f.text}" ${f.ratio}:1 (needs ${f.need}) [${f.cls}]`).join(' | '));

                    /* The aria-selected state must follow the click, not stay on the first tab. */
                    const sync = await page.evaluate((s) => {
                        const el = document.querySelector(s);
                        const act = document.querySelectorAll('.nav-link.active').length;
                        return { selected: el.getAttribute('aria-selected'), active: el.classList.contains('active'),
                                 activeCount: act,
                                 labelled: (document.getElementById('pills-tabContent') || {}).getAttribute
                                     ? document.getElementById('pills-tabContent').getAttribute('aria-labelledby') : null };
                    }, sel);
                    check(sync.selected === 'true' && sync.active && sync.activeCount === 1,
                          `${theme}/${dir} ${t.label}: aria-selected=${sync.selected}, active=${sync.active}, ${sync.activeCount} tabs visually active`);
                    check(sync.labelled === await page.getAttribute(sel, 'id'),
                          `${theme}/${dir} ${t.label}: panel aria-labelledby=${sync.labelled}`);

                    const file = `${OUT}/settings-${t.tab}-${lang}-${theme}.png`;
                    await page.screenshot({ path: file, fullPage: true });
                    shots[file] = crypto.createHash('sha1').update(fs.readFileSync(file)).digest('hex').slice(0, 12);
                }
                await ctx.close();
              }
            }
        }
    } finally {
        setState('light');
        setUserLanguage(MERCHANT.email, 'en');
        await browser.close();
    }

    /* ---- the operator-only panels, rendered through real Blade and audited from file:// ---- */
    {
        const browser2 = await chromium.launch();
        const tmp = REPO + '/storage/app/p2-settings-preview';
        fs.mkdirSync(tmp, { recursive: true });
        try {
            for (const theme of ['light', 'dark']) {
                for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
                    for (const t of RENDERED_PANELS) {
                        const file = `${tmp}/${t.panel}-${lang}-${theme}.html`;
                        execFileSync('php', [REPO + '/docs/development/phase-2/tools/render-settings-panel.php',
                                             t.panel, lang, theme, file],
                                     { cwd: REPO, env: ENV, stdio: 'pipe' });
                        const ctx = await browser2.newContext({ viewport: { width: 1440, height: 1000 } });
                        const page = await ctx.newPage();
                        await page.goto('file://' + file, { waitUntil: 'load' });

                        /* PREMISE: the stylesheets must really have loaded, or everything measured
                           from here is of unstyled markup and every contrast check passes. */
                        const styled = await page.evaluate(() => {
                            const el = document.querySelector('.ss-card, .ss-input, .ss-label');
                            if (!el) return { ok: false, why: 'no design-system element' };
                            const cs = getComputedStyle(document.body);
                            return { ok: true, bg: cs.backgroundColor,
                                     sheets: document.styleSheets.length };
                        });
                        check(styled.ok && styled.sheets >= 3,
                              `${theme}/${dir} ${t.label} (rendered): stylesheets=${styled.sheets} ${styled.why || ''}`);

                        const a = await page.evaluate(auditPanel, LEGACY);
                        const c = await page.evaluate(measureContrast);
                        const tag = `${theme}/${dir} ${t.label} (rendered)`;
                        check((a.controls || 0) >= t.minControls,
                              `${tag}: ${a.controls} controls, expected >= ${t.minControls}`);
                        /* With minControls 0 the control count proves nothing, so the premise
                           that the panel rendered at all rests on this. */
                        check((a.ss || 0) >= 3,
                              `${tag}: only ${a.ss} design-system elements, so the panel did not render`);
                        check((a.unnamed || []).length === 0,
                              `${tag}: controls with no accessible name: ${(a.unnamed || []).join(', ')}`);
                        check((a.dangling || []).length === 0,
                              `${tag}: label[for] pointing at nothing: ${(a.dangling || []).join(', ')}`);
                        check((a.credsWithValue || []).length === 0,
                              `${tag}: credential fields carrying a value: ${(a.credsWithValue || []).join(', ')}`);
                        check((a.legacy || []).length === 0,
                              `${tag}: pre-design-system classes: ${(a.legacy || []).join(', ')}`);
                        check((c.failures || []).length === 0,
                              `${tag}: below AA: ` + (c.failures || [])
                                  .map((f) => `"${f.text}" ${f.ratio}:1 (needs ${f.need}) [${f.cls}]`).join(' | '));

                        /* And the fixture's own credentials must not appear ANYWHERE in the page,
                           not just in an input value -- a hidden field, a data attribute or a
                           script would all leak them. */
                        const html = await page.content();
                        const leaked = ['FIXTURE-S3-SECRET-DO-NOT-RENDER', 'FIXTURE-WASABI-SECRET-DO-NOT-RENDER',
                                        'FIXTURE-OPENAI-KEY-A-DO-NOT-RENDER', 'FIXTURE-OPENAI-KEY-B-DO-NOT-RENDER']
                            .filter((k) => html.includes(k));
                        check(leaked.length === 0, `${tag}: stored credential present in the page: ${leaked.join(', ')}`);

                        const shot = `${OUT}/settings-${t.panel}-rendered-${lang}-${theme}.png`;
                        await page.screenshot({ path: shot, fullPage: true });
                        shots[shot] = crypto.createHash('sha1').update(fs.readFileSync(shot)).digest('hex').slice(0, 12);
                        await ctx.close();
                    }
                }
            }
        } finally {
            await browser2.close();
            fs.rmSync(tmp, { recursive: true, force: true });
        }
    }

    /* Four configurations must really be four. A shot repeated across them means the state switch
       did not take, which is how a "dark mode verified" claim went out wrong earlier. */
    for (const t of TABS.map((x) => ({ key: x.tab, label: x.label }))
                  .concat(RENDERED_PANELS.map((x) => ({ key: x.panel + '-rendered', label: x.label })))) {
        const mine = Object.entries(shots).filter(([f]) => f.includes('-' + t.key + '-'));
        const uniq = new Set(mine.map(([, h]) => h));
        check(uniq.size === mine.length,
              `${t.label}: ${mine.length} screenshots but only ${uniq.size} distinct -- a configuration did not change`);
    }

    console.log(results.join('\n'));
    console.log(JSON.stringify({ checks, failed, screenshots: Object.keys(shots).length,
                                 distinct: new Set(Object.values(shots)).size }));
    process.exit(failed ? 1 : 0);
})();
