#!/usr/bin/env node
/*
 * P2-06-4 -- the table-state sanitiser, EXECUTED against real DataTables state objects.
 * ============================================================================================
 *
 * WHY THIS EXISTS AS A SCRIPT. `sanitizeTableState` is JavaScript, so a PHP test can only match
 * its source. Matching source is how a function that does nothing passes: a body containing the
 * word "search" satisfies a grep whether or not it clears anything. This loads the real shipped
 * file and calls the function.
 *
 * It is driven by `tests/Feature/Merchant/TablePreferencesTest.php`, which asserts this script's
 * exit code, so a new case added here is a new case in the suite.
 *
 * THE STATE SHAPE IS THE REAL ONE, taken from DataTables 1.10.20's `_fnSaveState`
 * (public/vendor/datatables/datatables.min.js): `{time, start, length, order, search:{search,
 * smart, regex, caseInsensitive}, columns:[{visible, search:{...}}]}`. Asserting against an
 * invented shape would prove the function handles a shape DataTables never sends.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const repoRoot = path.resolve(__dirname, '..', '..', '..', '..');
const scriptPath = path.join(repoRoot, 'public', 'js', 'store-syria-ds.js');

/* The design-system file is a browser script: it reads `document` at load time and registers
   listeners. A minimal stand-in is enough -- nothing below drives the DOM behaviours, and
   anything it needs that is missing throws loudly rather than silently passing. */
function loadStoreSyriaDS() {
    const source = fs.readFileSync(scriptPath, 'utf8');

    const noop = () => {};
    const element = {
        addEventListener: noop,
        removeEventListener: noop,
        querySelectorAll: () => [],
        querySelector: () => null,
        closest: () => null,
        classList: { add: noop, remove: noop, contains: () => false },
        setAttribute: noop,
        removeAttribute: noop,
        getAttribute: () => null,
        focus: noop,
        style: {},
    };

    const sandbox = {
        document: {
            readyState: 'complete',
            addEventListener: noop,
            removeEventListener: noop,
            querySelectorAll: () => [],
            querySelector: () => null,
            documentElement: element,
            body: element,
            createElement: () => element,
            activeElement: element,
        },
        console,
        setTimeout,
        clearTimeout,
        fetch: () => Promise.reject(new Error('not used')),
        AbortController: function () { this.abort = noop; this.signal = {}; },
        localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    };
    // `window` IS the sandbox, so the script's `window.StoreSyriaDS = …` lands where this
    // function can read it -- and `window.addEventListener`, which the beforeunload guard binds
    // at load time, has to exist on it too.
    sandbox.addEventListener = noop;
    sandbox.removeEventListener = noop;
    sandbox.window = sandbox;
    sandbox.globalThis = sandbox;

    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: scriptPath });

    if (!sandbox.window.StoreSyriaDS || typeof sandbox.window.StoreSyriaDS.sanitizeTableState !== 'function') {
        throw new Error(
            'StoreSyriaDS.sanitizeTableState is not exported by ' + scriptPath +
            ' -- buildDataTable() calls it on every table, so this is a live defect, not a test problem'
        );
    }

    return sandbox.window.StoreSyriaDS;
}

/** A DataTables 1.10.20 state object, as `_fnSaveState` builds it. */
function state(overrides) {
    return Object.assign({
        time: 1761400000000,
        start: 70,
        length: 50,
        order: [[3, 'desc']],
        search: { search: 'refund', smart: true, regex: false, caseInsensitive: true },
        columns: [
            { visible: false, search: { search: '', smart: true, regex: false, caseInsensitive: true } },
            { visible: true, search: { search: 'ORD-99', smart: true, regex: false, caseInsensitive: true } },
        ],
    }, overrides || {});
}

const failures = [];
let cases = 0;

function check(label, condition) {
    cases += 1;
    if (!condition) {
        failures.push(label);
        console.error('  FAIL  ' + label);
    }
}

const ds = loadStoreSyriaDS();

/* ---------------------------------------------------------------- what is dropped */

let s = ds.sanitizeTableState(state());

check('the global search term is cleared', s.search.search === '');
check('the page offset returns to the first page', s.start === 0);
check('a per-column filter is cleared', s.columns[1].search.search === '');

/* ---------------------------------------------------------------- what survives */

check('rows per page is kept -- this is the preference', s.length === 50);
check('the sort column is kept', s.order[0][0] === 3);
check('the sort direction is kept', s.order[0][1] === 'desc');
check('a hidden column stays hidden', s.columns[0].visible === false);
check('a shown column stays shown', s.columns[1].visible === true);

/* The rest of the search object is left alone: DataTables reads `smart`, `regex` and
   `caseInsensitive` whether or not there is a term, and rewriting them would change how the NEXT
   search behaves -- which is not this function's business. */
check('search flags are not rewritten', s.search.smart === true && s.search.regex === false);

/* ---------------------------------------------------------------- it does not crash */

check('a state with no search object is tolerated',
    ds.sanitizeTableState({ start: 5, length: 25 }).start === 0);

check('a state with no columns array is tolerated',
    ds.sanitizeTableState({ start: 5, search: { search: 'x' } }).search.search === '');

check('a columns value that is not an array is tolerated',
    ds.sanitizeTableState({ start: 5, columns: 'not-an-array' }).start === 0);

check('a null column entry is tolerated',
    ds.sanitizeTableState({ start: 1, columns: [null, undefined] }).start === 0);

check('null is returned unchanged rather than throwing', ds.sanitizeTableState(null) === null);
check('a non-object is returned unchanged', ds.sanitizeTableState('nonsense') === 'nonsense');

/* ---------------------------------------------------------------- it mutates in place

   `buildDataTable()`'s hook calls this for its SIDE EFFECT and discards the return value, which
   is how DataTables' own `stateSaveParams` contract works. A function that returned a cleaned
   copy and left the original alone would pass every assertion above and change nothing on the
   page. */

const original = state();
ds.sanitizeTableState(original);
check('the caller\'s own object is the one that was cleaned', original.search.search === '');

/* ---------------------------------------------------------------- report */

if (failures.length > 0) {
    console.error('\n' + failures.length + ' of ' + cases + ' cases FAILED');
    process.exit(1);
}

console.log(cases + ' cases passed -- the table-state sanitiser keeps the preferences and drops the search');
