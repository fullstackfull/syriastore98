/**
 * SCREENSHOT EACH DESIGN-SYSTEM SPECIMEN ON ITS OWN.
 * =================================================================================================
 *
 * `render-ds-gallery.php` writes one HTML page per combination holding every `x-ss.*` specimen.
 * This takes a separate picture of EACH specimen, because the coverage inventory carries each
 * component as its own surface and one picture of a whole page is evidence for the page.
 *
 * It screenshots the element, not the viewport, so a component's capture is its own bounding box
 * at its own height — nothing is cropped by a fixed frame and nothing is padded by empty page.
 *
 * `deviceScaleFactor: 2` because these are read by a person judging spacing, weight and colour.
 *
 * usage:
 *   node tests/Support/DesignSystem/bin/shoot-ds-gallery.cjs <dir-holding-the-html>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const OUT = process.argv[2];

if (!OUT || !fs.existsSync(OUT)) {
  console.error('usage: shoot-ds-gallery.cjs <dir>   (the dir render-ds-gallery.php wrote into)');
  process.exit(1);
}

function slug(name) {
  return name
    .replace(/^x-ss\./, '')
    .replace(/[^a-z0-9]+/gi, '-')
    .replace(/^-+/, '')
    .replace(/-+$/, '')
    .toLowerCase();
}

(async () => {
  const pages = fs.readdirSync(OUT).filter((n) => n.endsWith('.html'));

  if (pages.length === 0) {
    console.error('no gallery HTML in ' + OUT + ' — run render-ds-gallery.php first');
    process.exit(1);
  }

  const browser = await chromium.launch();
  let wrote = 0;

  for (const file of pages) {
    // ds-gallery_en_ltr_light.html -> en_ltr_light
    const combo = file.replace('ds-gallery_', '').replace('.html', '');

    const page = await browser.newPage({
      viewport: { width: 760, height: 900 },
      deviceScaleFactor: 2,
    });

    await page.goto('file://' + path.join(OUT, file));
    await page.waitForLoadState('networkidle');

    const items = await page.$$('.ss-gallery__item');

    if (items.length === 0) {
      console.error('no specimens found in ' + file);
      process.exit(1);
    }

    for (const item of items) {
      const name = await item.$eval('.ss-gallery__name', (e) => e.textContent.trim());
      await item.screenshot({ path: path.join(OUT, 'ds-' + slug(name) + '_' + combo + '.png') });
      wrote++;
    }

    await page.close();
  }

  await browser.close();
  console.log('wrote ' + wrote + ' component captures from ' + pages.length + ' combinations');
})();
