#!/usr/bin/env node
/**
 * P2-06-11 — helpful empty states, driven in a real browser.
 * =================================================================================================
 *
 * WHAT ONLY A BROWSER CAN ANSWER. `EmptyStateTest` proves the strings: that both keys exist, that
 * they differ, that the filtered one never suggests creating a record, and that Arabic really is
 * Arabic rather than `__()` falling back to the key. None of it proves DataTables RENDERS them --
 * the builder passes a `language` array and the plugin decides what to do with it, and the whole
 * defect this row fixes was two keys silently falling back to English defaults.
 *
 * BOTH CASES ARE DRIVEN FOR REAL, which took finding the right fixtures:
 *   - `emptyTable` on `/coupon`, because `coupons` genuinely holds no rows for store 1 in the dev
 *     seed. This is the case that cannot be faked -- a filter produces `zeroRecords`, not
 *     `emptyTable`, and the two are the distinction the row is about.
 *   - `zeroRecords` on `/product` and `/order` by searching for a term that matches nothing.
 *
 * THE DANGEROUS MESSAGE IS THE ONE ASSERTED HARDEST. A merchant with nine hundred products and a
 * filter applied who is told to "add your first product" will reasonably conclude their catalogue
 * is gone. So the filtered case is checked to contain the way OUT and to contain no creation
 * language, in both languages.
 *
 * PRECONDITIONS, and it fails loudly rather than reporting a clean pass without them:
 *   - a server on 8899 serving THIS repository, launched from inside `public/`;
 *   - `database/p2dev.sqlite` seeded by `database/seed-p2dev.php`, with `coupons` empty for
 *     store 1 -- asserted as a premise rather than assumed.
 *
 * usage: node docs/development/phase-2/tools/check-empty-states-live.cjs [baseUrl]
 */

'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:8899';
const REPO = '/home/user/syriastore98';
const SHOTS = path.join(REPO, 'docs/development/phase-2/evidence/p2-06-11');
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

function php(code) {
    return execFileSync('php', ['-r',
        'require "vendor/autoload.php"; $a = require "bootstrap/app.php";'
        + ' $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ' + code],
        { cwd: REPO, env: ENV, stdio: 'pipe' }).toString().trim();
}

function setDirection(lang) {
    php('$u = App\\Models\\User::where("email","merchant@example.test")->firstOrFail();'
        + ' $u->language = "' + lang + '"; $u->save(); (new App\\Models\\User)->flushCache();'
        + ' $s = (int) $u->current_store;'
        + ' $r = App\\Models\\Setting::withoutGlobalScopes()->where("store_id",$s)'
        + '     ->where("name","SITE_RTL")->first();'
        + ' if ($r) { $r->value = "' + (lang === 'ar' ? 'on' : 'off') + '"; $r->save(); }'
        + ' (new App\\Models\\Setting)->flushCache();');
}

async function login(page) {
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', 'merchant@example.test');
    await page.fill('input[name="password"]', 'password');
    await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('button[type="submit"]')]);
    if (page.url().includes('/login')) throw new Error('login failed');
}

async function ready(page, table) {
    await page.waitForFunction((id) => !!(window.jQuery && window.jQuery.fn.dataTable
        && window.jQuery.fn.dataTable.isDataTable('#' + id)), table, { timeout: 45000 });
    await page.waitForTimeout(800);
}

/* The rendered cell, after waiting for it to actually appear. DataTables writes
   `td.dataTables_empty`, and reading before the draw settles is how a check like this reports a
   false pass. */
async function emptyCellText(page, table) {
    try {
        await page.waitForFunction((id) => {
            const td = document.querySelector('#' + id + ' td.dataTables_empty');
            return !!(td && td.textContent.trim().length);
        }, table, { timeout: 15000 });
    } catch (e) { return null; }

    return page.evaluate((id) =>
        document.querySelector('#' + id + ' td.dataTables_empty').textContent.trim(), table);
}

const CREATION_LANGUAGE = ['add your first', 'create your first', 'get started by',
    'أضف منتجك الأول', 'أنشئ'];

