/**
 * PHASE 03 M7 — CAPTURE AND MEASURE THE PUBLIC PLATFORM SITE AND DOCUMENTATION CENTRE.
 * =================================================================================================
 *
 * The M5 and M6 surfaces are PUBLIC, so this harness needs none of what
 * `storefront-matrix.cjs` carries: no customer sign-in, no store slug, no theme resolution, no
 * `ThemeLanguage` cookie dance for a merchant. What it does keep is that file's discipline: a row
 * is only evidence if its PREMISES hold, because the ways a capture lies all answer HTTP 200.
 *
 * WHAT THIS MEASURES THAT A SCREENSHOT CANNOT.
 *
 *   - `data-ss-theme` and the COMPUTED background. The platform site defaults to `auto`, which
 *     follows `prefers-color-scheme`; Playwright's `colorScheme` emulation is what makes the dark
 *     column real rather than a second copy of the light one. A run that produced identical
 *     backgrounds in both columns would be a silent failure, so the pair is compared and reported.
 *   - `dir` and `lang`. A missing `dir` is a finding, not a default: `[dir="rtl"]` and `[dir="ltr"]`
 *     rules both miss when it is the empty string, which is a defect this project has already
 *     shipped once on the guest layout.
 *   - OFF-ORIGIN REQUESTS. The platform site is supposed to fetch nothing from anywhere else.
 *     `PlatformSiteAssetsAreLocalTest` reads the source; this watches the BROWSER, which is the
 *     only place a request injected at runtime would show up.
 *   - CONSOLE ERRORS, which a screenshot shows as a page that merely looks fine.
 *
 * WHAT MAKES A ROW NOT EVIDENCE (`premise` false), each because it answers 200 or looks right:
 *   - the page is Laravel's error page rather than the site;
 *   - the content area is empty — the `@yield`/`$slot` failure CLAUDE.md records renders perfect
 *     chrome, HTTP 200 and nothing inside;
 *   - `dir` disagrees with the locale that was asked for;
 *   - the expected content marker is absent.
 *
 * usage:
 *   node docs/development/phase-3/tools/platform-site-matrix.cjs \
 *        [--base http://127.0.0.1:8899] [--out <dir>] [--no-shots]
 *
 * The server must already be running. `docs/development/phase-2.5/tools/serve-dev.sh` starts one
 * on 127.0.0.1:8899 — read its header before you do, it documents three environment variables and
 * one way to destroy the fixture.
 */

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const argOf = (name, fallback) => {
    const i = args.indexOf(name);
    return i === -1 ? fallback : args[i + 1];
};

const BASE = argOf('--base', 'http://127.0.0.1:8899').replace(/\/$/, '');
const OUT = argOf('--out', 'docs/development/phase-3/evidence/m7-platform-matrix');
const SHOTS = !args.includes('--no-shots');

/* Each surface names a string that exists ONLY inside its own content section, so the chrome
   alone cannot satisfy the premise. These mirror the markers the PHP suites assert. */
const SURFACES = [
    { key: 'home',          url: '/platform',                 en: 'What you get',              ar: 'ما الذي تحصل عليه' },
    { key: 'features',      url: '/platform/features',        en: 'What the platform does',    ar: 'ما الذي تقوم به المنصّة' },
    { key: 'pricing',       url: '/platform/pricing',         en: 'Questions about a plan',    ar: 'أسئلة عن الباقات' },
    { key: 'onboarding',    url: '/platform/getting-started', en: 'Create your first store',   ar: 'أنشئ متجرك الأول' },
    { key: 'faq',           url: '/platform/faq',             en: 'Questions people ask',      ar: 'أسئلة يطرحها الناس' },
    { key: 'contact',       url: '/platform/contact',         en: 'Talk to us',                ar: 'تحدّث إلينا' },
    { key: 'legal',         url: '/platform/legal',           en: 'Terms of service',          ar: 'شروط الخدمة' },
    { key: 'docs-index',    url: '/platform/docs',            en: 'How to use Store Syria',    ar: 'كيف تستخدم ستور سوريا' },
    { key: 'docs-category', url: '/platform/docs/your-storefront', en: 'Your storefront',      ar: 'واجهة متجرك' },
    { key: 'docs-article',  url: '/platform/docs/your-storefront/edit-your-theme',
      en: 'Nothing you type is live yet', ar: 'لا شيء تكتبه يصبح مباشراً فوراً' },
    /* THE QUERY IS PER LOCALE, and that is the product behaving correctly rather than a
       concession. Search does not work ACROSS languages: the Arabic corpus contains no English
       word "theme", so `?q=theme` in Arabic correctly renders "nothing matched" and not a results
       list. The first version of this harness used one query for both columns and reported the
       Arabic rows as failures. The results page states the limitation in a line; this honours it. */
    { key: 'docs-search',   url: { en: '/platform/docs/search?q=theme', ar: '/platform/docs/search?q=%D9%82%D8%A7%D9%84%D8%A8' },
      en: 'Results for', ar: 'نتائج البحث عن' },
];

