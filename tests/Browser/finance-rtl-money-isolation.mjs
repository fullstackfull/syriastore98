/**
 * THE /finance MONEY, IN A REAL RIGHT-TO-LEFT LAYOUT ENGINE.  P5-16, second review.
 *
 *   node tests/Browser/finance-rtl-money-isolation.mjs
 *
 * WHY THIS EXISTS. The independent review of the `/finance` ledger figures claimed two Arabic-page defects -- a negative
 * profit rendered with its sign AFTER the number ("USD 20.00-"), and a list of amounts whose numbers detached from their
 * currency codes so the pairing could read swapped -- and said, honestly, that both came from a hand-written UAX #9
 * simulation and not from a browser. A feature test can only pin the MARKUP (the presenter wraps every amount in
 * U+2066 .. U+2069, LRI .. PDI); it cannot see how an engine lays that markup out. So this loads the EXACT strings the
 * presenter produces into a `dir="rtl"` page in Chromium and MEASURES where each piece lands.
 *
 * WHAT IT ASSERTS, and what it only reports. For each case it measures the BASELINE (the strings as the first version
 * produced them) and the ISOLATED form (what the presenter produces now). It FAILS if the isolated form lays out wrongly.
 * The baseline is REPORTED, not asserted: if Chromium happens to lay the old strings out correctly, that is a finding
 * about the review's simulation, and printing it is the honest thing to do.
 *
 * It needs no server and no database: the strings are the presenter's, the engine is the browser's. The glyphs may fall
 * back to whatever Arabic font the machine has; ORDER does not depend on which.
 */
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '/opt/node-tools/node_modules/playwright/index.js')
    .then((m) => m.default || m);

const LRI = String.fromCodePoint(0x2066); // LEFT-TO-RIGHT ISOLATE (spelled as a code point: the character itself is invisible)
const PDI = String.fromCodePoint(0x2069); // POP DIRECTIONAL ISOLATE

let fails = 0;
const check = (label, ok, detail = '') => {
    console.log(`${ok ? '  ok  ' : ' FAIL '} ${label}${detail ? '  -- ' + detail : ''}`);
    if (!ok) fails++;
};

/** Left edge (px) of the first occurrence of each needle inside `#probe`'s text, measured with a Range. */
async function lefts(page, needles) {
    return page.evaluate((list) => {
        const root = document.getElementById('probe');
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const out = {};
        for (const needle of list) {
            out[needle] = null;
            walker.currentNode = root;
            let node;
            while ((node = walker.nextNode())) {
                const at = node.data.indexOf(needle);
                if (at >= 0) {
                    const range = document.createRange();
                    range.setStart(node, at);
                    range.setEnd(node, at + needle.length);
                    const r = range.getBoundingClientRect();
                    out[needle] = { left: r.left, right: r.right };
                    break;
                }
            }
        }
        return out;
    }, needles);
}

const html = (inner, tag = 'span') => `<!doctype html><meta charset="utf-8">
<body style="font:20px/1.6 'Noto Naskh Arabic','DejaVu Sans',sans-serif; margin:24px">
<div dir="rtl" style="width:900px"><${tag} id="probe" style="unicode-bidi:isolate">${inner}</${tag}></div></body>`;

const browser = await chromium.launch({ args: ['--no-sandbox'] });
const page = await browser.newPage();

// ---------------------------------------------------------------------------------------------------------------
// 1. A NEGATIVE PROFIT in the stat tile (`.ss-stat__value` is `unicode-bidi: isolate` and has NO direction of its own).
// ---------------------------------------------------------------------------------------------------------------
console.log('\n1. a negative profit in an RTL tile');
for (const [name, text] of [['baseline', '-20.00 USD'], ['isolated', `${LRI}-20.00 USD${PDI}`]]) {
    await page.setContent(html(text));
    const x = await lefts(page, ['-', '20.00', 'USD']);
    const reads = x['-'].left < x['20.00'].left && x['20.00'].left < x['USD'].left;
    const order = Object.entries(x).sort((a, b) => a[1].left - b[1].left).map(([k]) => k).join(' ');
    if (name === 'isolated') {
        check('the sign is in front of the number and the code follows: reads "-20.00 USD" left to right', reads, `visual order: ${order}`);
    } else {
        console.log(`  info  baseline (no isolate) lays out as: ${order}${reads ? '  (correct -- the review\'s simulation did not reproduce in this engine)' : '  (the defect the review described)'}`);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// 2. THE MIXED-CURRENCY LIST, inside the Arabic sentence the presenter produces.
// ---------------------------------------------------------------------------------------------------------------
console.log('\n2. a list of amounts in an Arabic sentence');
const sentence = (list) => `هذه المبالغ بأكثر من عملة واحدة ولم تُجمع: ${list}.`;
const variants = {
    baseline: sentence('1,000.00 USD, 15,000,000.00 SYP'),
    isolated: sentence(`${LRI}15,000,000.00 SYP${PDI}, ${LRI}1,000.00 USD${PDI}`), // alphabetical by code, as the presenter orders it
};
for (const [name, text] of Object.entries(variants)) {
    await page.setContent(html(text, 'p'));
    const x = await lefts(page, ['1,000.00', 'USD', '15,000,000.00', 'SYP']);
    // Each amount must be IMMEDIATELY followed, on its right, by ITS OWN code: the code starts a space's width (well under
    // 24px at this size) after the amount ends. (An earlier version of this check compared left edges, which a wide amount
    // beside a short code defeats; the gap between the amount's right edge and the code's left edge cannot be fooled so.)
    const follows = (amount, code) => {
        const d = x[code].left - x[amount].right;
        return d >= 0 && d < 24;
    };
    const pairedUSD = follows('1,000.00', 'USD');
    const pairedSYP = follows('15,000,000.00', 'SYP');
    const order = Object.entries(x).sort((a, b) => a[1].left - b[1].left).map(([k]) => k).join('  ');
    if (name === 'isolated') {
        check('1,000.00 reads with USD and 15,000,000.00 with SYP, each amount before its own code', pairedUSD && pairedSYP, `visual order: ${order}`);
    } else {
        console.log(`  info  baseline (no isolates) lays out as: ${order}  ${pairedUSD && pairedSYP ? '(pairs intact)' : '(pairing broken)'}`);
    }
}

// ---------------------------------------------------------------------------------------------------------------
// 3. A NEGATIVE AMOUNT INSIDE A SENTENCE ("Includes -20.00 USD from records that carry no currency ...").
// ---------------------------------------------------------------------------------------------------------------
console.log('\n3. a negative amount in the middle of an Arabic sentence');
for (const [name, amount] of [['baseline', '-20.00 USD'], ['isolated', `${LRI}-20.00 USD${PDI}`]]) {
    await page.setContent(html(`يشمل ${amount} من سجلات بلا عملة مسجَّلة، وتُحتسب بعملة متجرك.`, 'p'));
    const x = await lefts(page, ['-', '20.00', 'USD']);
    const reads = x['-'].left < x['20.00'].left && x['20.00'].left < x['USD'].left;
    const order = Object.entries(x).sort((a, b) => a[1].left - b[1].left).map(([k]) => k).join(' ');
    if (name === 'isolated') {
        check('the sign stays in front of the number', reads, `visual order: ${order}`);
    } else {
        console.log(`  info  baseline lays out as: ${order}${reads ? '  (correct)' : '  (the sign trails)'}`);
    }
}

await browser.close();
console.log(fails === 0 ? '\nall checks passed' : `\n${fails} check(s) FAILED`);
process.exit(fails === 0 ? 0 : 1);
