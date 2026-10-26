#!/usr/bin/env node
'use strict';
/**
 * P2-03-4 RECONNAISSANCE — the media and variant UX of the RUNNING product editor.
 *
 * NO REAL CUSTOMER DATA. The tenant is `database/seed-p2dev.php`; every value is invented.
 *
 * WHY THIS IS A SURVEY AND NOT A CHECK. Workstream D asks for an "images and media workflow"
 * and "existing variants/options support". Both surfaces depend on Dropzone and on the
 * attribute block that `layouts/app.blade.php` injects -- and until F16 was fixed on
 * 2026-10-26 Dropzone 404'd on every admin page, so NOBODY HAS SEEN EITHER OF THEM WORK. A
 * plan written from the blade source would be a plan about markup that may never have run.
 * So this measures what is actually there first.
 *
 * usage: node survey-media-variant-live.cjs [baseUrl] [--json <file>]
 */
const { chromium } = require('playwright');
const BASE = (process.argv[2] && !process.argv[2].startsWith('--')) ? process.argv[2] : 'http://127.0.0.1:8899';
const jsonIdx = process.argv.indexOf('--json');
const OUT = jsonIdx > -1 ? process.argv[jsonIdx + 1] : null;
const EMAIL = 'merchant@example.test', PASSWORD = 'password';

async function login(p) {
    await p.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name="email"]', EMAIL);
    await p.fill('input[name="password"]', PASSWORD);
    await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('button[type="submit"]')]);
}

const SURVEY_MEDIA = () => {
    const q = (s) => document.querySelector(s);
    const all = (s) => Array.from(document.querySelectorAll(s));
    const box = (el) => { if (!el) { return null; } const r = el.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top) }; };
    const vis = (el) => !!(el && (el.checkVisibility ? el.checkVisibility({ contentVisibilityAuto: true }) : el.offsetParent !== null));

    const dz = q('[data-toggle="dropzone1"]');
    return {
        dropzone: {
            present: !!dz,
            hasInstance: !!(dz && dz.dropzone),
            visible: vis(dz),
            box: box(dz),
            // what the merchant is told about the drop target
            promptText: dz ? (dz.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 160) : null,
            acceptedFiles: dz && dz.dropzone ? (dz.dropzone.options.acceptedFiles || null) : null,
            maxFilesize: dz && dz.dropzone ? (dz.dropzone.options.maxFilesize || null) : null,
            maxFiles: dz && dz.dropzone ? (dz.dropzone.options.maxFiles === undefined ? null : dz.dropzone.options.maxFiles) : null,
            addRemoveLinks: dz && dz.dropzone ? !!dz.dropzone.options.addRemoveLinks : null,
            previewTemplatePresent: !!q('.dz-preview, [data-dz-name]'),
            // is there any guidance on size/format/count anywhere near it?
            hintNearby: dz && dz.parentElement
                ? all('.form-text, .text-muted, small, .ss-field__hint')
                    .filter((e) => dz.parentElement.contains(e))
                    .map((e) => e.textContent.trim().replace(/\s+/g, ' ').slice(0, 90))
                : [],
        },
        coverImage: {
            input: !!q('#cover_image'),
            visible: vis(q('#cover_image')),
            accept: q('#cover_image') ? q('#cover_image').getAttribute('accept') : null,
            previewImg: !!q('#upcoverImg'),
            labelled: (() => { const i = q('#cover_image'); if (!i) { return null; }
                const l = document.querySelector('label[for="cover_image"]');
                return !!l || !!(i.closest('div') && i.closest('div').querySelector('label')); })(),
        },
        otherFileInputs: all('input[type="file"]').map((i) => ({
            name: i.name, id: i.id, accept: i.getAttribute('accept'), visible: vis(i),
            multiple: i.multiple,
        })),
        // existing images on the edit page: can they be reordered, removed, made primary?
        existingImages: {
            count: all('.product-image-item, .dz-preview, [data-image-id], .uploaded-image').length,
            removeControls: all('[data-dz-remove], .remove-image, .dz-remove').length,
            reorderAffordance: all('[draggable="true"], .ui-sortable, .sortable').length,
            primaryAffordance: all('[data-primary], .make-primary, input[name="primary_image"]').length,
        },
    };
};