const LOCALES = ['en', 'ar'];
const SCHEMES = ['light', 'dark'];

const rgb = (s) => {
    const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(s || '');
    return m ? [Number(m[1]), Number(m[2]), Number(m[3])] : null;
};

const luminance = ([r, g, b]) => {
    const f = (c) => {
        c /= 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};

const contrast = (a, b) => {
    const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (l1 + 0.05) / (l2 + 0.05);
};

(async () => {
    fs.mkdirSync(OUT, { recursive: true });

    const browser = await chromium.launch();
    const rows = [];

    for (const scheme of SCHEMES) {
        for (const locale of LOCALES) {
            const context = await browser.newContext({
                colorScheme: scheme,
                viewport: { width: 1280, height: 900 },
                deviceScaleFactor: 2,
            });

            /* THE LOCALE IS SET BY DRIVING THE PRODUCT'S OWN SWITCH, NOT BY WRITING A COOKIE.
               ------------------------------------------------------------------------------------
               MEASURED, because the first version of this harness wrote `LANGUAGE` directly with
               `context.addCookies()` and all 22 Arabic loads came back `dir="ltr"` with the English
               text — a finding that looked like a product defect and was a harness defect.

               Laravel's `EncryptCookies` runs on the `web` group and this application excepts
               nothing, so every cookie the app reads is encrypted and signed. A raw cookie of the
               same NAME fails decryption and is DISCARDED, so `Cookie::get('LANGUAGE')` answers
               null and `SetLocate` falls through to the super admin's language — English.

               Driving `/change-languages/{lang}` is also the better test: it is the journey a
               visitor actually takes from the switch in the site footer, and it exercises
               `LanguageController::changelanguage` writing both the session and the queued cookie
               rather than asserting around them. */
            {
                const boot = await context.newPage();
                await boot.goto(`${BASE}/change-languages/${locale}`, { waitUntil: 'networkidle' });
                await boot.close();
            }

            for (const surface of SURFACES) {
                const page = await context.newPage();
                const offOrigin = [];
                const consoleErrors = [];

                page.on('request', (r) => {
                    const u = r.url();
                    if (/^https?:\/\//.test(u) && !u.startsWith(BASE)) offOrigin.push(u);
                });
                page.on('console', (m) => {
                    if (m.type() === 'error') consoleErrors.push(m.text());
                });
                page.on('pageerror', (e) => consoleErrors.push(String(e)));

                const id = `${surface.key}--${locale}--${scheme}`;
                const url = typeof surface.url === 'string' ? surface.url : surface.url[locale];
                let row = { id, surface: surface.key, locale, scheme, url };

                try {
                    const res = await page.goto(BASE + url, {
                        waitUntil: 'networkidle', timeout: 30000,
                    });

                    row.status = res ? res.status() : 0;

                    const probe = await page.evaluate(() => {
                        const html = document.documentElement;
                        const body = document.body;
                        const main = document.getElementById('ss-ps-main');
                        const cs = getComputedStyle(body);
                        const h1 = document.querySelector('h1');
                        return {
                            lang: html.getAttribute('lang'),
                            dir: html.getAttribute('dir'),
                            theme: html.getAttribute('data-ss-theme'),
                            themeSource: html.getAttribute('data-ss-theme-source'),
                            title: document.title,
                            h1: h1 ? h1.textContent.trim() : null,
                            bg: cs.backgroundColor,
                            fg: cs.color,
                            mainText: main ? main.innerText.trim().length : 0,
                            headerPresent: !!document.querySelector('.ss-ps-header'),
                            footerPresent: !!document.querySelector('.ss-ps-footer'),
                            skipLink: !!document.querySelector('.ss-ps-skip'),
                            bodyText: body.innerText,
                        };
                    });

                    const marker = surface[locale];
                    const wantDir = locale === 'ar' ? 'rtl' : 'ltr';

                    row = {
                        ...row,
                        lang: probe.lang,
                        dir: probe.dir,
                        theme: probe.theme,
                        themeSource: probe.themeSource,
                        title: probe.title,
                        h1: probe.h1,
                        bg: probe.bg,
                        fg: probe.fg,
                        bodyContrast: (() => {
                            const a = rgb(probe.fg), b = rgb(probe.bg);
                            return a && b ? Number(contrast(a, b).toFixed(2)) : null;
                        })(),
                        mainTextLength: probe.mainText,
                        offOrigin: [...new Set(offOrigin)],
                        consoleErrors,
                        markerPresent: probe.bodyText.includes(marker),
                    };

                    /* THE PREMISES. Each names a way a 200 lies. */
                    const failures = [];
                    if (row.status !== 200) failures.push(`status ${row.status}`);
                    if (!probe.headerPresent || !probe.footerPresent) failures.push('chrome missing');
                    if (probe.mainText < 200) failures.push(`content area holds ${probe.mainText} chars`);
                    if (probe.dir !== wantDir) failures.push(`dir=${probe.dir} wanted ${wantDir}`);
                    if (!row.markerPresent) failures.push(`marker absent: ${marker}`);
                    if (row.offOrigin.length) failures.push(`off-origin: ${row.offOrigin.length}`);
                    if (consoleErrors.length) failures.push(`console errors: ${consoleErrors.length}`);

                    row.premise = failures.length === 0;
                    row.premiseFailures = failures;

                    if (SHOTS) {
                        const file = path.join(OUT, `${id}.png`);
                        await page.screenshot({ path: file, fullPage: true });
                        row.screenshot = path.relative(process.cwd(), file);
                    }
                } catch (e) {
                    row.premise = false;
                    row.premiseFailures = [`threw: ${e.message}`];
                }

                rows.push(row);
                await page.close();
            }

            await context.close();
        }
    }

    await browser.close();

    /* THE LIGHT/DARK PAIR CHECK. A run where both columns render the same background has not
       measured dark mode at all -- it has photographed the light page twice. */
    const pairs = [];
    for (const surface of SURFACES) {
        for (const locale of LOCALES) {
            const light = rows.find((r) => r.surface === surface.key && r.locale === locale && r.scheme === 'light');
            const dark = rows.find((r) => r.surface === surface.key && r.locale === locale && r.scheme === 'dark');
            if (light && dark) {
                pairs.push({
                    surface: surface.key, locale,
                    lightBg: light.bg, darkBg: dark.bg,
                    differs: light.bg !== dark.bg,
                });
            }
        }
    }

    const summary = {
        base: BASE,
        loads: rows.length,
        premiseOk: rows.filter((r) => r.premise).length,
        premiseFailed: rows.filter((r) => !r.premise).length,
        offOriginRequests: rows.reduce((n, r) => n + (r.offOrigin ? r.offOrigin.length : 0), 0),
        consoleErrors: rows.reduce((n, r) => n + (r.consoleErrors ? r.consoleErrors.length : 0), 0),
        appearancePairsThatDiffer: pairs.filter((p) => p.differs).length,
        appearancePairsTotal: pairs.length,
        worstBodyContrast: rows.reduce((w, r) =>
            r.bodyContrast !== null && r.bodyContrast !== undefined && (w === null || r.bodyContrast < w)
                ? r.bodyContrast : w, null),
    };

    fs.writeFileSync(
        path.join(OUT, 'matrix.json'),
        JSON.stringify({ summary, pairs, rows }, null, 2)
    );

    console.log(JSON.stringify(summary, null, 2));

    for (const r of rows.filter((x) => !x.premise)) {
        console.log(`NOT EVIDENCE  ${r.id}  ${r.premiseFailures.join('; ')}`);
    }
    for (const p of pairs.filter((x) => !x.differs)) {
        console.log(`APPEARANCE NOT MEASURED  ${p.surface} ${p.locale}  both ${p.lightBg}`);
    }

    process.exit(summary.premiseFailed === 0 && summary.appearancePairsThatDiffer === pairs.length ? 0 : 1);
})();