(async () => {
    fs.mkdirSync(SHOTS, { recursive: true });

    // PREMISE, in the database rather than in a comment: the emptyTable case needs an empty list.
    const coupons = Number(php('echo Illuminate\\Support\\Facades\\DB::table("coupons")'
        + '->where("store_id", 1)->count();'));
    check('premise: the coupon list is genuinely empty for store 1', coupons === 0, String(coupons));

    const browser = await chromium.launch();

    try {
        for (const [lang, dir] of [['en', 'ltr'], ['ar', 'rtl']]) {
            setDirection(lang);

            const ctx = await browser.newContext({ viewport: { width: 1500, height: 900 } });
            const page = await ctx.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));

            await login(page);

            const htmlDirPage = await (async () => {
                await page.goto(BASE + '/product', { waitUntil: 'domcontentloaded' });
                return (await page.getAttribute('html', 'dir')) || 'ltr';
            })();
            check(`${dir} — the page is really ${dir}`,
                (htmlDirPage === 'rtl') === (dir === 'rtl'), htmlDirPage);

            /* ---- THE REAL emptyTable: a list with nothing in it ---- */
            await page.goto(BASE + '/coupon', { waitUntil: 'domcontentloaded' });

            const couponTable = await page.evaluate(() =>
                !!document.getElementById('coupon-table'));
            check(`${dir} — premise: the coupon list rendered`, couponTable === true,
                'the merchant may lack Manage Coupon, so the emptyTable case was not driven');

            if (couponTable) {
                await ready(page, 'coupon-table');
                const text = await emptyCellText(page, 'coupon-table');

                check(`${dir} — the empty list renders a message`, !!text, String(text));
                check(`${dir} — it is not the DataTables default`,
                    !!text && !text.includes('No data available in table'), String(text));
                check(`${dir} — it says what to do next`,
                    !!text && text.length > 30, String(text));

                if (dir === 'rtl') {
                    check(`${dir} — the empty message is in Arabic`,
                        !!text && /[؀-ۿ]/.test(text), String(text));
                } else {
                    check(`${dir} — the empty message names coupons`,
                        !!text && /coupon/i.test(text), String(text));
                }

                await page.screenshot({
                    path: path.join(SHOTS, `empty-coupon-${lang}.png`), fullPage: false,
                });
            }

            /* ---- THE REAL zeroRecords: a search that matches nothing ---- */
            for (const [listPath, table, label] of [
                ['/product', 'product-table', 'products'],
                ['/order', 'order-table', 'orders'],
            ]) {
                await page.goto(BASE + listPath, { waitUntil: 'domcontentloaded' });

                const present = await page.evaluate((id) => !!document.getElementById(id), table);
                check(`${dir} ${label} — premise: the list rendered`, present === true);
                if (!present) continue;

                await ready(page, table);
                await page.evaluate((id) =>
                    window.jQuery('#' + id).DataTable().search('zzzzqqqnomatch').draw(), table);

                const text = await emptyCellText(page, table);

                check(`${dir} ${label} — a filtered-empty list renders a message`, !!text,
                    String(text));
                check(`${dir} ${label} — it is not the DataTables default`,
                    !!text && !text.includes('No matching records found'), String(text));

                /* THE DANGEROUS ONE. "Add your first product" to a merchant who has 900 and a
                   filter applied reads as "your catalogue is gone". */
                const lower = (text || '').toLowerCase();
                const offender = CREATION_LANGUAGE.find((phrase) => lower.includes(phrase.toLowerCase()));
                check(`${dir} ${label} — it does not tell them to create a record`,
                    offender === undefined, 'contains: ' + String(offender));

                check(`${dir} ${label} — it names the way out`,
                    !!text && /clear|filter|search|أزِل|تصفية|بحث/i.test(text), String(text));

                if (dir === 'rtl') {
                    check(`${dir} ${label} — the message is in Arabic`,
                        !!text && /[؀-ۿ]/.test(text), String(text));
                }

                await page.screenshot({
                    path: path.join(SHOTS, `filtered-empty-${label}-${lang}.png`), fullPage: false,
                });
            }

            check(`${dir} — no JavaScript errors`, errors.length === 0, errors.join(' | '));

            await ctx.close();
        }
    } finally {
        setDirection('en');
        await browser.close();
    }

    const shots = fs.readdirSync(SHOTS).filter((f) => f.endsWith('.png'));
    const distinct = new Set(shots.map((f) =>
        require('crypto').createHash('sha1')
            .update(fs.readFileSync(path.join(SHOTS, f))).digest('hex')));

    check(`every screenshot is distinct`, distinct.size === shots.length,
        `${shots.length} screenshots, ${distinct.size} distinct -- identical ones mean the page `
        + `did not change between captures`);

    if (failures.length) {
        console.log(`\n${failures.length} of ${pass + failures.length} cases FAILED`);
        process.exit(1);
    }
    console.log(`\n${pass} cases passed, ${shots.length} screenshots — both the empty and the filtered-empty cases are driven for real, in both languages`);
})().catch((e) => { console.error('FATAL ' + e.message); process.exit(1); });
