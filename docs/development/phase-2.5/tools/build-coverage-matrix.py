#!/usr/bin/env python3
"""
BUILD THE PHASE 2.5 CUMULATIVE COVERAGE MATRIX, from the inventory rather than from memory.

The owner's directive requires a per-surface classification in FOUR states -- PREMIUM VERIFIED,
REDESIGNED AND VERIFIED, BLOCKED (with reason and remediation), OUT OF SCOPE -- maintained
cumulatively across waves, and says plainly: "A screen must not silently disappear from the
inventory" and "Do not claim 295/295 complete without evidence for every inventoried surface."

SO THE MATRIX IS GENERATED, NOT WRITTEN. It parses PHASE-2.5-SURFACE-INVENTORY.md and refuses to
emit anything if the row count moves without the expected total being updated to match. A
hand-maintained matrix is exactly how a screen disappears quietly.

A FIFTH STATE EXISTS AND IS DELIBERATE. `PENDING` is not one of the directive's four, because the
four describe a FINISHED surface. A cumulative matrix has to be able to say "not yet", and saying
it explicitly is the opposite of claiming completion. PENDING must be zero before Phase 2.5 can be
presented for acceptance; it is the number that makes that checkable.

VERIFIED MEANS RENDERED. A surface earns REDESIGNED AND VERIFIED only when the running application
was driven and captured in all four combinations (en-LTR and ar-RTL, light and dark). Inheriting a
shared component's repair is NOT verification -- the whole point of the Wave 1 table work is that
it reaches ~100 screens, and exactly three of them have actually been looked at.

usage:  python3 docs/development/phase-2.5/tools/build-coverage-matrix.py [--check]
        --check  exit 1 if the matrix on disk is stale, for CI. Writes nothing.
"""
import re, sys, json, collections, os, hashlib

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..'))
INV = os.path.join(ROOT, 'docs/development/phase-2.5/PHASE-2.5-SURFACE-INVENTORY.md')
OUT = os.path.join(ROOT, 'docs/development/phase-2.5/PHASE-2.5-COVERAGE-MATRIX.md')
EXPECTED_TOTAL = 295

# ---------------------------------------------------------------------------------------------
# VERIFIED SURFACES. One entry per surface actually driven and captured, naming the evidence.
# Adding a row here without the screenshots is the one way this file can lie, so each entry
# carries the directory its four captures live in and the generator checks the files exist.
# ---------------------------------------------------------------------------------------------
VERIFIED = {
    'Product list (table view)':  ('REDESIGNED AND VERIFIED', 'wave-1', '_product__',  'P2.5-WAVE-1'),
    'Order list':                 ('REDESIGNED AND VERIFIED', 'wave-1', '_order__',    'P2.5-WAVE-1'),
    'Customer list':              ('REDESIGNED AND VERIFIED', 'wave-1', '_customer__', 'P2.5-WAVE-1'),
    'Merchant dashboard (page shell)': ('REDESIGNED AND VERIFIED', 'wave-3b', '_dashboard__', 'P2.5-WAVE-3 + 3b'),
    'Coupon list':                ('REDESIGNED AND VERIFIED', 'wave-2', '_coupon-empty__', 'P2.5-WAVE-2'),
    # P2.5-WAVE-10 redesigned the finance tables onto .ss-table and #12 translated the
    # 45 untranslated finance strings; all three PAGES captured in four combos under
    # evidence/wave-10/after. The Add/Deduct/Request modals and the inline payment-status
    # control are NOT separately rendered, so they stay PENDING rather than be claimed here.
    'Finance dashboard':          ('REDESIGNED AND VERIFIED', 'wave-10', '_finance__', 'P2.5-WAVE-10 + #12'),
    'Withdrawal queue':           ('REDESIGNED AND VERIFIED', 'wave-10', '_finance_withdrawals__', 'P2.5-WAVE-10 + #12'),
    'Payments Awaiting Review (unreconciled captures)': ('REDESIGNED AND VERIFIED', 'wave-10', '_finance_unreconciled_captures__', 'P2.5-WAVE-10'),
    # P2.5-WAVE-11 wrapped the three merchant order tables onto .ss-table, verified in a real
    # browser by probe-wave11-order-tables.cjs (order detail + refund detail via encrypted-id
    # URLs; the quick-view modal by clicking the grid eye action). The quick-view modal is not
    # a separate inventory surface, so it earns no row here though it is in evidence/wave-11.
    'Order detail':               ('REDESIGNED AND VERIFIED', 'wave-11', 'order-view__', 'P2.5-WAVE-11', 'order-view'),
    'Refund detail / decision screen': ('REDESIGNED AND VERIFIED', 'wave-11', 'refund-detail__', 'P2.5-WAVE-11'),
    # P2.5-WAVE-12 wrapped the two role-permission matrix modals onto .ss-table, verified by
    # probe-wave12-roles.cjs (create via the static /roles button, edit via the roles DataTable
    # row action). Both names are unique, so no route needle is needed.
    'Create role (xl modal, permission matrix)': ('REDESIGNED AND VERIFIED', 'wave-12', 'role-create-modal__', 'P2.5-WAVE-12'),
    'Edit role (xl modal, permission matrix)':   ('REDESIGNED AND VERIFIED', 'wave-12', 'role-edit-modal__', 'P2.5-WAVE-12'),
    # P2.5-WAVE-13. A live DataTable, so the treatment is .ss-dt-card-body (the Wave-1 scope),
    # not .ss-table. Reaching it needed a fixture permission grant -- the dev role had
    # Manage Customer but not Show Customer, so it had been REDIRECTING to the dashboard.
    'Customer orders (reached by the cart icon)': ('REDESIGNED AND VERIFIED', 'wave-13', '_customer_1__', 'P2.5-WAVE-13'),
}

