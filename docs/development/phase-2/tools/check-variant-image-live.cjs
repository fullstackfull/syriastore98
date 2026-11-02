/**
 * D-26 — the one image a product variant may carry, driven on the real edit page.
 *
 * WHY A BROWSER. The control is wired by a DELEGATED handler on the parent page, deliberately,
 * because both combination partials are injected with jQuery's `.html()` — which EVALUATES their
 * <script> tags, so a handler declared inside a partial re-binds on every injection and one click
 * then fires it N times. Nothing short of driving the real page proves the binding is single, that
 * the upload reaches the right variant, and that one variant's preview does not move another's —
 * which is the F164 class of defect this surface could easily have repeated.
 *
 * WHAT IT MEASURES, per theme/direction:
 *   - how many variant-image controls render, and whether each carries its own variant id;
 *   - that uploading to ONE variant changes only that variant's preview;
 *   - that the server stored exactly one row for it, bound to that variant;
 *   - that removing it clears only that preview;
 *   - console errors, in both directions.
 *
 * usage: node docs/development/phase-2/tools/check-variant-image-live.cjs
 */
'use strict';
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const REPO = '/home/user/syriastore98';
const ENV = Object.assign({}, process.env, {
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: path.join(REPO, 'database', 'p2dev.sqlite'),
  CACHE_STORE: 'file',
  SESSION_DRIVER: 'file',
});

const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

const rowCount = () => {
  const out = execFileSync('php', ['-r',
    '$d=new PDO("sqlite:' + path.join(REPO, 'database', 'p2dev.sqlite') + '");' +
    'echo json_encode($d->query("select variant_id, count(*) c from product_images where variant_id is not null group by variant_id")->fetchAll(PDO::FETCH_ASSOC));'
  ], { cwd: REPO, env: ENV }).toString();
  return JSON.parse(out);
};

(async () => {
  const tmp = path.join('/tmp', 'ss-variant-swatch.png');
  fs.writeFileSync(tmp, PNG);

  for (const [theme, dir, lang] of [['light', 'ltr', 'en'], ['dark', 'rtl', 'ar']]) {
    execFileSync('php', [path.join(REPO, 'docs/development/phase-2/tools/set-admin-preview-state.php'),
      '1', theme, dir, lang, 'merchant@example.test'], { cwd: REPO, env: ENV, stdio: 'pipe' });

    const b = await chromium.launch({ args: ['--no-sandbox'] });
    const c = await b.newContext({ viewport: { width: 1500, height: 950 } });
    await c.route('**/merchant-notifications/feed*', r => r.abort());
    const p = await c.newPage();
    const errs = [];
    p.on('pageerror', e => errs.push(String(e).slice(0, 140)));

    await p.goto('http://127.0.0.1:8899/login', { waitUntil: 'domcontentloaded' });
    await p.fill('input[name=email]', 'merchant@example.test');
    await p.fill('input[name=password]', 'password');
    await Promise.all([p.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}),
      p.click('button[type=submit]')]);
    await p.goto('http://127.0.0.1:8899/product/1/edit', { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(2600);

    const controls = await p.evaluate(() => {
      const rows = Array.from(document.querySelectorAll('.variant-image-row'));
      const ids = rows.map(r => String(r.getAttribute('data-variant-id') || ''));
      return {
        count: rows.length,
        ids,
        allDistinct: new Set(ids).size === ids.length && !ids.includes(''),
        labelsOwnTheirInput: rows.every(r => {
          const lab = r.querySelector('label[for]');
          const inp = r.querySelector('input[type=file]');
          const target = lab ? document.getElementById(lab.getAttribute('for')) : null;
          return !!(target && inp && target === inp);
        }),
      };
    });
    console.log(`\n[${theme}/${dir}/${lang}] controls:`, JSON.stringify(controls));

    if (controls.count < 2) { console.log('  (need at least two variants to test isolation)'); await b.close(); continue; }

    const targetId = controls.ids[1];     // deliberately NOT the first
    const otherId = controls.ids[0];

    /* OPEN THE PANEL FIRST. Every variant sits in a COLLAPSED accordion, so its controls are in
       the DOM but not visible -- a click on the remove button times out with "element is not
       visible", which is what the first run of this probe did. setInputFiles does not need
       visibility; clicking does. */
    await p.evaluate(id => {
      const row = document.querySelector(`.variant-image-row[data-variant-id="${id}"]`);
      const panel = row.closest('.accordion-collapse');
      if (panel) { panel.classList.add('show'); }
    }, targetId);

    /* HAS AN <img>, not innerHTML length. The length comparison cannot tell a server-rendered
       preview from a JS-built one -- a second run against a variant that already had an image
       measured 443 -> 244 and reported "no preview" because the JS markup is shorter. */
    const hasImg = ids => p.evaluate(list => list.map(id =>
      !!document.querySelector(`.variant-image-row[data-variant-id="${id}"] .ss-variant-image-preview img`)
    ), ids);

    const before = await hasImg([otherId, targetId]);

    await p.setInputFiles(`.variant-image-row[data-variant-id="${targetId}"] input[type=file]`, tmp);
    await p.waitForTimeout(1800);

    const after = await hasImg([otherId, targetId]);
    const feedback = await p.evaluate(ids => ids.map(id =>
      document.querySelector(`.variant-image-row[data-variant-id="${id}"] .ss-variant-image-feedback`).textContent.trim()
    ), [otherId, targetId]);

    console.log(`  uploaded to variant ${targetId}:`);
    console.log('   has <img>  other/target before:', JSON.stringify(before), ' after:', JSON.stringify(after));
    console.log('   feedback   other/target       :', JSON.stringify(feedback));
    console.log('   OTHER variant untouched:', before[0] === after[0]);
    console.log('   TARGET got a preview   :', after[1] === true && before[1] === false);
    console.log('   rows in db (variant_id => count):', JSON.stringify(rowCount()));

    await p.click(`.variant-image-row[data-variant-id="${targetId}"] .ss-variant-image-remove`);
    await p.waitForTimeout(1500);

    const removed = await hasImg([otherId, targetId]);

    console.log('   after remove, has <img> other/target:', JSON.stringify(removed));
    console.log('   rows in db after remove            :', JSON.stringify(rowCount()));
    console.log('   page errors:', errs.length ? JSON.stringify(errs.slice(0, 3)) : 'none');

    await b.close();
  }

  /*
   * CLEAN UP AFTER ITSELF. This probe performs REAL uploads against the dev fixture, so it writes
   * real files into the repository's `uploads/<store_id>/` and real rows into `product_images`.
   * An earlier run left seven PNGs and a stray `uploads/pro_image/` directory sitting in
   * `git status` — and a probe whose artifacts can be committed by accident is a liability, not a
   * tool. The rows are removed through the same column the handler writes, and the files by the
   * names this run created.
   */
  execFileSync('php', ['-r',
    '$d=new PDO("sqlite:' + path.join(REPO, 'database', 'p2dev.sqlite') + '");' +
    '$d->exec("delete from product_images where variant_id is not null");'
  ], { cwd: REPO, env: ENV });

  for (const dir of fs.readdirSync(path.join(REPO, 'uploads'), { withFileTypes: true })) {
    if (! dir.isDirectory()) { continue; }
    const full = path.join(REPO, 'uploads', dir.name);
    for (const f of fs.readdirSync(full)) {
      if (/_swatch\.png$/.test(f)) { fs.unlinkSync(path.join(full, f)); }
    }
  }

  fs.unlinkSync(tmp);
  console.log('\ncleaned up: variant image rows and uploaded swatches removed');
})();
