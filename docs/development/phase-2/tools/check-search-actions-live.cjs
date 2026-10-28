#!/usr/bin/env node
/**
 * P2-06-8 — quick actions from search results, driven in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `SearchResultActionsTest` proves the AUTHORISATION: which
 * actions a principal is sent, that an ungated principal is sent none, that nothing destructive
 * is offered, that the operator is not refused by `isAbleTo()`. None of it proves the actions
 * reach the screen, that the bar follows the arrow keys, that it is reachable and named for
 * assistive technology, or that it reads in Arabic and in dark mode.
 *
 * AND ONE STRUCTURAL INVARIANT IS CHECKED HERE BECAUSE ONLY THE DOM HOLDS IT: the results are a
 * `listbox` and each row an `option`, which in ARIA is a LEAF. An interactive descendant inside a
 * row would break the `aria-activedescendant` pattern P2-06-2 was built around -- a selection that
 * moves while the caret stays in the input. So the bar must live OUTSIDE the listbox, and that is
 * asserted by ancestry rather than by reading the markup.
 *
 * THE FIXTURE IS THE INTERESTING PART. `merchant@example.test` holds `Edit Products` and
 * `Manage Customer` but NOT `Show Customer`, so one search exercises the action path and another
 * exercises the no-action path without any permission juggling -- a product result carries an
 * action, a customer result carries none.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 serving THIS repository, launched from inside `public/`;
 *   - `database/p2dev.sqlite` seeded by `database/seed-p2dev.php`.
 *
 * usage: node docs/development/phase-2/tools/check-search-actions-live.cjs [baseUrl]
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite',
    CACHE_STORE: 'file', SESSION_DRIVER: 'file',
});

let pass = 0; const failures = [];
function check(name, ok, detail) {
    if (ok) { pass++; return; }
    failures.push(name);
    console.log('  FAIL  ' + name + (detail ? '  [' + detail + ']' : ''));
}

function setSetting(name, value) {
    const got = execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $store = (int) $u->current_store;'
        + ' $row = App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "' + name + '")->first();'
        + ' if ($row) { $row->value = "' + value + '"; $row->save(); }'
        + ' else { App\\Models\\Setting::create(["name" => "' + name + '", "value" => "' + value + '",'
        + '     "store_id" => $store, "created_by" => $u->creatorId()]); }'
        + ' (new App\\Models\\Setting)->flushCache();'
        + ' echo App\\Models\\Setting::withoutGlobalScopes()->where("store_id", $store)'
        + '     ->where("name", "' + name + '")->value("value");'],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();

    if (got !== value) throw new Error(`${name} is "${got}", asked for "${value}"`);
}

/* Both halves of direction, and the theme is a SETTING too -- `data-ss-theme` comes from
   `cust_darklayout`, not from the browser's colour scheme. Both lessons were paid for on P2-06-6
   and P2-06-7, where harnesses reported green while measuring the wrong configuration. */
function setDirection(lang) {
    execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
        + ' $u = App\\Models\\User::where("email", "merchant@example.test")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();'],
        { cwd: REPO, env: ENV, stdio: 'pipe' });
    setSetting('SITE_RTL', (lang === 'ar' || lang === 'he') ? 'on' : 'off');
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    if (page.url().includes('/login')) throw new Error('login failed');
}

async function search(page, term) {
    await page.keyboard.press('Control+k');
    await page.waitForTimeout(350);
    await page.fill('#ss-command-input', term);
    await page.waitForFunction(
        () => document.querySelectorAll('#ss-command-results .ss-command__item').length > 0,
        null, { timeout: 15000 }
    );
    await page.waitForTimeout(350);
}

