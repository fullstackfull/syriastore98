#!/usr/bin/env python3
"""
PHASE 03 — TWO STORES' CAPTURES OF THE SAME SURFACE MUST NOT BE THE SAME IMAGE.

WHY THIS EXISTS, AND WHY THE PHASE 2.5 CHECK CANNOT DO IT.

`phase-2.5/tools/check-distinct-surface-captures.py` groups candidate collisions BY DIRECTORY
FIRST, on purpose: in Phase 2.5 a different directory meant a different capture WAVE, and the same
merchant surface photographed twice across waves is legitimately identical.

In Phase 03 a different directory means a different STORE. The grouping is therefore exactly
backwards for this evidence: a cross-directory collision is not the benign case, it is the single
most important thing to catch, because the whole claim Phase 03 has to support is that a storefront
reflects ITS OWN store.

MEASURED when this was written: of 126 captures across three stores, **28 were byte-identical
across stores** — every `cart`, `wishlist`, `track-order` and `contact-us` row for `aleppo-soap` and
`p3fixt-no-theme`. The Phase 2.5 check reported those same 126 files as clean, correctly by its own
rules. The cause was not a tenancy fault: both pages were right, and the storefront header renders
`themeSettings['header_logo']`, which neither fixture store had set, so both fell back to the
THEME's logo. The HTML differed by 99 lines and every one of them was a `csrf-token` or an
`og:url` — nothing visible. Seeding a per-store logo fixed it.

THAT IS THE FAILURE MODE THIS GUARDS: evidence that is individually valid and collectively
worthless, because no reader can tell which store a screenshot is of.

A clean result here does NOT mean a capture shows the right screen — `storefront-matrix.cjs`'s
premise checks are what test that. This answers one narrower question: are two stores
distinguishable?

    python3 check-cross-store-captures.py <evidence-root> [--json]

<evidence-root> holds one subdirectory per store. Exit 0 clean, 1 collisions, 2 premise failed.
"""
import hashlib
import json
import os
import re
import sys

# `aleppo-soap_cart__ar_mobile.png`            -> store, surface, combo `ar_mobile`
# `aleppo-soap_cart__ar_dark_mobile.png`       -> store, surface, combo `ar_dark_mobile`
#
# THE APPEARANCE SEGMENT WAS ADDED IN M2-B and this check REFUSED TO REPORT CLEAN until it could
# parse it -- "PREMISE FAILED: parsed 0 captures, so a pass would mean nothing." That is the
# behaviour it was built for: an unparsed name is a capture the check is not covering, and a clean
# report over files it never opened is worse than no report. Recorded because the refusal was
# correct and is the second time a gate in this project has caught its own blind spot.
NAME = re.compile(
    r'^(?P<store>.+?)_(?P<surface>.+?)__(?P<combo>[a-z]{2}(?:_(?:light|dark))?_[a-z]+)\.png$')


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('-')]
    as_json = '--json' in sys.argv

    if not args:
        sys.stderr.write('usage: check-cross-store-captures.py <evidence-root> [--json]\n')
        return 2

    root = os.path.abspath(args[0])

    if not os.path.isdir(root):
        sys.stderr.write(f'PREMISE FAILED: no evidence directory at {root}\n')
        return 2

    # digest -> [(store, surface, combo, relpath)]
    by_hash = {}
    stores = set()
    scanned = 0
    unparsed = []

    for dirpath, _dirnames, filenames in os.walk(root):
        for fn in sorted(filenames):
            if not fn.endswith('.png'):
                continue

            path = os.path.join(dirpath, fn)
            rel = os.path.relpath(path, root)
            m = NAME.match(fn)

            if not m:
                # Never skip silently: an unparsed name would be a capture this check does not
                # cover while reporting a clean result over it.
                unparsed.append(rel)
                continue

            with open(path, 'rb') as fh:
                digest = hashlib.sha256(fh.read()).hexdigest()

            by_hash.setdefault(digest, []).append(
                (m.group('store'), m.group('surface'), m.group('combo'), rel))
            stores.add(m.group('store'))
            scanned += 1

    if scanned == 0:
        sys.stderr.write('PREMISE FAILED: parsed 0 captures, so a pass would mean nothing.\n')
        return 2

    if len(stores) < 2:
        sys.stderr.write(
            f'PREMISE FAILED: captures for only {len(stores)} store(s) ({sorted(stores)}).\n'
            'A cross-store check needs at least two, or it can never find anything.\n')
        return 2

    collisions = []
    for digest, entries in by_hash.items():
        if len(entries) < 2:
            continue
        distinct_stores = {e[0] for e in entries}
        if len(distinct_stores) > 1:
            collisions.append({
                'stores': sorted(distinct_stores),
                'surface': sorted({e[1] for e in entries}),
                'combo': sorted({e[2] for e in entries}),
                'files': sorted(e[3] for e in entries),
            })

    if as_json:
        print(json.dumps({'scanned': scanned, 'stores': sorted(stores),
                          'unparsed': unparsed, 'collisions': collisions}, indent=2))
        return 1 if (collisions or unparsed) else 0

    print('CROSS-STORE CAPTURE CHECK')
    print('=' * 88)
    print(f'scanned {scanned} capture(s) across {len(stores)} store(s): {", ".join(sorted(stores))}')
    print(f'{len(by_hash)} distinct contents')
    print('=' * 88)

    if unparsed:
        print(f'\nPREMISE FAILED: {len(unparsed)} capture(s) whose names this check cannot parse, '
              'so they are NOT covered:')
        for u in unparsed[:20]:
            print(f'    {u}')
        return 2

    if not collisions:
        print(f'\nno capture is byte-identical to another STORE\'s capture.')
        print('\nThis does NOT prove any capture shows the right screen — storefront-matrix.cjs\'s')
        print('premise checks are what test that. It proves the stores are distinguishable.')
        return 0

    print(f'\n{len(collisions)} CROSS-STORE COLLISION(S) — the same image for different stores:\n')
    for c in collisions:
        print(f'  stores {c["stores"]}  surface {c["surface"]}  combo {c["combo"]}')
        for f in c['files']:
            print(f'      {f}')
        print()
    print('Two merchants\' storefronts that photograph identically cannot evidence either one.')
    print('Check the per-store logo, name and section copy before re-capturing.')
    return 1


if __name__ == '__main__':
    sys.exit(main())