# Surfaces that are out of the Phase 2.5 merchant scope by the approved phase plan.
OUT_OF_SCOPE = {
    'Super Admin dashboard': 'Phase 06 (SaaS & Super Admin), not Phase 2.5 Merchant Experience',
}

COMBOS = ['en_ltr_light', 'en_ltr_dark', 'ar_rtl_light', 'ar_rtl_dark']


def parse_inventory():
    rows, area = [], None
    for i, line in enumerate(open(INV, encoding='utf-8').read().splitlines(), 1):
        if line.startswith('## '):
            area = line[3:].split('—')[0].split('(')[0].split(' - ')[0].strip()[:58]
        if line.startswith('|') and line.count('|') >= 5:
            c = [x.strip() for x in line.strip('|').split('|')]
            if len(c) < 4 or set(c[0]) <= set('-: '):
                continue
            if c[-1].lower() in ('premium-already', 'needs-polish', 'deficient', 'unknown'):
                rows.append({'line': i, 'area': area, 'screen': c[0],
                             'route': c[1], 'audit': c[-1].lower()})
    return rows


def classify(row):
    """Audit state plus work done -> one of the directive's four, or PENDING."""
    if row['screen'] in OUT_OF_SCOPE:
        return 'OUT OF SCOPE', OUT_OF_SCOPE[row['screen']]
    if row['screen'] in VERIFIED:
        entry = VERIFIED[row['screen']]
        state, wave, prefix, pkg = entry[0], entry[1], entry[2], entry[3]
        route_needle = entry[4] if len(entry) > 4 else None
        # A screen NAME can repeat across areas -- "Order detail" is BOTH the Laravel web screen
        # (route order-view/{id}) and the Flutter screen (OrderDetailScreen). A verified entry that
        # carries a route needle claims only the row whose route contains it; the other falls
        # through to PENDING, so a web capture can never mark a Flutter surface verified.
        if route_needle is None or route_needle in row['route']:
            base = os.path.join(ROOT, 'docs/development/phase-2.5/evidence', wave, 'after')
            missing = [c for c in COMBOS if not os.path.exists(os.path.join(base, f'{prefix}{c}.png'))]
            if missing:
                return 'BLOCKED', f'claimed {state} by {pkg} but these captures are absent: {", ".join(missing)}'
            return state, f'{pkg}; four captures under evidence/{wave}/'
    if row['audit'] == 'unknown':
        return 'BLOCKED', 'audit could not determine its state; it needs to be rendered and classified'
    return 'PENDING', f'audited {row["audit"]}; not yet redesigned or verified'


