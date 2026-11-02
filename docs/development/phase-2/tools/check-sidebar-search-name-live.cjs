/*
 * F217-adjacent accessibility finding — THE SIDEBAR SEARCH FIELD HAD NO ACCESSIBLE NAME.
 *
 * `partision/sidebar.blade.php:38` rendered
 *   <input type="text" class="form-control" id="sideBarsearch" placeholder="{{ __('Search here...') }}">
 *
 * WHAT THIS IS, AFTER THE MEASUREMENT CORRECTED IT. The audit called the field nameless, and I
 * repeated that. **It was not.** This tool's first version asserted that removing `aria-label`
 * would stop the engine resolving a name, and that assertion FAILED in both directions: Chromium
 * computes a name from `placeholder` for a text input, which is step 2(f) of the accname fallback
 * and correct behaviour.
 *
 * So the fix is a ROBUSTNESS improvement rather than the repair of a nameless control, and the two
 * things it actually buys are what this tool now measures: the name is EXPLICIT and wins the
 * precedence order rather than depending on a fallback that older assistive technology implements
 * inconsistently; and the name PERSISTS WHILE THE USER TYPES, where a placeholder is visually
 * replaced by the user's own text — the moment they most need to confirm what they are typing
 * into.
 *
 * WHY A BROWSER AND NOT A GREP. The accessible name is COMPUTED by the browser from the whole
 * accessibility tree — label, aria-label, aria-labelledby, title, placeholder, in that precedence —
 * so only a real engine can say what a screen reader would announce. A source assertion proves the
 * attribute is written; this proves the name is computed. Both are kept: the unit test is the
 * ratchet, this is the evidence.
 *
 * AND IT IS DRIVEN IN BOTH DIRECTIONS, because the sidebar is the shared chrome of every merchant
 * screen and Arabic is the platform's primary language. The probe reads `aria-label` AND the
 * computed name, so a fix that satisfies one and not the other is visible.
 *
 * usage: node docs/development/phase-2/tools/check-sidebar-search-name-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync, spawn } = require('child_process');
const path = require('path');

const REPO = '/home/user/syriastore98';
const TOOLS = path.join(REPO, 'docs/development/phase-2/tools');
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file', SESSION_DRIVER: 'file',
});

let failures = 0;
const ok = (c, m) => { console.log((c ? '  [ OK ] ' : '  [FAIL] ') + m); if (!c) { failures++; } };

(async () => {
    const server = spawn('php', ['-S', '127.0.0.1:8899', '-t', path.join(REPO, 'public')],
        { cwd: REPO, env: ENV, stdio: 'ignore' });
    await new Promise(r => setTimeout(r, 2500));

    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            execFileSync('php', [path.join(TOOLS, 'set-admin-preview-state.php'), '1', 'light', dir, lang,
                'merchant@example.test'], { cwd: REPO, env: ENV, stdio: 'pipe' });

            const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
            await ctx.route('**/merchant-notifications/feed*', r => r.abort());
            const page = await ctx.newPage();

            await page.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
            await page.fill('input[name=email]', 'merchant@example.test');
            await page.fill('input[name=password]', 'password');
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
                page.click('button[type=submit]'),
            ]);
            await page.goto('http://127.0.0.1:8899/dashboard', { waitUntil: 'domcontentloaded' });

            console.log('\n--- ' + lang.toUpperCase() + ' / ' + dir.toUpperCase());

            const field = await page.$('#sideBarsearch');
            ok(!!field, 'the sidebar search field is rendered at all (premise: if this fails the rest is vacuous)');

            if (!field) { await ctx.close(); continue; }

            const attrs = await page.evaluate(() => {
                const el = document.querySelector('#sideBarsearch');
                return {
                    ariaLabel: el.getAttribute('aria-label'),
                    placeholder: el.getAttribute('placeholder'),
                    hasLabelFor: !!document.querySelector('label[for="sideBarsearch"]'),
                };
            });

            ok(!!attrs.ariaLabel && attrs.ariaLabel.trim().length > 1,
                'it carries a non-empty aria-label: ' + JSON.stringify(attrs.ariaLabel));

            ok(!!attrs.placeholder,
                'the visible placeholder is KEPT, because it still helps sighted users: '
                + JSON.stringify(attrs.placeholder));

            /* THE COMPUTED ACCESSIBLE NAME, resolved by the engine rather than read off the DOM.
               `getByRole('textbox', { name })` performs the real ARIA name computation and matches
               only if the browser arrives at that name — which is the thing a screen reader
               announces, and the reason this tool exists rather than a grep.

               `page.accessibility.snapshot()` would be the direct route and is GONE from this
               Playwright version (measured: `page.accessibility` is undefined), so the role+name
               locator is the supported equivalent. */
            const expected = String(attrs.ariaLabel || '').trim();
            const byName = page.getByRole('textbox', { name: expected, exact: true });
            const matched = await byName.count();

            ok(matched >= 1,
                'the browser COMPUTES that name for the field: getByRole("textbox", { name: '
                + JSON.stringify(expected) + ' }) matched ' + matched);

            /* And it is the field we think it is, not another textbox that happens to share a
               name -- otherwise the assertion above could pass against the header's own search. */
            const isTheSidebarField = matched >= 1
                ? await byName.first().evaluate(el => el.id === 'sideBarsearch')
                : false;

            ok(isTheSidebarField,
                'and the element the name resolves to IS #sideBarsearch, not another textbox');

            /* ===========================================================================
               WHAT THE MEASUREMENT REFUTED, AND THE CLAIM IS CORRECTED RATHER THAN DROPPED.
               ===========================================================================

               This tool first asserted that with `aria-label` removed the engine would stop
               resolving the name -- on the premise that "a placeholder is not an accessible name".
               IT FAILED, IN BOTH DIRECTIONS: 1 match with the label and 1 without. Chromium DOES
               compute a name from `placeholder` for a text input; that is step 2(f) of the accname
               fallback and it is correct behaviour, not a browser bug.

               SO THE FIELD WAS NOT NAMELESS, and the audit's framing -- which I repeated -- was too
               strong. What the fix actually buys is narrower and still real:

                 (1) THE NAME IS NOW EXPLICIT AND WINS THE PRECEDENCE ORDER, so it no longer depends
                     on a fallback that older assistive technology implements inconsistently.
                 (2) THE NAME PERSISTS WHILE THE USER TYPES. A placeholder is visually replaced by
                     the user's own text; an `aria-label` is not. That is the moment a user most
                     needs to confirm what they are typing into, and it is measurable.

               Both are asserted below, against the live DOM, instead of the claim that failed. */

            await page.evaluate(() => {
                const el = document.querySelector('#sideBarsearch');
                el.setAttribute('placeholder', 'ZZ-PLACEHOLDER-DIFFERENT');
            });

            const nameFollowsLabel = await page
                .getByRole('textbox', { name: expected, exact: true })
                .filter({ hasNot: page.locator('x-none') })
                .count();

            const nameFollowsPlaceholder = await page
                .getByRole('textbox', { name: 'ZZ-PLACEHOLDER-DIFFERENT', exact: true })
                .count();

            ok(nameFollowsLabel >= 1 && nameFollowsPlaceholder === 0,
                '(1) with the placeholder changed to a DIFFERENT string, the computed name still '
                + 'follows the aria-label (' + nameFollowsLabel + ') and not the placeholder ('
                + nameFollowsPlaceholder + ') -- so the name is explicit, not a fallback');

            await page.evaluate(() => {
                const el = document.querySelector('#sideBarsearch');
                el.setAttribute('placeholder', 'ZZ-PLACEHOLDER-DIFFERENT');
                el.value = 'some text the merchant typed';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });

            const namedWhileTyping = await page
                .getByRole('textbox', { name: expected, exact: true })
                .count();

            ok(namedWhileTyping >= 1,
                '(2) and the name survives the user typing into the field -- which is exactly when '
                + 'a placeholder-derived name disappears');

            /* Restore, so the AR pass is not measuring the EN pass's mutations. */
            await page.evaluate((label) => {
                const el = document.querySelector('#sideBarsearch');
                el.setAttribute('placeholder', label);
                el.value = '';
            }, expected);

            const computed = expected;

            /* In Arabic it must be the Arabic string, not an untranslated fallback -- the defect
               this project has hit before is a control that is named in English on an Arabic page. */
            if (lang === 'ar') {
                ok(/[؀-ۿ]/.test(computed),
                    'in Arabic the announced name is Arabic text, not an English fallback');
            }

            await ctx.close();
        }
    } finally {
        await browser.close();
        server.kill();
    }

    console.log('\n======================================================================');
    console.log(failures === 0
        ? 'every check held: the sidebar search field is named in both directions'
        : failures + ' check(s) FAILED');
    process.exit(failures === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
