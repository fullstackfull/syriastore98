#!/usr/bin/env node
/**
 * P2-06-12 — task-oriented instructions where a merchant must choose, in the accessibility tree.
 * =================================================================================================
 *
 * THE LEDGER ASKS FOR THE ACCESSIBILITY TREE, NOT THE MARKUP, and that is the right instrument
 * for this row: the defect it fixes was markup that LOOKED associated and was not. "Stock Status:"
 * was a `<label>` with an empty `for` above a bare `<div>` of radios — present on the page,
 * absent from the tree. A regex over HTML cannot tell those apart; Playwright's accessibility
 * snapshot is the computed result, which can.
 *
 * SO WHAT IS ASSERTED IS WHAT A SCREEN READER WOULD GET: for each radio in each group, the
 * computed accessible DESCRIPTION must carry the group's instruction, and the group itself must
 * appear as a named group. Both are read from `page.accessibility.snapshot()` and from the
 * computed `aria-describedby` resolution, in English and in Arabic.
 *
 * THE FIELDSET IS ALSO CHECKED NOT TO HAVE BROKEN THE LAYOUT. A `<fieldset>` has an implicit
 * minimum content size in every engine, so dropping one into a flex row makes it refuse to shrink
 * and overflow. An accessibility fix that visibly breaks the editor is one that gets reverted, so
 * the group's width is compared against its container.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 serving THIS repository, launched from inside `public/`;
 *   - `database/p2dev.sqlite` seeded, and `stock_management` ON for store 1 — both groups live
 *     behind that setting, so without it the page has no groups at all and every assertion here
 *     would be measuring a feature flag. Set and asserted below.
 *
 * usage: node docs/development/phase-2/tools/check-choice-guidance-live.cjs [baseUrl]
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const SHOTS = path.join(REPO, 'docs/development/phase-2/evidence/p2-06-12');
const ENV = Object.assign({}, process.env, {
    DB_CONNECTION: 'sqlite', DB_DATABASE: REPO + '/database/p2dev.sqlite',
    CACHE_STORE: 'file', SESSION_DRIVER: 'file',
});

/* THE TWO GROUPS ARE MUTUALLY EXCLUSIVE, measured rather than assumed, and each has to be driven
   in the state where it is visible. With stock tracking OFF the manual status picker is shown
   (94px) and the backorder group is collapsed (0px); with it ON they swap (0px / 146px). That is
   sensible product behaviour -- a tracked product's status is derived from its quantity, so the
   manual picker would contradict it -- and it is why `track_stock` is set per group below.

   Getting this wrong produced two successive false readings: first the backorder group reported no
   accessible name (it was hidden), then after revealing it the status group did (now hidden). A
   hidden subtree is CORRECTLY absent from the accessibility tree, so both readings were true of
   the page and false of the component. */
const GROUPS = [
    { name: 'stock_status', radios: ['in_stock', 'out_of_stock', 'on_backorder'], tracking: false },
    { name: 'stock_order_status', radios: ['not_allow', 'notify_customer', 'allow'], tracking: true },
];

let pass = 0; const failures = [];
function check(name, ok, detail) {
    if (ok) { pass++; return; }
    failures.push(name);
    console.log('  FAIL  ' + name + (detail ? '  [' + detail + ']' : ''));
}

function php(code) {
    return execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ' + code],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();
}

function setSetting(name, value) {
    return php('$u = App\\Models\\User::where("email","merchant@example.test")->firstOrFail();'
        + ' $s = (int) $u->current_store;'
        + ' $r = App\\Models\\Setting::withoutGlobalScopes()->where("store_id",$s)'
        + '     ->where("name","' + name + '")->first();'
        + ' if ($r) { $r->value = "' + value + '"; $r->save(); }'
        + ' else { App\\Models\\Setting::create(["name"=>"' + name + '","value"=>"' + value + '",'
        + '     "store_id"=>$s,"created_by"=>$u->creatorId()]); }'
        + ' (new App\\Models\\Setting)->flushCache();'
        + ' echo App\\Models\\Setting::withoutGlobalScopes()->where("store_id",$s)'
        + '     ->where("name","' + name + '")->value("value");');
}

