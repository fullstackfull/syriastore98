'use strict';
/* F204 -- does the confirmation actually gate a type="submit" trigger, in a real browser?
   Driven against the SHIPPED public/js/custom.js served by the preview server, and against the
   REAL markup copied verbatim from resources/views/setting/webhook_setting.blade.php:69. The
   settings screen needs fixture rows this dev database does not carry, so the page is assembled
   here -- but every moving part (jQuery, SweetAlert2, custom.js, the button markup, the DELETE
   form) is the production one, fetched over HTTP from the running app. */
const { chromium } = require('playwright');

const PAGE = `<!doctype html><html><head><meta charset="utf-8">
<script src="http://127.0.0.1:8899/js/jquery-3.6.0.min.js"></script>
<script src="http://127.0.0.1:8899/assets/js/plugins/sweetalert2.all.min.js"></script>
</head><body>
<form id="target" method="POST" action="http://127.0.0.1:8899/__submitted" class="d-inline">
  <input type="hidden" name="_method" value="DELETE">
  <button type="submit" class="ss-btn ss-btn--danger-quiet ss-btn--sm ss-btn--icon show_confirm"
          data-confirm="Are You Sure?" data-text="This action can not be undone. Do you want to continue?"
          data-text-yes="Yes" data-text-no="No">
    <i class="ti ti-trash" aria-hidden="true"></i><span>Delete</span>
  </button>
</form>
<script src="http://127.0.0.1:8899/js/custom.js"></script>
</body></html>`;

const fails = [];
function check(ok, what, detail) {
  console.log((ok ? '  ok    ' : '  FAIL  ') + what + (detail ? ' -- ' + detail : ''));
  if (!ok) fails.push(what);
}

(async () => {
  const b = await chromium.launch({ args: ['--no-sandbox'] });
  const p = await (await b.newContext()).newPage();
  p.on('pageerror', e => console.log('  (page error) ' + e.message.slice(0, 120)));

  const submits = [];
  await p.route('**/__submitted', r => { submits.push(r.request().method()); return r.fulfill({ status: 200, body: 'submitted' }); });

  await p.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
  await p.setContent(PAGE, { waitUntil: 'networkidle' });

  check(await p.evaluate(() => typeof window.jQuery === 'function'), 'jQuery loaded from the app');
  check(await p.evaluate(() => !!window.Swal), 'SweetAlert2 loaded from the app');
  check(await p.evaluate(() => !!document.querySelector('.show_confirm')), 'the real trigger markup is on the page');
  check(await p.evaluate(() => document.querySelector('.show_confirm').type === 'submit'),
    'the trigger really is type="submit", which is the whole defect');

  await p.click('.show_confirm');
  await p.waitForTimeout(1000);

  check(submits.length === 0, 'the click did NOT submit the form before the dialog was answered',
    'submits=' + submits.length);

  const dialog = await p.evaluate(() => ({
    open: !!document.querySelector('.swal2-container'),
    title: (document.querySelector('.swal2-title')?.textContent || '').trim(),
  }));
  check(dialog.open, 'the confirmation dialog is open', JSON.stringify(dialog.title));

  await p.click('.swal2-cancel');
  await p.waitForTimeout(800);
  check(submits.length === 0, 'Cancel sent nothing', 'submits=' + submits.length);

  await p.click('.show_confirm');
  await p.waitForTimeout(900);
  await p.click('.swal2-confirm');
  await p.waitForTimeout(1500);
  check(submits.length === 1, 'Confirm submitted the form exactly once', 'submits=' + JSON.stringify(submits));

  await b.close();
  console.log('\nfailures: ' + fails.length);
  process.exit(fails.length ? 2 : 0);
})().catch(e => { console.error('HARNESS FAILED', e.message); process.exit(1); });
