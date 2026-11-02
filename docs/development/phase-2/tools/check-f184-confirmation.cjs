/**
 * F184 — the variant-deletion confirmation, driven on the real editor.
 *
 * It fails loudly rather than reporting a clean pass: every step asserts a floor, because this
 * project's harness history is of probes that measured nothing and read as green.
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const REPO = '/home/user/syriastore98';
/* This file's own directory: the fixture reset script lives beside it, so the harness is
   runnable from a fresh clone with `node docs/development/phase-2/tools/check-f184-confirmation.cjs`
   and nothing outside the repository. */
const OUT = __dirname;
/* Screenshots and the machine-readable result are EVIDENCE, so they are written beside the run log
   under evidence/, not into the tools directory next to the script. */
const ART = path.join(__dirname, '..', 'evidence', 'f184');
const ENV = Object.assign({}, process.env, { DB_CONNECTION:'sqlite', DB_DATABASE: path.join(REPO,'database','p2dev.sqlite'), CACHE_STORE:'file', SESSION_DRIVER:'file' });

const CONFIGS = [
  { lang:'en', dir:'ltr', theme:'light', vp:{width:1500,height:950}, tag:'desktop' },
  { lang:'ar', dir:'rtl', theme:'dark',  vp:{width:1500,height:950}, tag:'desktop' },
  { lang:'en', dir:'ltr', theme:'dark',  vp:{width:390,height:844},  tag:'phone'   },
  { lang:'ar', dir:'rtl', theme:'light', vp:{width:390,height:844},  tag:'phone'   },
  /* THE TRAP CONFIGURATION. Direction is driven by the per-store SITE_RTL setting, NOT by the
     locale (resources/views/layouts/app.blade.php:68), so an Arabic store with RTL switched off
     renders LTR and `[dir="rtl"]` never matches. The dialog's one explicit RTL rule is the
     centring transform flip (store-syria-ds.css:1355); everything else is logical properties.
     This config is the only one that proves Arabic text in an LTR document still reads. */
  { lang:'ar', dir:'ltr', theme:'light', vp:{width:1500,height:950}, tag:'desktop' },
];

/* A product with variants and NO order history. The fixture has exactly one variant-bearing
   product (id 1) and all seven of its orders name it, so the permitted path needs one of our own.
   Created and reset by reset-f184-free-product.php, which is idempotent. */
const FREE_PRODUCT = 9184;

/* Close whatever dialog is open, through the shipped API, so a block can leave the page clean. */
async function StoreSyriaDS_closeDialogs(page) {
  await page.evaluate(() => {
    if (window.StoreSyriaDS && typeof StoreSyriaDS.closeDialog === 'function') {
      (StoreSyriaDS.openDialogIds ? StoreSyriaDS.openDialogIds() : ['ss-variant-delete-dialog'])
        .forEach(id => StoreSyriaDS.closeDialog(id, false));
    }
  });
  await page.waitForTimeout(250);
}

const fails = [];
function check(cond, what, detail) {
  if (cond) { console.log('  ok    ' + what); return true; }
  fails.push(what + (detail ? ' -- ' + detail : ''));
  console.log('  FAIL  ' + what + (detail ? ' -- ' + detail : ''));
  return false;
}