const SURVEY_VARIANT = () => {
    const q = (s) => document.querySelector(s);
    const all = (s) => Array.from(document.querySelectorAll(s));
    const vis = (el) => !!(el && (el.checkVisibility ? el.checkVisibility({ contentVisibilityAuto: true }) : el.offsetParent !== null));
    return {
        toggle: { present: !!q('#enable_product_variant'), checked: !!(q('#enable_product_variant') || {}).checked },
        /* READ THROUGH THE CHOICES WRAPPER, not off the native select. Choices.js takes
           `#attribute_id` over, which EMPTIES the native element's options and sets
           display:none on it -- so the first version of this survey reported
           `options=0, visible=false` and read as "no attributes are seeded", when in fact
           both were present and offered to the merchant. CLAUDE.md records this behaviour;
           the survey did not honour it. */
        attributeSelect: (() => {
            const el = q('#attribute_id');
            if (!el) { return { present: false }; }
            const wrap = el.closest('.choices');
            const fromChoices = wrap
                ? Array.from(wrap.querySelectorAll('.choices__list--dropdown .choices__item, .choices__list[role="listbox"] .choices__item'))
                    .map((e) => e.textContent.trim())
                : null;
            const native = Array.from(el.options || []).map((o) => o.text.trim());
            return {
                present: true,
                managedBy: wrap ? 'choices' : (/select2-hidden-accessible/.test(el.className) ? 'select2' : null),
                // what the MERCHANT can choose from, whichever element holds it
                offered: (fromChoices && fromChoices.length) ? fromChoices : native,
                nativeOptionCount: native.length,
                // visibility of the thing the merchant actually sees
                visible: wrap ? wrap.offsetParent !== null : vis(el),
                multiple: !!el.multiple,
            };
        })(),
        containers: {
            attributeOptions: !!q('#attribute_options'),
            attributeOptionsChildren: q('#attribute_options') ? q('#attribute_options').children.length : null,
            attributeCombination: !!q('#attribute_combination'),
            attributeCombinationChildren: q('#attribute_combination') ? q('#attribute_combination').children.length : null,
        },
        injectedControls: all('[name^="attribute_options_"], [name^="choice_no"], [name^="sku_"], [name^="price_"]')
            .map((e) => ({ name: e.name, tag: e.tagName.toLowerCase(), required: e.required, visible: vis(e) })),
        combinationRows: all('#attribute_combination tr, .attribute_combination tr').length,
    };
};

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const p = await ctx.newPage();
    const errs = [], bad = [];
    p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));
    p.on('response', (r) => { if (r.status() >= 400 && /\.(js|css)(\?|$)/.test(r.url())) { bad.push(r.status() + ' ' + r.url().replace(BASE, '')); } });

    await login(p);
    const report = { base: BASE, pages: {} };

    for (const [label, url] of [['create', '/product/create'], ['edit', '/product/32/edit']]) {
        await p.goto(BASE + url, { waitUntil: 'networkidle' });
        await p.waitForTimeout(1500);

        const media = await p.evaluate(SURVEY_MEDIA);
        const variantBefore = await p.evaluate(SURVEY_VARIANT);

        /* Turn variants ON. This is the half nobody has driven: the attribute block is
           injected by layouts/app.blade.php, not by the product view, and the create view
           contains no attribute markup at all. */
        let variantAfter = null, afterAttrPick = null;
        const toggle = await p.$('#enable_product_variant');
        if (toggle) {
            await toggle.check().catch(() => {});
            await p.waitForTimeout(1200);
            variantAfter = await p.evaluate(SURVEY_VARIANT);

            /* Pick an attribute THROUGH THE WIDGET. Setting `.value` on the native select
               and dispatching `change` does not drive Choices' delegated handlers -- a
               documented bite from P2-03-3 -- so this opens the dropdown and clicks the real
               item, which is what a merchant does. */
            let picked = null;
            /* SCOPED TO THIS WIDGET'S OWN WRAPPER. The page carries six Choices instances, so
               a bare `.choices__list--dropdown .choices__item--selectable` matched whichever
               dropdown happened to be first in the document -- the Category one -- and the
               survey reported picking "Select Category" while the attribute select was never
               touched. A marker attribute is written onto the right wrapper and every
               selector below is anchored to it. */
            const marked = await p.evaluate(() => {
                const el = document.querySelector('#attribute_id');
                const wrap = el && el.closest('.choices');
                if (!wrap) { return false; }
                wrap.setAttribute('data-survey-target', 'attr');
                return true;
            });
            if (marked) {
                const inner = await p.$('[data-survey-target="attr"] .choices__inner');
                if (inner) {
                    await inner.click().catch(() => {});
                    await p.waitForTimeout(500);
                    const item = await p.$('[data-survey-target="attr"] .choices__list--dropdown .choices__item--selectable');
                    if (item) {
                        picked = (await item.textContent() || '').trim();
                        await item.click().catch(() => {});
                    }
                }
            }
            if (picked) {
                await p.waitForTimeout(1200);
                afterAttrPick = await p.evaluate(SURVEY_VARIANT);
                afterAttrPick.pickedAttribute = picked;
            }
            await toggle.uncheck().catch(() => {});
            await p.waitForTimeout(500);
        }

        report.pages[label] = { url, media, variantBefore, variantAfter, afterAttrPick };
        console.log('######## ' + label + ' ########');
        console.log('  DROPZONE  present=' + media.dropzone.present + ' instance=' + media.dropzone.hasInstance
            + ' visible=' + media.dropzone.visible + ' box=' + JSON.stringify(media.dropzone.box));
        console.log('            acceptedFiles=' + JSON.stringify(media.dropzone.acceptedFiles)
            + ' maxFilesize=' + media.dropzone.maxFilesize + ' maxFiles=' + media.dropzone.maxFiles
            + ' addRemoveLinks=' + media.dropzone.addRemoveLinks);
        console.log('            prompt=' + JSON.stringify(media.dropzone.promptText));
        console.log('            hintsNearby=' + JSON.stringify(media.dropzone.hintNearby));
        console.log('  COVER     input=' + media.coverImage.input + ' visible=' + media.coverImage.visible
            + ' accept=' + JSON.stringify(media.coverImage.accept) + ' labelled=' + media.coverImage.labelled);
        console.log('  FILES     ' + JSON.stringify(media.otherFileInputs));
        console.log('  EXISTING  ' + JSON.stringify(media.existingImages));
        console.log('  VARIANT off: attrSelect=' + variantBefore.attributeSelect.present
            + ' managedBy=' + variantBefore.attributeSelect.managedBy
            + ' visible=' + variantBefore.attributeSelect.visible
            + ' offered=' + JSON.stringify(variantBefore.attributeSelect.offered));
        if (variantAfter) {
            console.log('  VARIANT on : visible=' + variantAfter.attributeSelect.visible
                + ' offered=' + JSON.stringify(variantAfter.attributeSelect.offered)
                + ' injected=' + variantAfter.injectedControls.length);
        }
        if (afterAttrPick) {
            console.log('  AFTER PICK "' + afterAttrPick.pickedAttribute + '": injected='
                + afterAttrPick.injectedControls.length + ' optionRows='
                + afterAttrPick.containers.attributeOptionsChildren + ' combinationRows='
                + afterAttrPick.combinationRows);
            console.log('            controls=' + JSON.stringify(afterAttrPick.injectedControls.slice(0, 8)));
        }
        console.log('');
    }

    report.pageErrors = errs;
    report.failedAssets = bad;
    console.log('pageErrors=' + errs.length + (errs.length ? ' :: ' + errs.join(' | ') : ''));
    console.log('failedAssets=' + bad.length + (bad.length ? ' :: ' + bad.join(' | ') : ''));
    if (OUT) { require('fs').writeFileSync(OUT, JSON.stringify(report, null, 2)); console.log('wrote ' + OUT); }
    await browser.close();
})().catch((e) => { console.error('SURVEY ERROR: ' + e.message); process.exit(2); });