function setDirection(lang) {
    php('$u = App\\Models\\User::where("email","merchant@example.test")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();');
    setSetting('SITE_RTL', (lang === 'ar' || lang === 'he') ? 'on' : 'off');
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    if (page.url().includes('/login')) throw new Error('login failed');
}

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });

    // PREMISE, in the database: both groups live behind this setting.
    const stock = setSetting('stock_management', 'on');
    check('premise: stock management is on for store 1', stock === 'on', stock);

    const browser = await chromium.launch();

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            setDirection(lang);

            const ctx = await browser.newContext({ viewport: { width: 1500, height: 1000 } });
            const page = await ctx.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));

            /* THE BROWSER'S OWN COMPUTED ACCESSIBILITY NODE, via CDP. `page.accessibility` does
               not exist in Playwright 1.63, and `locator.ariaSnapshot()` gives the role and name
               but not the computed DESCRIPTION -- which is the half this row is about. CDP's
               `Accessibility.getPartialAXTree` returns what the engine actually computed:
               {role, name, description}. Nothing else can distinguish a real association from
               markup that merely looks like one, which was the original defect. */
            const cdp = await ctx.newCDPSession(page);
            await cdp.send('Accessibility.enable');

            const axNode = async (selector) => {
                const { root } = await cdp.send('DOM.getDocument');
                const { nodeId } = await cdp.send('DOM.querySelector',
                    { nodeId: root.nodeId, selector });
                if (!nodeId) return null;
                const { nodes } = await cdp.send('Accessibility.getPartialAXTree',
                    { nodeId, fetchRelatives: false });
                const n = (nodes || [])[0];
                return n ? {
                    role: n.role && n.role.value,
                    name: n.name && n.name.value,
                    desc: n.description && n.description.value,
                } : null;
            };

            await login(page);
            await page.goto(BASE + '/product/create', { waitUntil: 'domcontentloaded' });
            await page.waitForTimeout(900);

            const htmlDir = (await page.getAttribute('html', 'dir')) || 'ltr';
            check(`${dir} — the page is really ${dir}`, (htmlDir === 'rtl') === (dir === 'rtl'), htmlDir);

            const stockToggle = page.locator('input.enable_product_stock').first();

            for (const group of GROUPS) {
                const sel = `fieldset[data-ss-choicegroup="${group.name}"]`;

                const present = await page.locator(sel).count();
                check(`${dir} ${group.name} — the group renders as a fieldset`, present === 1,
                    String(present));
                if (present !== 1) continue;

                // Put the screen into the state where THIS group is the one on show.
                if (await stockToggle.count() === 1) {
                    if (group.tracking) { await stockToggle.check().catch(() => {}); }
                    else { await stockToggle.uncheck().catch(() => {}); }
                    await page.waitForTimeout(400);
                }

                /* PREMISE, asserted rather than assumed: a silent failure to reveal would put
                   back exactly the vacuous reading this restructuring removed. */
                const revealed = await page.evaluate((s2) => {
                    const el = document.querySelector(s2);
                    return !!(el && el.getBoundingClientRect().height > 0);
                }, sel);
                check(`${dir} ${group.name} — premise: the group is visible`, revealed === true,
                    'the group is collapsed, so the accessibility tree legitimately has no name '
                    + 'for it and every assertion below would be vacuous');
                if (!revealed) continue;

                const ax = await axNode(sel);
                const legendText = (await page.locator(sel + ' legend').first().textContent() || '').trim();

                check(`${dir} ${group.name} — the group is in the accessibility tree`,
                    ax !== null, 'the fieldset is not exposed at all');
                check(`${dir} ${group.name} — its computed role is a group`,
                    !!ax && ax.role === 'group', ax && ax.role);
                check(`${dir} ${group.name} — it has a legend with text`, legendText.length > 0,
                    legendText);
                check(`${dir} ${group.name} — the COMPUTED NAME is the legend`,
                    !!ax && typeof ax.name === 'string' && ax.name.trim() === legendText,
                    `computed "${ax && ax.name}" vs legend "${legendText}"`);

                /* THE COMPUTED DESCRIPTION -- the requirement itself. This is the engine's own
                   resolution of aria-describedby, so a dangling id shows up as an empty
                   description rather than as markup that looks fine. */
                check(`${dir} ${group.name} — the instruction is the COMPUTED DESCRIPTION`,
                    !!ax && typeof ax.desc === 'string' && ax.desc.trim().length > 30,
                    `computed description: "${ax && ax.desc}"`);

                if (dir === 'rtl') {
                    check(`${dir} ${group.name} — the computed description is in Arabic`,
                        !!ax && /[\u0600-\u06FF]/.test(ax.desc || ''), String(ax && ax.desc));
                    check(`${dir} ${group.name} — the computed name is in Arabic`,
                        !!ax && /[\u0600-\u06FF]/.test(ax.name || ''), String(ax && ax.name));
                }

                /* And the radios are named in the tree too -- the `for="   "` bug made one
                   nameless, and a nameless radio is announced as "radio button" and nothing more. */
                const snapshot = await page.locator(sel).ariaSnapshot();
                check(`${dir} ${group.name} — the snapshot shows a named group`,
                    snapshot.includes('- group "'), snapshot.split('\n')[0]);
                for (const id of group.radios) {
                    const label = await page.evaluate((rid) => {
                        const lab = document.querySelector('label[for="' + rid + '"]');
                        return lab ? lab.textContent.trim() : null;
                    }, id);
                    check(`${dir} ${group.name} — radio ${id} is named in the tree`,
                        !!label && snapshot.includes('radio "' + label + '"'),
                        `label "${label}" not found as a named radio in the snapshot`);
                }

                /* THE INSTRUCTION, resolved the way a reader resolves it: follow
                   `aria-describedby` from the group to the element and read its text. A dangling
                   id resolves to nothing, which is the failure mode this catches. */
                const described = await page.evaluate((s) => {
                    const fs2 = document.querySelector(s);
                    const ref = fs2 && fs2.getAttribute('aria-describedby');
                    if (!ref) return { ref: null, text: null };
                    const parts = ref.split(/\s+/).filter(Boolean).map((id) => {
                        const el = document.getElementById(id);
                        return el ? el.textContent.trim() : null;
                    });
                    return { ref: ref, text: parts.every((t) => t !== null) ? parts.join(' ') : null };
                }, sel);

                check(`${dir} ${group.name} — the group has an aria-describedby`,
                    !!described.ref, String(described.ref));
                check(`${dir} ${group.name} — it resolves to real text`,
                    !!described.text && described.text.length > 30, String(described.text));

                if (dir === 'rtl') {
                    check(`${dir} ${group.name} — the instruction is in Arabic`,
                        !!described.text && /[؀-ۿ]/.test(described.text),
                        String(described.text));
                    check(`${dir} ${group.name} — the legend is in Arabic`,
                        /[؀-ۿ]/.test(legendText), legendText);
                }

                /* EVERY OPTION'S LABEL NAMES IT IN THE TREE. The `for="   "` bug made one radio
                   nameless, and a nameless radio is announced as "radio button" and nothing else. */
                for (const id of group.radios) {
                    const radio = page.locator('#' + id);
                    if (await radio.count() !== 1) {
                        check(`${dir} ${group.name} — option ${id} exists`, false, 'missing');
                        continue;
                    }
                    const name = await radio.evaluate((el) => {
                        const lab = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
                        return lab ? lab.textContent.trim() : null;
                    });
                    check(`${dir} ${group.name} — option ${id} is named by a label`,
                        !!name && name.length > 0, String(name));
                }

                /* THE LAYOUT DID NOT BREAK. A fieldset's implicit min-width makes it overflow a
                   flex row; if it has, the accessibility fix will be reverted by whoever notices. */
                const fits = await page.evaluate((s) => {
                    const el = document.querySelector(s);
                    const parent = el.parentElement;
                    return el.getBoundingClientRect().width <= parent.getBoundingClientRect().width + 1;
                }, sel);
                check(`${dir} ${group.name} — the fieldset does not overflow its container`,
                    fits === true, 'the implicit fieldset min-width broke the row');
            }

            if (await stockToggle.count() === 1) { await stockToggle.check().catch(() => {}); }
            await page.waitForTimeout(400);
            await page.locator('fieldset[data-ss-choicegroup="stock_order_status"]')
                .scrollIntoViewIfNeeded().catch(() => {});
            await page.waitForTimeout(250);
            await page.screenshot({
                path: path.join(SHOTS, `choice-guidance-${lang}.png`), fullPage: false,
            });

            check(`${dir} — no JavaScript errors`, errors.length === 0, errors.join(' | '));

            await ctx.close();
        }
    } finally {
        setDirection('en');
        await browser.close();
    }

    const shots = fs.readdirSync(SHOTS).filter((f) => f.endsWith('.png'));
    const distinct = new Set(shots.map((f) => require('crypto').createHash('sha1')
        .update(fs.readFileSync(path.join(SHOTS, f))).digest('hex')));
    check('every screenshot is distinct', distinct.size === shots.length,
        `${shots.length} screenshots, ${distinct.size} distinct`);

    if (failures.length) {
        console.log(`\n${failures.length} of ${pass + failures.length} cases FAILED`);
        process.exit(1);
    }
    console.log(`\n${pass} cases passed, ${shots.length} screenshots — both choice groups are named groups in the accessibility tree with their instruction resolved, in both languages`);
})().catch((e) => { console.error('FATAL ' + e.message); process.exit(1); });