(async () => {
  execFileSync('php', [path.join(OUT, 'reset-f184-free-product.php')], { cwd: REPO, env: ENV, stdio: 'inherit' });

  for (const cfg of CONFIGS) {
    const key = `${cfg.lang}-${cfg.dir}-${cfg.theme}-${cfg.tag}`;
    console.log(`\n== ${key} ==`);
    execFileSync('php', [path.join(REPO,'docs/development/phase-2/tools/set-admin-preview-state.php'),
      '1', cfg.theme, cfg.dir, cfg.lang, 'merchant@example.test'], { cwd: REPO, env: ENV, stdio: 'pipe' });

    const b = await chromium.launch({ args: ['--no-sandbox'] });
    const c = await b.newContext({ viewport: cfg.vp });
    await c.route('**/merchant-notifications/feed*', r => r.abort());
    const p = await c.newPage();
    const deletes = [];
    p.on('request', r => { if (r.method() === 'DELETE') deletes.push({ url: r.url(), post: r.postData() }); });

    await p.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name=email]', 'merchant@example.test');
    await p.fill('input[name=password]', 'password');
    await Promise.all([p.waitForNavigation({ waitUntil:'domcontentloaded' }).catch(()=>{}), p.click('button[type=submit]')]);
    await p.goto('http://127.0.0.1:8899/product/1/edit', { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(3000);

    const pre = await p.evaluate(() => ({
      trash: document.querySelectorAll('.delete-option-comment').length,
      shell: !!document.getElementById('ss-variant-delete-dialog'),
      bridge: typeof window.ssConfirmVariantDelete,
      ds: !!(window.StoreSyriaDS && window.StoreSyriaDS.confirmDestructive),
      flag: document.getElementById('ss_confirm_variant_removal')?.value,
      dataVariant: document.querySelector('.delete-option-comment')?.getAttribute('data-variant'),
      cards: document.querySelectorAll('.accordion-item.card.media').length,
    }));
    check(pre.trash >= 8, 'the variant panel rendered its trash buttons', 'trash=' + pre.trash);
    check(pre.shell, 'the dialog shell is on the page');
    check(pre.bridge === 'function', 'the confirmation bridge is defined', 'typeof=' + pre.bridge);
    check(pre.ds, 'StoreSyriaDS.confirmDestructive is exported');
    check(pre.flag === '0', 'the consent flag is 0 at rest', 'flag=' + pre.flag);
    check(!!pre.dataVariant, 'the trash button carries data-variant', 'got=' + pre.dataVariant);

    // ---- the click must NOT delete; it must open the dialog --------------------------------
    await p.click('.delete-option-comment');
    await p.waitForTimeout(600);

    const open = await p.evaluate(() => {
      const d = document.getElementById('ss-variant-delete-dialog');
      const bd = document.querySelector('[data-ss-dialog-backdrop="ss-variant-delete-dialog"]');
      const confirm = d.querySelector('[data-ss-dialog-confirm]');
      const cancel = d.querySelector('[data-ss-dialog-cancel]');
      const cs = getComputedStyle(d);
      const note = d.querySelector('.ss-dialog__note');
      const li = d.querySelector('.ss-dialog__list li');
      return {
        visible: !d.hidden && cs.display !== 'none',
        backdropVisible: bd ? !bd.hidden : false,
        title: d.querySelector('[data-ss-dialog-title]')?.textContent || '',
        list: li ? li.textContent : '',
        note: note ? note.textContent : '',
        confirmText: confirm?.textContent?.trim(),
        cancelText: cancel?.textContent?.trim(),
        confirmHidden: confirm?.hidden,
        role: d.getAttribute('role'),
        ariaModal: d.getAttribute('aria-modal'),
        focusIsCancel: document.activeElement === cancel,
        accordionOpened: !!document.querySelector('#collapseone_S-Natural_0.show')
          || !!document.querySelector('#collapseone_S-Natural_0.collapsing')
          || document.querySelector('.accordion-button')?.getAttribute('aria-expanded') === 'true',
        rect: d.getBoundingClientRect(),
        noteColor: note ? getComputedStyle(note).color : null,
        bodyBg: getComputedStyle(d).backgroundColor,
      };
    });

    check(open.visible && open.backdropVisible, 'the click OPENED the dialog instead of deleting');
    check(deletes.length === 0, 'no DELETE request was sent by the click', 'requests=' + deletes.length);
    check(open.title.length > 0, 'the dialog has a title', JSON.stringify(open.title));
    check(open.list.includes('S-Natural'), 'the dialog NAMES the variant', JSON.stringify(open.list));

    /* AND NAMES IT CLEANLY -- `includes()` is not enough, and this check exists because
       `includes()` let a real defect through. The name line was built with a `@@v@@` marker, and
       Blade's `@@` is the escape for a literal `@`: it rewrote the `.split()` argument to `'@v@@'`
       while leaving the copy inside `__()` alone, so every line rendered as `Variant: @S-Natural`.
       Both the name check and the `$`-safety check used `includes(name)` and sailed past it; it
       was caught by looking at the screenshot. The fixture's name has no `@` and no underscore
       run, so neither may appear in the line, and the name must end it. */
    check(/^[^@]*S-Natural$/.test(open.list.trim()) && !/__SS/.test(open.list),
      'the variant line carries no leftover marker or stray character',
      JSON.stringify(open.list));
    check(open.note.length > 0, 'the dialog states the loss is permanent', JSON.stringify(open.note));
    check(!!open.confirmText && !!open.cancelText, 'it has a Confirm and a Cancel', JSON.stringify([open.confirmText, open.cancelText]));
    check(open.confirmHidden === false, 'Confirm is offered for a permitted delete');
    check(open.role === 'dialog' && open.ariaModal === 'true', 'role=dialog and aria-modal=true');

    /* THE NAME THE MERCHANT APPROVES MUST BE THE VARIANT'S NAME, VERBATIM.
       The name line was built with `String.replace()`, where the variant name is the REPLACEMENT
       argument and `$$`, `$&`, `` $` `` and `$'` are substitution patterns -- so `A$&B` displayed
       as `A@@v@@B` and ``A$`B`` as `AVariant: B`. The right row was still deleted (the echo is the
       untouched `data-variant`), but the merchant approved a name that was not the variant's,
       which is the one guarantee the name echo exists to give. Driven through the SHIPPED
       function, not a copy of it. */
    const dollarNames = ['A$&B', 'A$$B', 'A$`B', "A$'B", 'A$1B'];
    const dollarShown = await p.evaluate(async (names) => {
      const out = [];
      for (const v of names) {
        window.ssConfirmVariantDelete({ variant: v }, function () {});
        const list = document.querySelector('#' + window.SS_VARIANT_DIALOG + ' [data-ss-dialog-body] .ss-dialog__list');
        out.push((list ? list.innerText : '').trim());
        StoreSyriaDS.closeDialog(window.SS_VARIANT_DIALOG, false);
      }
      return out;
    }, dollarNames);

    const dollarOk = dollarNames.every((v, i) => dollarShown[i].includes(v));
    check(dollarOk, 'a variant name containing $ is displayed verbatim',
      JSON.stringify(dollarNames.map((v, i) => [v, dollarShown[i]]).filter((x, i) => !dollarShown[i].includes(dollarNames[i]))));

    /* Re-open the real dialog the rest of this block expects. */
    await p.click('.delete-option-comment');
    await p.waitForTimeout(400);

    /* THE SCREEN THAT DOES NOT INCLUDE THE BRIDGE AT PAGE LOAD -- which is the vendored
       AuctionProduct module's edit screen. It calls the same first-party routes, so it renders
       these same two partials, but it has no `@include` of the bridge and `packages/workdo/` may
       not be edited. The first version of this change `@push`ed the bridge from the two
       first-party views only, so every trash button on that screen started answering "This
       variant could not be deleted" and sending nothing: a capability that worked before was
       gone, and the message blamed the variant. Simulated faithfully here with first-party code
       only -- tear the bridge and the shell out of the page, re-inject the panel through the
       same route, and the panel must bring both back with it. */
    await StoreSyriaDS_closeDialogs(p);
    const workdo = await p.evaluate(async () => {
      delete window.ssConfirmVariantDelete;
      const shell = document.getElementById('ss-variant-delete-dialog');
      if (shell) { shell.remove(); }
      const bd = document.querySelector('[data-ss-dialog-backdrop="ss-variant-delete-dialog"]');
      if (bd) { bd.remove(); }
      document.querySelectorAll('[data-ss-dialog-template]').forEach(t => t.remove());

      const before = {
        bridge: typeof window.ssConfirmVariantDelete,
        shell: !!document.getElementById('ss-variant-delete-dialog'),
      };

      /* The same request the module's own screen makes. */
      const html = await $.ajax({
        url: '/products/attribute_combination_data/edit',   // products.attribute_combination_data
        type: 'POST',
        data: $('#choice_form').serialize(),
      });
      $('.attribute_combination').html(html);
      await new Promise(r => setTimeout(r, 600));

      return {
        before,
        after: {
          bridge: typeof window.ssConfirmVariantDelete,
          shell: !!document.getElementById('ss-variant-delete-dialog'),
          trash: document.querySelectorAll('.delete-option-comment').length,
        },
      };
    });

    check(workdo.before.bridge === 'undefined' && workdo.before.shell === false,
      'the bridgeless-screen simulation really started without the bridge',
      JSON.stringify(workdo.before));
    check(workdo.after.bridge === 'function' && workdo.after.shell === true && workdo.after.trash > 0,
      'a screen with no page-load include gets the bridge and the shell from the panel itself',
      JSON.stringify(workdo.after));

    const workdoDeletes = [];
    const workdoListener = r => { if (r.method() === 'DELETE') { workdoDeletes.push(r.url()); } };
    p.on('request', workdoListener);
    await p.click('.delete-option-comment');
    await p.waitForTimeout(600);
    const workdoOpen = await p.evaluate(() => {
      const d = document.getElementById('ss-variant-delete-dialog');
      return { visible: !!d && !d.hidden, list: d ? d.querySelector('.ss-dialog__list')?.innerText || '' : '' };
    });
    p.off('request', workdoListener);

    check(workdoOpen.visible, 'and the trash click on that screen opens the dialog rather than deleting');
    check(workdoDeletes.length === 0, 'no DELETE was sent from the bridgeless screen', 'requests=' + workdoDeletes.length);
    check(workdoOpen.list.length > 0, 'the dialog on that screen still names the variant', JSON.stringify(workdoOpen.list));

    await StoreSyriaDS_closeDialogs(p);

    /* Back to the page the rest of this block expects. */
    await p.goto('http://127.0.0.1:8899/product/1/edit', { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(2500);
    await p.click('.delete-option-comment');
    await p.waitForTimeout(400);
    check(open.focusIsCancel, 'focus lands on Cancel, not on the destroying button');
    check(!open.accordionOpened, 'the accordion did NOT toggle behind the dialog (stopPropagation)');
    check(open.rect.left >= -1 && open.rect.right <= cfg.vp.width + 1,
      'the dialog is inside the viewport', JSON.stringify({left:Math.round(open.rect.left), right:Math.round(open.rect.right), vw:cfg.vp.width}));

    // Arabic must actually be Arabic
    const arabic = /[؀-ۿ]/;
    if (cfg.lang === 'ar') {
      check(arabic.test(open.title), 'the title is in Arabic', JSON.stringify(open.title));
      check(arabic.test(open.note), 'the note is in Arabic', JSON.stringify(open.note));
      check(arabic.test(open.confirmText), 'the Confirm button is in Arabic', JSON.stringify(open.confirmText));
    } else {
      check(!arabic.test(open.title), 'the title is in English', JSON.stringify(open.title));
    }

    await p.screenshot({ path: path.join(ART, `f184-dialog-${key}.png`) });

    // ---- contrast of the note against the dialog surface ------------------------------------
    const contrast = await p.evaluate(() => {
      function lum(c) {
        const m = c.match(/\d+(\.\d+)?/g).map(Number);
        const f = m.slice(0,3).map(v => { v /= 255; return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4); });
        return 0.2126*f[0] + 0.7152*f[1] + 0.0722*f[2];
      }
      const d = document.getElementById('ss-variant-delete-dialog');
      const bg = getComputedStyle(d).backgroundColor;
      const out = {};
      for (const sel of ['.ss-dialog__title', '.ss-dialog__note', '.ss-dialog__list li', '[data-ss-dialog-confirm]', '[data-ss-dialog-cancel]']) {
        const el = d.querySelector(sel);
        if (!el) { continue; }
        const fg = getComputedStyle(el).color;
        const b = sel.includes('confirm') ? getComputedStyle(el).backgroundColor : bg;
        const l1 = lum(fg), l2 = lum(b);
        out[sel] = { ratio: +(((Math.max(l1,l2)+0.05)/(Math.min(l1,l2)+0.05)).toFixed(2)), fg, bg: b, text: el.textContent.trim().slice(0,40) };
      }
      return out;
    });
    for (const sel of Object.keys(contrast)) {
      const r = contrast[sel];
      check(r.ratio >= 4.5, `contrast >= 4.5:1 for ${sel}`, `${r.ratio}:1  fg=${r.fg} bg=${r.bg} "${r.text}"`);
    }

    // ---- Escape is Cancel ------------------------------------------------------------------
    await p.keyboard.press('Escape');
    await p.waitForTimeout(400);
    const afterEsc = await p.evaluate(() => ({
      hidden: document.getElementById('ss-variant-delete-dialog').hidden,
      cards: document.querySelectorAll('.accordion-item.card.media').length,
    }));
    check(afterEsc.hidden, 'Escape closed the dialog');
    check(deletes.length === 0, 'Escape sent no DELETE (Escape is Cancel)', 'requests=' + deletes.length);
    check(afterEsc.cards === pre.cards, 'no card disappeared from the DOM', `${afterEsc.cards} vs ${pre.cards}`);

    // ---- Cancel is Cancel ------------------------------------------------------------------
    await p.click('.delete-option-comment');
    await p.waitForTimeout(500);
    await p.click('[data-ss-dialog-cancel]');
    await p.waitForTimeout(400);
    check(deletes.length === 0, 'Cancel sent no DELETE', 'requests=' + deletes.length);

    /* ---- Confirm sends the consent echo -- AND THE SERVER STILL REFUSES, because every one of
            the seven orders in this fixture names product 1, so the D-20 unverifiable-order-history
            block applies. That is the owner's condition driven end to end in a browser: the
            merchant confirmed, and confirmation did not make an unsafe operation safe.
            MY FIRST VERSION OF THIS ASSERTED THE OPPOSITE and reported four failures -- it expected
            a card to vanish. The code was right and the assertion was wrong, which is exactly the
            kind of thing a browser run is for. ------------------------------------------------- */
    await p.click('.delete-option-comment');
    await p.waitForTimeout(500);
    await p.click('[data-ss-dialog-confirm]');
    await p.waitForTimeout(2500);

    check(deletes.length === 1, 'Confirm sent exactly ONE DELETE', 'requests=' + deletes.length);
    if (deletes.length) {
      const post = deletes[0].post || '';
      check(/ss_confirm_variant=/.test(post), 'the DELETE carries the consent echo', post.slice(0, 140));
      check(decodeURIComponent(post).includes(pre.dataVariant), 'the echo is the variant name the dialog showed', post.slice(0, 140));
    }

    const blockedState = await p.evaluate(() => ({
      cards: document.querySelectorAll('.accordion-item.card.media').length,
      /* This app does NOT use toastr. `show_toastr()` (public/js/custom.js:275) renders
         through the `notifier` plugin into `.notifier-container`, and also mirrors the same
         string into the DS's assertive live region. Read both -- language-agnostic, and the
         live region is the one that proves the message reaches a non-visual merchant too. */
      toast: (document.querySelector('.notifier-container')?.innerText || '').replace(/\s+/g, ' ').trim(),
      announced: (document.getElementById('ss-live-region-assertive')?.textContent || '').trim(),
    }));
    check(blockedState.cards === pre.cards,
      'a CONFIRMED delete of an order-blocked variant left every card in the DOM',
      blockedState.cards + ' vs ' + pre.cards);
    check(blockedState.toast.length > 0, 'the hard-block refusal reached the merchant as a visible message',
      JSON.stringify(blockedState.toast.slice(0, 100)));
    check(blockedState.announced.length > 0, 'the same refusal was announced to assistive technology',
      JSON.stringify(blockedState.announced.slice(0, 100)));
    if (cfg.lang === 'ar' && blockedState.toast.length) {
      check(/[؀-ۿ]/.test(blockedState.toast), 'the refusal message is in Arabic',
        JSON.stringify(blockedState.toast.slice(0, 100)));
    }

    /* ---- AND THE PERMITTED PATH, on a product with no order history. Without this the whole run
            could be satisfied by a server that refuses everything. ------------------------------ */
    await p.goto('http://127.0.0.1:8899/product/' + FREE_PRODUCT + '/edit', { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(3000);
    const freeBefore = await p.evaluate(() => document.querySelectorAll('.accordion-item.card.media').length);
    check(freeBefore >= 2, 'the order-free product rendered its variants', 'cards=' + freeBefore);

    const sentBefore = deletes.length;
    await p.click('.delete-option-comment');
    await p.waitForTimeout(600);
    const freeDialogOpen = await p.evaluate(() => !document.getElementById('ss-variant-delete-dialog').hidden);
    check(freeDialogOpen, 'the order-free product also confirms before deleting');
    await p.click('[data-ss-dialog-confirm]');
    await p.waitForTimeout(2500);

    const freeAfter = await p.evaluate(() => document.querySelectorAll('.accordion-item.card.media').length);
    check(deletes.length === sentBefore + 1, 'exactly one DELETE for the permitted path', 'sent=' + (deletes.length - sentBefore));
    check(freeAfter === freeBefore - 1,
      'a CONFIRMED delete of an unreferenced variant removed exactly one card', freeAfter + ' vs ' + freeBefore);

    await b.close();

    // restore the order-free fixture for the next configuration
    execFileSync('php', [path.join(OUT, 'reset-f184-free-product.php')], { cwd: REPO, env: ENV, stdio: 'pipe' });
  }

  fs.writeFileSync(path.join(ART,'f184-live.json'), JSON.stringify({ fails }, null, 2));
  console.log(`\n${'='.repeat(70)}\nfailures: ${fails.length}`);
  fails.forEach(f => console.log('  - ' + f));
  process.exit(fails.length ? 1 : 0);
})().catch(e => { console.error('HARNESS FAILED:', e.message); process.exit(2); });