const srgb = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
const lum = ([r, g, b]) => 0.2126 * srgb(r) + 0.7152 * srgb(g) + 0.0722 * srgb(b);
const parse = (s) => { const m = String(s).match(/-?\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; };
const ratio = (fg, bg) => {
    const a = lum(fg), b = lum(bg);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
};

(async () => {
    const browser = await chromium.launch();

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            setDirection(lang);

            for (const theme of ['light', 'dark']) {
                setSetting('cust_darklayout', theme === 'dark' ? 'on' : 'off');

                const ctx = await browser.newContext({ viewport: { width: 1600, height: 950 } });
                const page = await ctx.newPage();
                const errors = [];
                page.on('pageerror', (e) => errors.push(e.message));

                const tag = `${dir}/${theme}`;

                await login(page);
                await page.goto(BASE + '/product', { waitUntil: 'domcontentloaded' });
                await page.waitForTimeout(600);

                // PREMISES. Without these the rest is vacuous.
                const htmlDir = (await page.getAttribute('html', 'dir')) || 'ltr';
                check(`${tag} — the page is really ${dir}`, (htmlDir === 'rtl') === (dir === 'rtl'), htmlDir);
                const htmlTheme = (await page.getAttribute('html', 'data-ss-theme')) || 'light';
                check(`${tag} — the page is really ${theme}`, htmlTheme === theme, htmlTheme);

                /* ---- a product: this merchant holds `Edit Products`, so there IS an action.
                        "Co" rather than "Damask" because the follow-the-selection case needs TWO
                        hits to move between, and "Damask" matches exactly one -- measured against
                        the seed, and the premise assertion below is what caught it rather than
                        the case quietly scoring a pass. ---- */
                await search(page, 'Co');

                const state = await page.evaluate(() => {
                    const bar = document.getElementById('ss-command-actions');
                    const list = document.getElementById('ss-command-results');
                    const opts = Array.from(list.querySelectorAll('[role="option"]'));
                    return {
                        barExists: !!bar,
                        /* COMPUTED DISPLAY, NOT `bar.hidden`. The IDL property is true whenever
                           the attribute is set, so it can never distinguish "hidden" from "the
                           attribute is set and a class rule overrode it" -- which review flagged
                           as live here. Measured: it is NOT live, because
                           `public/assets/css/style.css` carries
                           `[hidden] { display: none !important }`, so the attribute wins over any
                           class rule in this admin. Reading the computed value is still the right
                           instrument: it is the only one that could have settled the question. */
                        barHidden: bar ? (getComputedStyle(bar).display === 'none') : null,
                        barHiddenAttr: bar ? bar.hidden : null,
                        insideListbox: bar ? list.contains(bar) : null,
                        role: bar ? bar.getAttribute('role') : null,
                        label: bar ? bar.getAttribute('aria-label') : null,
                        actions: bar ? Array.from(bar.querySelectorAll('.ss-command__action'))
                            .map((a) => ({ text: a.textContent.trim(), href: a.getAttribute('href') })) : [],
                        activeDescendant: document.getElementById('ss-command-input')
                            .getAttribute('aria-activedescendant'),
                        firstOptionTitle: opts.length
                            ? opts[0].querySelector('.ss-command__title').textContent.trim() : null,
                        // THE ARIA INVARIANT: an option is a leaf.
                        interactiveInsideAnOption: opts.some((o) =>
                            o.querySelector('a, button, input, select, textarea, [tabindex]')),
                        tabbableOptions: opts.filter((o) => o.tabIndex >= 0).length,
                    };
                });

                check(`${tag} — the action bar exists`, state.barExists === true);
                check(`${tag} — it is OUTSIDE the listbox`, state.insideListbox === false,
                    'the bar is inside the listbox, so an option is no longer a leaf');
                check(`${tag} — no option contains an interactive descendant`,
                    state.interactiveInsideAnOption === false);

                /* THE OPTIONS ARE NOT TAB STOPS. They are `<a href>` with `role="option"`, so
                   they were natively tabbable -- and an `aria-activedescendant` combobox requires
                   DOM focus to stay on the input, so tabbing into one told a screen reader two
                   contradictory things about what was current. */
                check(`${tag} — the options are not in the tab order`,
                    state.tabbableOptions === 0,
                    String(state.tabbableOptions) + ' option(s) are tabbable, which breaks the '
                    + 'aria-activedescendant contract');

                /* AND THE FIRST TAB FROM THE INPUT REACHES THE ACTION BAR, which is what the
                   fix above is for: the bar used to be sixteen stops away, behind every option. */
                await page.locator('#ss-command-input').focus();
                await page.keyboard.press('Tab');
                await page.waitForTimeout(200);
                const firstStop = await page.evaluate(() => {
                    const el = document.activeElement;
                    return {
                        isAction: !!(el && el.classList
                            && el.classList.contains('ss-command__action')),
                        cls: el ? (el.className || el.tagName) : null,
                    };
                });
                check(`${tag} — one Tab from the input reaches the action bar`,
                    firstStop.isAction === true, String(firstStop.cls));

                /* FOCUS GOES BACK TO THE INPUT before the arrow-key checks below, and forgetting
                   it cost a run: with focus still in the action bar, ArrowDown correctly does
                   NOTHING (that is the target guard this slice added), so the selection never
                   moved and the follow-the-selection premise failed. The guard working looked
                   exactly like a missing fixture. */
                await page.locator('#ss-command-input').focus();
                await page.waitForTimeout(150);
                check(`${tag} — it is a group`, state.role === 'group', String(state.role));
                check(`${tag} — the selection is carried by aria-activedescendant`,
                    !!state.activeDescendant, String(state.activeDescendant));
                check(`${tag} — the bar is shown for a result with an action`,
                    state.barHidden === false, String(state.barHidden));
                check(`${tag} — exactly one action is offered`, state.actions.length === 1,
                    JSON.stringify(state.actions));
                check(`${tag} — the action points at the product edit route`,
                    (state.actions[0] || {}).href && /\/product\/\d+\/edit$/.test(state.actions[0].href),
                    (state.actions[0] || {}).href);
                check(`${tag} — the group names the selected result`,
                    !!state.label && state.label.includes(state.firstOptionTitle), String(state.label));

                if (dir === 'rtl') {
                    check(`${tag} — the action label is translated`,
                        (state.actions[0] || {}).text !== 'Edit product',
                        (state.actions[0] || {}).text);
                } else {
                    check(`${tag} — the action label is the English one`,
                        (state.actions[0] || {}).text === 'Edit product',
                        (state.actions[0] || {}).text);
                }

                /* THE BAR FOLLOWS THE SELECTION. Arrow down to the next product and the action's
                   href must change -- a bar that kept the first result's action would be a link to
                   the wrong record. */
                const firstHref = (state.actions[0] || {}).href;
                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(300);
                const after = await page.evaluate(() => {
                    const bar = document.getElementById('ss-command-actions');
                    const a = bar.querySelector('.ss-command__action');
                    return {
                        href: a ? a.getAttribute('href') : null,
                        label: bar.getAttribute('aria-label'),
                    };
                });
                const moved = await page.evaluate(() => {
                    const id = document.getElementById('ss-command-input')
                        .getAttribute('aria-activedescendant');
                    const el = document.getElementById(id);
                    return el ? el.querySelector('.ss-command__title').textContent.trim() : null;
                });

                if (moved && moved !== state.firstOptionTitle) {
                    check(`${tag} — the action follows the selection`, after.href !== firstHref,
                        `${firstHref} -> ${after.href}`);
                    check(`${tag} — and the group label follows it too`,
                        !!after.label && after.label.includes(moved), String(after.label));
                } else {
                    // Only one hit: say so rather than silently scoring a pass.
                    check(`${tag} — premise: more than one result to move between`, false,
                        'only one result matched the term, so the follow-the-selection case was not driven');
                }

                /* ---- a customer: this merchant has `Manage Customer` and NOT `Show Customer`,
                        so the result must carry NO action and the bar must be hidden. ---- */
                await page.keyboard.press('Escape');
                await page.waitForTimeout(250);
                await search(page, 'Layla');

                const noAction = await page.evaluate(() => {
                    const bar = document.getElementById('ss-command-actions');
                    const id = document.getElementById('ss-command-input')
                        .getAttribute('aria-activedescendant');
                    const el = id ? document.getElementById(id) : null;
                    return {
                        // Computed, not the IDL property -- see above.
                        hidden: getComputedStyle(bar).display === 'none',
                        count: bar.querySelectorAll('.ss-command__action').length,
                        selected: el ? el.querySelector('.ss-command__title').textContent.trim() : null,
                        // nothing anywhere in the dialog should link to a customer record
                        anyCustomerShowLink: !!document.querySelector('#ss-command a[href*="/customer/"]'),
                    };
                });

                check(`${tag} — premise: the customer result is selected`,
                    noAction.selected !== null, String(noAction.selected));
                check(`${tag} — no action is offered without Show Customer`, noAction.count === 0,
                    String(noAction.count));
                check(`${tag} — and the bar is really hidden (computed display)`,
                    noAction.hidden === true, String(noAction.hidden));
                check(`${tag} — no customer record link is in the DOM at all`,
                    noAction.anyCustomerShowLink === false,
                    'an unauthorised action reached the markup and was merely hidden');

                /* ---- an order: this merchant holds `Show Order`, so the action must open the
                        PREVIEW PANEL in place rather than navigate. The `panel` key is new on an
                        action and only the DOM shows whether the script honoured it -- it was
                        silently ignored at first, which turned a read-only in-place surface into
                        a full page load. ---- */
                await page.keyboard.press('Escape');
                await page.waitForTimeout(250);
                await search(page, 'ORD-2041');

                const orderCase = await page.evaluate(() => {
                    const bar = document.getElementById('ss-command-actions');
                    const a = bar.querySelector('.ss-command__action');
                    const id = document.getElementById('ss-command-input')
                        .getAttribute('aria-activedescendant');
                    const el = id ? document.getElementById(id) : null;
                    return {
                        selected: el ? el.querySelector('.ss-command__title').textContent.trim() : null,
                        count: bar.querySelectorAll('.ss-command__action').length,
                        panel: a ? a.getAttribute('data-ss-panel-open') : null,
                        href: a ? a.getAttribute('href') : null,
                        text: a ? a.textContent.trim() : null,
                    };
                });

                check(`${tag} — premise: the order result is selected`,
                    orderCase.selected !== null, String(orderCase.selected));
                check(`${tag} — the order offers exactly one action`, orderCase.count === 1,
                    String(orderCase.count));
                /* NOT A DEAD CLICK. The action used to carry `data-ss-panel-open` for a panel
                   element that exists in only three views, so on this page it pointed at nothing.
                   It is now a real link to the order page, which works from anywhere. */
                check(`${tag} — the order action is a real link, not a panel on a page without one`,
                    orderCase.panel === null, String(orderCase.panel));
                check(`${tag} — it points at the order page`,
                    !!orderCase.href && orderCase.href.includes('order-view/'),
                    String(orderCase.href));

                /* AND IF IT EVER CARRIES A PANEL AGAIN, THE PANEL MUST EXIST. This is the
                   assertion whose absence let the dead click through. */
                if (orderCase.panel) {
                    const exists = await page.evaluate((id) => !!document.getElementById(id),
                        orderCase.panel);
                    check(`${tag} — a panel action's panel exists on this page`, exists === true,
                        orderCase.panel + ' is on no element here');
                }

                /* KEYBOARD: focus the action and press Enter. The palette's keydown is bound to
                   `document` and had no target guard, so Enter activated the selected ROW instead
                   of the focused action -- reproduced by review. Here the URL must not change to
                   the row's destination. */
                const before2 = page.url();
                await page.locator('#ss-command-actions .ss-command__action').first().focus();

                const focusRing = await page.evaluate(() => {
                    const el = document.activeElement;
                    const s2 = getComputedStyle(el);
                    return {
                        isAction: el.classList.contains('ss-command__action'),
                        outlineWidth: s2.outlineWidth,
                        outlineStyle: s2.outlineStyle,
                    };
                });
                check(`${tag} — the action can take focus`, focusRing.isAction === true,
                    String(focusRing.isAction));
                /* WCAG 2.4.7. The host clears outlines on focused links admin-wide, so without an
                   explicit ring the only focus cue was the hover background -- which
                   `:focus-visible` shared, making keyboard focus indistinguishable from hover. */
                check(`${tag} — and it shows a visible focus ring`,
                    focusRing.outlineStyle !== 'none' && parseFloat(focusRing.outlineWidth) > 0,
                    `${focusRing.outlineStyle} ${focusRing.outlineWidth}`);

                await page.keyboard.press('ArrowDown');
                await page.waitForTimeout(250);
                const afterArrow = await page.evaluate(() => ({
                    tag: document.activeElement ? document.activeElement.tagName : null,
                    isAction: document.activeElement
                        ? document.activeElement.classList.contains('ss-command__action') : false,
                }));
                /* Arrowing used to move the row selection, which re-renders the bar and removed
                   the focused element mid-keypress -- focus fell to <body>, outside the modal. */
                check(`${tag} — arrowing inside the bar does not throw focus to the body`,
                    afterArrow.tag !== 'BODY', String(afterArrow.tag));

                check(`${tag} — and Enter did not navigate to the row's destination`,
                    page.url() === before2, page.url());



                /* ---- contrast, on the surface the bar actually paints ---- */
                await page.keyboard.press('Escape');
                await page.waitForTimeout(200);
                await search(page, 'Co');

                const contrast = await page.evaluate(() => {
                    const bgOf = (el) => {
                        let n = el;
                        while (n && n !== document.documentElement) {
                            const c = getComputedStyle(n).backgroundColor;
                            if (c && c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent') return c;
                            n = n.parentElement;
                        }
                        return getComputedStyle(document.body).backgroundColor;
                    };
                    return Array.from(document.querySelectorAll('#ss-command-actions .ss-command__action'))
                        .map((el) => {
                            const s = getComputedStyle(el);
                            return {
                                text: el.textContent.trim().slice(0, 24),
                                fg: s.color, bg: bgOf(el),
                                size: parseFloat(s.fontSize), weight: s.fontWeight,
                                height: el.getBoundingClientRect().height,
                            };
                        });
                });

                check(`${tag} — there is an action to contrast-check`, contrast.length > 0,
                    String(contrast.length));

                for (const c of contrast) {
                    const fg = parse(c.fg), bg = parse(c.bg);
                    const r = (fg && bg) ? ratio(fg, bg) : 0;
                    const large = c.size >= 24 || (c.size >= 18.66 && Number(c.weight) >= 700);
                    const need = large ? 3 : 4.5;
                    check(`${tag} — "${c.text}" meets ${need}:1`, r >= need,
                        `${r.toFixed(2)}:1  ${c.fg} on ${c.bg} @ ${c.size}px`);
                    // WCAG 2.5.5: a real target, beside a list the keyboard drives.
                    check(`${tag} — "${c.text}" is at least 44px tall`, c.height >= 43.5,
                        c.height.toFixed(1) + 'px');
                }

                /* THE POSITIVE HALF, and its absence was a real gap in this harness. Everything
                   above checks that the new guard STOPS the palette hijacking keys aimed at the
                   action bar. Nothing checked that Enter still works from the INPUT -- the
                   dialog's primary interaction, and the thing both the guard and `tabIndex = -1`
                   touch. A guard with only the negative half can over-match and break Enter
                   entirely unnoticed: `inActions` returning true unconditionally would have
                   passed every assertion above.

                   Driven on a PRODUCT row, because an order row opens a PANEL in place rather
                   than navigating, so the URL correctly does not change -- the first version of
                   this check asserted against an order row and failed for that reason, which is
                   the check being wrong rather than Enter being broken. It is also how the ROW's
                   own dead click on panel-less pages surfaced (F145, P2-06-2's contract, not
                   this slice's). It runs last because it leaves the dialog. */
                await page.keyboard.press('Escape');
                await page.waitForTimeout(200);
                await search(page, 'Co');

                const rowTarget = await page.evaluate(() => {
                    const id = document.getElementById('ss-command-input')
                        .getAttribute('aria-activedescendant');
                    const el = id ? document.getElementById(id) : null;
                    return el ? { href: el.getAttribute('href'),
                                  panel: el.getAttribute('data-ss-panel-open') } : null;
                });

                check(`${tag} — premise: a navigating row is selected`,
                    !!rowTarget && !!rowTarget.href && !rowTarget.panel,
                    JSON.stringify(rowTarget));

                if (rowTarget && rowTarget.href && !rowTarget.panel) {
                    await page.locator('#ss-command-input').focus();
                    await page.keyboard.press('Enter');
                    await page.waitForLoadState('domcontentloaded').catch(() => {});
                    await page.waitForTimeout(700);

                    const want = rowTarget.href.replace(/^https?:\/\/[^/]+/, '');
                    check(`${tag} — Enter from the input still opens the selected row`,
                        page.url().includes(want),
                        `expected ${want}, got ${page.url()}`);
                }

                check(`${tag} — no JavaScript errors`, errors.length === 0, errors.join(' | '));

                await ctx.close();
            }
        }
    } finally {
        setDirection('en');
        setSetting('cust_darklayout', 'off');
        await browser.close();
    }

    if (failures.length) {
        console.log(`\n${failures.length} of ${pass + failures.length} cases FAILED`);
        process.exit(1);
    }
    console.log(`\n${pass} cases passed — the actions reach the screen, follow the selection, stay outside the listbox, and an unpermitted action never reaches the DOM`);
})().catch((e) => { console.error('FATAL ' + e.message); process.exit(1); });
