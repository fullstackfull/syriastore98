#!/usr/bin/env python3
"""
TWO CAPTURES CLAIMING DIFFERENT SURFACES MUST NEVER BE BYTE-IDENTICAL.
=================================================================================================

THE DEFECT THIS EXISTS FOR, found 2026-11-06 in accepted evidence. Three surfaces -- `FAQ list`,
`Flash sale list` and `Delivery boys` -- were marked PREMIUM VERIFIED in the coverage matrix on
twelve captures that are all screenshots of the DASHBOARD, each carrying a "Permission denied."
toast. The dev fixture could not open those screens; a Laratrust refusal is `redirect()->back()`,
so the harness followed the redirect and photographed the landing page.

`build-coverage-matrix.py` checks that the four capture FILES EXIST. It cannot check what is
inside them, so a dashboard redirect satisfies it perfectly. This closes part of that gap, with no
dependency beyond the standard library.

THE INVARIANT, AND THE ONE NARROWING IT NEEDED. Two genuinely different screens cannot produce the
same PNG. Measured over the whole evidence base: 657 captures, 581 distinct contents, 71 groups of
identical files, and of those 71 the overwhelming majority are the SAME surface name copied between
`before/` and `after/` or carried between waves, which is legitimate.

The comparison is made WITHIN ONE WAVE'S capture directory, and that is a real distinction rather
than a convenience. Across waves, two different names legitimately describe one rendering: wave-16b
captured `_customer_timeline_1__` as a SURFACE and wave-16c captured `timeline-pills__` as the
COMPONENT verified on that same page. Identical files, both correct, nothing wrong. Within a single
wave, two different surfaces being pixel-identical means the harness photographed the same thing
twice and filed it under two names -- which is what a `redirect()->back()` does to a sweep.

Every true positive this gate has found was intra-wave: `_faqs__`/`_flash_sale__`/`_deliveryboy__`
and `_tag__`/`_testimonial__` and `_shipping__`/`_shopify_product__`, all inside wave-33.

So this gate has NO exemption list -- the narrowing is structural, and an exemption list on a
hygiene check is the kind that rots into noise.

WHAT IT DOES NOT CATCH, stated plainly so nobody reads a pass as more than it is:
  - a SINGLE impostor. If only one surface redirects to the dashboard in a given combination there
    is no second file to collide with, and this gate says nothing.
  - a redirect to some OTHER wrong screen.
  - a capture of the right screen in the wrong state.
`detect-refusal-toast-captures.py` is the thorough check for the first two; it needs Pillow,
which CI deliberately does not install, so it stays a local/manual run. This is the half that costs
nothing and can therefore run on every push.

usage:  python3 docs/development/phase-2.5/tools/check-distinct-surface-captures.py
        exit 1 if two captures claiming different surfaces have identical content.
"""
import hashlib
import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..'))
EVID = os.path.join(ROOT, 'docs/development/phase-2.5/evidence')

# `_faqs__en_ltr_light.png` -> surface `_faqs__`, combination `en_ltr_light`.
NAME = re.compile(r'^(?P<surface>.*?)(?P<combo>en_ltr_light|en_ltr_dark|ar_rtl_light|ar_rtl_dark)\.png$')


def main():
    if not os.path.isdir(EVID):
        sys.stderr.write(f'PREMISE FAILED: no evidence directory at {EVID}\n')
        return 2

    by_hash = {}
    scanned = 0

    for dirpath, _dirnames, filenames in os.walk(EVID):
        for fn in sorted(filenames):
            if not fn.endswith('.png'):
                continue
            path = os.path.join(dirpath, fn)
            with open(path, 'rb') as fh:
                digest = hashlib.sha256(fh.read()).hexdigest()
            by_hash.setdefault(digest, []).append(os.path.relpath(path, ROOT))
            scanned += 1

    if scanned == 0:
        sys.stderr.write('PREMISE FAILED: scanned 0 captures, so a pass would mean nothing.\n')
        return 2

    collisions = []
    for digest, paths in by_hash.items():
        if len(paths) < 2:
            continue
        # Group by the capture DIRECTORY first: see the narrowing in the docstring. A component
        # captured by one wave and the page captured by another are legitimately identical.
        by_dir = {}
        for p in paths:
            by_dir.setdefault(os.path.dirname(p), []).append(p)
        for dirname, same_dir in by_dir.items():
            if len(same_dir) < 2:
                continue
            surfaces = set()
            for p in same_dir:
                m = NAME.match(os.path.basename(p))
                # A file with no combination suffix is named by hand; compare the whole basename,
                # which is stricter, rather than silently skipping it.
                surfaces.add(m.group('surface') if m else os.path.basename(p))
            if len(surfaces) > 1:
                collisions.append((sorted(surfaces), sorted(same_dir)))

    print('DISTINCT-SURFACE CAPTURE CHECK')
    print('=' * 88)
    print(f'scanned {scanned} capture(s); {len(by_hash)} distinct contents')
    print(f'{sum(1 for v in by_hash.values() if len(v) > 1)} group(s) of identical files '
          '(the same surface copied between before/after or across waves is expected)')
    print('=' * 88)

    if not collisions:
        print('\nno two captures claiming different surfaces share content.')
        print('\nThis does NOT prove any capture shows the right screen. A single impostor has no')
        print('second file to collide with. See detect-refusal-toast-captures.py.')
        return 0

    print(f'\n{len(collisions)} COLLISION(S) -- different surfaces, identical pixels. At least one')
    print('of each group is photographing a screen it does not claim, which is almost always a')
    print('permission refusal that redirected to the dashboard:\n')
    for surfaces, paths in collisions:
        print('  surfaces: ' + ', '.join(repr(s) for s in surfaces))
        for p in paths:
            print(f'      {p}')
        print()
    return 1


if __name__ == '__main__':
    sys.exit(main())