def build():
    rows = parse_inventory()
    if len(rows) != EXPECTED_TOTAL:
        sys.exit(f'REFUSING: the inventory parses to {len(rows)} surfaces, not {EXPECTED_TOTAL}. '
                 f'A surface was added or lost. Update EXPECTED_TOTAL deliberately, never silently.')

    for r in rows:
        r['state'], r['why'] = classify(r)

    tally = collections.Counter(r['state'] for r in rows)
    by_area = collections.defaultdict(collections.Counter)
    for r in rows:
        by_area[r['area']][r['state']] += 1

    L = []
    L.append('# Phase 2.5 — cumulative coverage matrix')
    L.append('')
    L.append('**GENERATED. Do not hand-edit** — `docs/development/phase-2.5/tools/build-coverage-matrix.py`')
    L.append('rebuilds it from `PHASE-2.5-SURFACE-INVENTORY.md` and refuses to run if the surface count moves.')
    L.append('The directive requires that "a screen must not silently disappear from the inventory"; a')
    L.append('hand-maintained matrix is precisely how that happens.')
    L.append('')
    L.append('## Where Phase 2.5 stands')
    L.append('')
    L.append('| state | count | of 295 |')
    L.append('|---|---:|---:|')
    for s in ['PREMIUM VERIFIED', 'REDESIGNED AND VERIFIED', 'BLOCKED', 'OUT OF SCOPE', 'PENDING']:
        L.append(f'| {s} | {tally.get(s,0)} | {100*tally.get(s,0)/len(rows):.1f}% |')
    L.append(f'| **total** | **{len(rows)}** | |')
    L.append('')
    L.append('**PENDING is not one of the directive\'s four states, and that is the point.** The four')
    L.append('describe a finished surface. A cumulative matrix has to be able to say "not yet", and')
    L.append('saying it out loud is the opposite of claiming completion. **Phase 2.5 cannot be presented')
    L.append('for acceptance while PENDING is above zero.**')
    L.append('')
    L.append('**VERIFIED means rendered.** A surface earns a verified state only when the running')
    L.append('application was driven and captured in all four combinations. Inheriting a shared')
    L.append("component's repair is not verification — Wave 1's table work reaches roughly a hundred")
    L.append('screens and exactly three of them have been looked at. The other ~97 stay PENDING.')
    L.append('')
    L.append('## By merchant area')
    L.append('')
    L.append('| area | total | premium | redesigned | blocked | out of scope | pending |')
    L.append('|---|---:|---:|---:|---:|---:|---:|')
    for a in sorted(by_area, key=lambda k: -sum(by_area[k].values())):
        t = by_area[a]
        L.append(f'| {a} | {sum(t.values())} | {t.get("PREMIUM VERIFIED",0)} | '
                 f'{t.get("REDESIGNED AND VERIFIED",0)} | {t.get("BLOCKED",0)} | '
                 f'{t.get("OUT OF SCOPE",0)} | {t.get("PENDING",0)} |')
    L.append('')
    L.append('## Every surface')
    L.append('')
    L.append('`audit` is the state the Phase 2.5 audit recorded for the surface. `state` is what has')
    L.append('actually been done to it since. They are different questions and the matrix keeps both.')
    L.append('')
    for a in sorted(by_area, key=lambda k: -sum(by_area[k].values())):
        L.append(f'### {a}')
        L.append('')
        L.append('| surface | route / kind | audit | state | evidence or reason |')
        L.append('|---|---|---|---|---|')
        for r in [x for x in rows if x['area'] == a]:
            scr = r['screen'].replace('|', '\\|')
            rt = r['route'].replace('|', '\\|')[:62]
            L.append(f'| {scr} | `{rt}` | {r["audit"]} | **{r["state"]}** | {r["why"]} |')
        L.append('')
    return '\n'.join(L) + '\n', tally, len(rows)


if __name__ == '__main__':
    text, tally, total = build()
    if '--check' in sys.argv:
        cur = open(OUT, encoding='utf-8').read() if os.path.exists(OUT) else ''
        if cur != text:
            sys.exit('STALE: PHASE-2.5-COVERAGE-MATRIX.md does not match the inventory. Re-run without --check.')
        print(f'coverage matrix is current: {total} surfaces, {dict(tally)}')
    else:
        open(OUT, 'w', encoding='utf-8').write(text)
        print(f'wrote {OUT}')
        print(f'  {total} surfaces  ' + '  '.join(f'{k}={v}' for k, v in sorted(tally.items())))
