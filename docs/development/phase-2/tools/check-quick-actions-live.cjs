/**
 * P2-01-18 / WS B5 / H5 — the quick-action surface, reached from anywhere, by keyboard.
 *
 * WHAT THIS ROW TURNED OUT TO BE. Its acceptance is "a quick-action surface the merchant can reach
 * from anywhere, permission-aware, and reachable from search results; the host theme's own
 * 'Quick Add' is not ours and does not satisfy this". Measured before building anything, all three
 * clauses were already answered by shipped work:
 *
 *   - FROM ANYWHERE: `layouts/app.blade.php:537` includes `partision.command-palette`, so it is on
 *     every merchant screen, and Ctrl/Cmd+K is bound on `document`. That is what this tool drives.
 *   - PERMISSION-AWARE: `MerchantSearchController::may()` is operator-first and resolves each
 *     action grant separately (P2-06-8), asserted by `MerchantSearchAuthorizationTest` -- including
 *     "a section the caller may not see is absent, NOT EMPTY" and the positive case, "the same
 *     staff member sees the section once granted".
 *   - FROM SEARCH RESULTS: P2-06-8, DONE.
 *
 * So the work was to VERIFY rather than to build -- and building a second quick-action surface
 * beside the palette is exactly the parallel mechanism the owner's standing limits forbid.
 *
 * WHY THE KEYPRESS AND NOT A GREP. "Reachable from anywhere" is a claim about a running page:
 * the include could be present while a JS error on a particular screen stops the binding ever
 * attaching. So Ctrl+K is pressed for real, on several screens, in both languages.
 *
 * usage: node docs/development/phase-2/tools/check-quick-actions-live.cjs [baseUrl] [storeId]
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const STORE = process.argv[3] || '1';
const REPO = '/home/user/syriastore98';
const EMAIL = 'merchant@example.test';
const PASSWORD = 'password';
const PAGES = ['/order', '/product', '/customer', '/coupon', '/order-kanban', '/product/create'];

const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'file',
});

let failures = 0;
function check(label, ok, detail) {
    process.stdout.write(`${ok ? 'ok  ' : 'FAIL'}  ${label}${detail ? '  -- ' + detail : ''}\n`);
    if (!ok) { failures++; }
}

const STATE = `(function () {
    var root = document.getElementById('ss-command');
    if (!root) { return { present: false }; }
    var cs = getComputedStyle(root);
    return {
        present: true,
        open: !root.hasAttribute('hidden') && cs.display !== 'none',
        modal: root.getAttribute('aria-modal'),
        role: root.getAttribute('role'),
        labelled: !!(root.getAttribute('aria-label') || root.getAttribute('aria-labelledby')),
        focusInside: !!(document.activeElement && document.activeElement.closest
                        && document.activeElement.closest('#ss-command')),
        favourites: document.querySelectorAll('#ss-command [data-ss-favourite]').length,
        /* D-24a. The four create-actions, read from the rendered page rather than from the Blade:
           the group is server-rendered, so a permission or module decision that went wrong shows
           up here as a missing or extra row. The group order is read too, because it is part of
           the claim: the fixed, short create list must not sit below a growing favourites list.
           (No backticks in this comment -- it lives inside a template literal, which is how
           measure-contrast-live.cjs was broken once already.) */
        quickActions: Array.prototype.map.call(
            document.querySelectorAll('#ss-command [data-ss-quick-action]'),
            function (a) {
                return {
                    name: a.getAttribute('data-ss-quick-action'),
                    title: (a.querySelector('.ss-command__shortcuttitle') || {}).textContent || '',
                    href: a.getAttribute('href') || '',
                    tabbable: a.tabIndex >= 0,
                };
            }
        ),
        groupHeads: Array.prototype.map.call(
            document.querySelectorAll('#ss-command .ss-command__grouphead'),
            function (p) { return (p.textContent || '').trim(); }
        ),
    };
})()`;

(async () => {
    const browser = await chromium.launch({ args: ['--no-sandbox'] });

    for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
        execFileSync('php', [
            path.join(__dirname, 'set-admin-preview-state.php'),
            STORE, 'light', dir, lang, EMAIL,
        ], { cwd: REPO, env: ENV, stdio: 'pipe' });

        const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
        await ctx.route('**/merchant-notifications/feed*', r => r.abort());
        const page = await ctx.newPage();

        await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name=email]', EMAIL);
        await page.fill('input[name=password]', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
            page.click('button[type=submit]'),
        ]);

        for (const url of PAGES) {
            const resp = await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
            if (!resp || resp.status() !== 200) {
                check(`[${lang}] ${url} reachable`, false, `HTTP ${resp ? resp.status() : 0} -- precondition, not a result`);
                continue;
            }

            const before = await page.evaluate(STATE);
            if (!before.present || before.open) {
                check(`[${lang}] ${url}: the palette starts present and closed`, false,
                    `present=${before.present} open=${before.open}`);
                continue;
            }

            // The real shortcut, on the real page.
            await page.keyboard.press('Control+k');
            await page.waitForFunction(() => {
                var r = document.getElementById('ss-command');
                return !!r && !r.hasAttribute('hidden');
            }, { timeout: 3000 }).catch(() => {});

            const after = await page.evaluate(STATE);

            check(`[${lang}] ${url}: Ctrl+K opens the quick-action surface`, after.open === true,
                `open=${after.open}`);
            check(`[${lang}] ${url}: it is a labelled modal dialog`,
                after.role === 'dialog' && after.modal === 'true' && after.labelled === true,
                `role=${after.role} aria-modal=${after.modal} labelled=${after.labelled}`);
            check(`[${lang}] ${url}: focus moves into it`, after.focusInside === true);

            /* D-24a — the four create-actions, on the real page, in this language.

               FOUR SEPARATE CLAIMS, because they fail separately: the right rows are present; each
               carries a real href (a route that did not resolve would render as an empty attribute
               or throw in the view); each is in the tab order, which is what "keyboard navigation
               is preserved" means for a link; and the group sits ABOVE the favourites, which is the
               layout decision rather than an accident of markup order. */
            /* SUBSET AND RELATIVE ORDER, not "all four" -- and that correction is itself the
               measurement. The first version of this check demanded four and reported three on
               every page in both languages. The dev merchant holds `Manage Coupon` and NOT
               `Create Coupon`, so `create-coupon` is withheld: the page is available to them and
               the action's own permission is not, which is exactly the case
               MerchantQuickActionTargetsTest::test_the_menu_page_alone_does_not_earn_the_action
               covers. The three that render ARE the correct answer for this principal, and a tool
               that insisted on four would have been reporting the permission gate as a defect.
               The grant-and-recheck at the end of this run is what proves the absence is the
               permission rather than a broken row. */
            const approved = ['create-product', 'create-order', 'create-customer', 'create-coupon'];
            const got = after.quickActions.map(a => a.name);

            check(`[${lang}] ${url}: every rendered create-action is one of the approved four`,
                got.length > 0 && got.every(n => approved.includes(n)), `got=${JSON.stringify(got)}`);

            check(`[${lang}] ${url}: they render in the approved relative order`,
                JSON.stringify(got) === JSON.stringify(approved.filter(n => got.includes(n))),
                `got=${JSON.stringify(got)}`);

            check(`[${lang}] ${url}: every create-action has a real href`,
                after.quickActions.length > 0
                && after.quickActions.every(a => /^https?:\/\/|^\//.test(a.href)),
                after.quickActions.map(a => a.name + '=' + a.href).join(' | '));

            check(`[${lang}] ${url}: every create-action is in the tab order`,
                after.quickActions.length > 0 && after.quickActions.every(a => a.tabbable),
                after.quickActions.map(a => a.name + '=' + a.tabbable).join(' | '));

            check(`[${lang}] ${url}: the create group is rendered above Favourites`,
                after.groupHeads.length > 0 && after.groupHeads[0] !== 'Favourites'
                    ? true
                    : after.groupHeads[0] === undefined,
                `heads=${JSON.stringify(after.groupHeads)}`);

            /* AND THE COPY IS REALLY TRANSLATED, measured on the rendered page. The source ratchet
               proves a key exists in ar.json; only the DOM proves the Arabic reached the screen. */
            if (lang === 'ar') {
                const arabic = /[\u0600-\u06FF]/;
                check(`[${lang}] ${url}: every create-action label is Arabic`,
                    after.quickActions.length > 0
                    && after.quickActions.every(a => arabic.test(a.title)),
                    after.quickActions.map(a => a.name + '="' + a.title.trim() + '"').join(' | '));
            } else {
                check(`[${lang}] ${url}: every create-action label is non-empty`,
                    after.quickActions.length > 0
                    && after.quickActions.every(a => a.title.trim().length > 0),
                    after.quickActions.map(a => a.name + '="' + a.title.trim() + '"').join(' | '));
            }

            await page.keyboard.press('Escape');
            const closed = await page.evaluate(STATE);
            check(`[${lang}] ${url}: Escape closes it again`, closed.open === false, `open=${closed.open}`);
        }

        await ctx.close();
    }

    /* THE LIVE BITE. `create-coupon` is absent above; this proves the reason is the permission and
       not a broken row, by granting `Create Coupon` to the dev merchant, reloading, and watching it
       appear -- then revoking it and watching it go. A tool that only ever observes one permission
       state cannot tell "correctly withheld" from "never renders". Dev sqlite only. */
    {
        const grant = (sql) => execFileSync('php', ['-r', sql], { cwd: REPO, env: ENV, stdio: 'pipe' });

        /* THE CACHE, AND IT IS THE REASON THE FIRST VERSION OF THIS BITE DID NOT BITE. Granting the
           permission in sqlite changed nothing on screen: Laratrust caches a user's roles and
           permissions in the CACHE STORE, and this server runs CACHE_STORE=file, so clearing the
           `cache` TABLE -- which is what I did first -- cleared the wrong thing entirely. It is the
           same trap CLAUDE.md names for `Cachable` models: a write that bypasses the cache leaves
           reads serving the pre-write value. `cache:clear` empties the store the app is actually
           using, which is the only way this measurement means anything. */
        const clearCaches = () => execFileSync('php', ['artisan', 'cache:clear'],
            { cwd: REPO, env: ENV, stdio: 'pipe' });
        const DB = "$db = new PDO('sqlite:' . getenv('DB_DATABASE'));";
        const ROLE = "$rid = $db->query(\"select ru.role_id from role_user ru join users u on u.id=ru.user_id where u.email='merchant@example.test'\")->fetchColumn();";
        const PERM = "$pid = $db->query(\"select id from permissions where name='Create Coupon'\")->fetchColumn(); if (!$pid) { $db->exec(\"insert into permissions (name, display_name, created_at, updated_at) values ('Create Coupon','Create Coupon',datetime('now'),datetime('now'))\"); $pid = $db->lastInsertId(); }";

        execFileSync('php', [
            path.join(__dirname, 'set-admin-preview-state.php'), STORE, 'light', 'ltr', 'en', EMAIL,
        ], { cwd: REPO, env: ENV, stdio: 'pipe' });

        const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 } });
        await ctx.route('**/merchant-notifications/feed*', r => r.abort());
        const page = await ctx.newPage();
        await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' });
        await page.fill('input[name=email]', EMAIL);
        await page.fill('input[name=password]', PASSWORD);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
            page.click('button[type=submit]'),
        ]);

        const namesNow = async () => {
            await page.goto(BASE + '/order', { waitUntil: 'domcontentloaded' });
            await page.keyboard.press('Control+k');
            await page.waitForFunction(() => {
                var r = document.getElementById('ss-command');
                return !!r && !r.hasAttribute('hidden');
            }, { timeout: 3000 }).catch(() => {});

            return (await page.evaluate(STATE)).quickActions.map(a => a.name);
        };

        const before = await namesNow();
        check('live bite: create-coupon is absent before the grant',
            !before.includes('create-coupon'), `got=${JSON.stringify(before)}`);

        grant(DB + ROLE + PERM + "$db->exec(\"insert into permission_role (permission_id, role_id) values ($pid, $rid)\");");
        clearCaches();

        const granted = await namesNow();
        check('live bite: granting Create Coupon makes the action appear',
            granted.includes('create-coupon'), `got=${JSON.stringify(granted)}`);

        grant(DB + ROLE + "$pid = $db->query(\"select id from permissions where name='Create Coupon'\")->fetchColumn(); $db->exec(\"delete from permission_role where permission_id=$pid and role_id=$rid\");");
        clearCaches();

        const revoked = await namesNow();
        check('live bite: revoking it again removes the action',
            !revoked.includes('create-coupon'), `got=${JSON.stringify(revoked)}`);

        await ctx.close();
    }

    await browser.close();
    process.stdout.write(`\n${failures} check(s) failed\n`);
    process.exit(failures ? 1 : 0);
})();
