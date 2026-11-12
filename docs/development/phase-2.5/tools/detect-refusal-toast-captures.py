#!/usr/bin/env python3
"""
IS THIS CAPTURE EVIDENCE OF THE SURFACE IT CLAIMS, OR OF A PERMISSION REFUSAL?
=================================================================================================

THE DEFECT THIS EXISTS FOR, found 2026-11-06 in accepted evidence. SIX surfaces were marked
PREMIUM VERIFIED in the coverage matrix on captures that photograph a refusal rather than the
screen: `FAQ list`, `Flash sale list`, `Delivery boys`, `Tag list`, `Testimonial list` and
`Product question list`. The dev fixture held no `Manage *` permission for any of them, a Laratrust
refusal is `redirect()->back()`, and the harness followed the redirect and photographed wherever it
landed.

`build-coverage-matrix.py` checks that the four capture FILES EXIST. It cannot check what is inside
them, so a refusal satisfies it perfectly. That is the gap this closes.

THE SIGNAL IS THE TOAST, NOT THE DESTINATION -- and finding that out took two wrong tools first.
  1. Comparing whole frames against the dashboard: no separation at all. Every merchant screen
     shares the sidebar, header and background with the dashboard, so ~90% agreement is the FLOOR
     for any screen here. At a 0.90 threshold it accused about thirty good captures.
  2. Comparing only the CONTENT region against the dashboard: this worked, but only for the three
     surfaces that happened to land on the dashboard. `Tag list` and `Testimonial list` had landed
     on **Stock Reports** and `Product question list` on the **Product grid**, because `back()`
     returns to whatever the probe visited previously. A destination-based detector cannot see
     those, and it did not.
  3. The refusal toast itself. Every one of them carries it, wherever it landed.

HOW THE TOAST IS DETECTED. Not by OCR, and not in greyscale -- greyscale over that region is ~90%
for every light-theme capture, because the region is mostly page background. The toast's diamond is
a strongly saturated red that almost nothing else in this admin uses at that size and position, so
the measure is simply the FRACTION of strongly-red pixels in the top-right corner.

CALIBRATED ON BOTH SIDES, by eye:
    1.80% .. 1.12%  twenty-four captures, six surfaces x four combinations, each confirmed a
                    refusal by opening it (dashboard, Stock Reports and the Product grid among the
                    destinations)
    -- the gap --
    0.47%           wave-2/after/_coupon-empty__ar_rtl_light.png, CONFIRMED LEGITIMATE by eye: the
                    Arabic empty state of the coupon list, whose red is the bulk-delete trash
                    button in the toolbar
    0.02% and below noise
0.80 of a percent sits inside that gap.

WHAT A CLEAN RESULT MEANS: no capture shows a permission-refusal toast. It does NOT prove a capture
shows the RIGHT screen -- a redirect that produced no toast, or a capture of the right screen in
the wrong state, still passes. `check-distinct-surface-captures.py` is the complementary check, and
it is the one that found `Tag list` and `Testimonial list`, because they were byte-identical to
each other.

usage:
  python3 docs/development/phase-2.5/tools/detect-refusal-toast-captures.py [--all] [--json]

    (default)  check only the captures the coverage matrix cites as evidence
    --all      check every capture under evidence/
"""
import os
import re
import sys
import json

try:
    from PIL import Image
except ImportError:
    sys.stderr.write('PREMISE FAILED: Pillow is not installed, so nothing was checked.\n')
    sys.exit(2)

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..'))
EVID = os.path.join(ROOT, 'docs/development/phase-2.5/evidence')
BUILDER = os.path.join(ROOT, 'docs/development/phase-2.5/tools/build-coverage-matrix.py')

COMBOS = ['en_ltr_light', 'en_ltr_dark', 'ar_rtl_light', 'ar_rtl_dark']

# The top-right corner, where the toast renders in BOTH directions -- verified on the Arabic
# captures, where the toast stays top-right although the layout mirrors.
TOAST_BOX = (0.70, 0.0, 1.0, 0.14)

# A pixel counts as toast-red when red clearly dominates both other channels. These bounds are the
# ones measured against the confirmed captures; they are deliberately strict, because the cost of a
# false accusation here is telling someone their good evidence is fake.
RED_MIN, GB_MAX, DOMINANCE = 150, 100, 70

# Fraction of the region that must be toast-red. See the calibration in the docstring: confirmed
# refusals run 1.12-1.80%, the closest legitimate capture 0.47%.
TOAST_AT = 0.0080


def toast_fraction(path):
    """How much of the top-right corner is the refusal toast's saturated red?"""
    with Image.open(path) as im:
        w, h = im.size
        box = (int(w * TOAST_BOX[0]), int(h * TOAST_BOX[1]),
               int(w * TOAST_BOX[2]), int(h * TOAST_BOX[3]))
        data = list(im.convert('RGB').crop(box).getdata())
    if not data:
        return 0.0
    hits = sum(1 for r, g, b in data
               if r > RED_MIN and g < GB_MAX and b < GB_MAX
               and (r - g) > DOMINANCE and (r - b) > DOMINANCE)
    return hits / float(len(data))


def matrix_cited_captures():
    """The (surface, wave, prefix) rows the coverage matrix cites, read from the builder itself."""
    src = open(BUILDER, encoding='utf-8').read()
    block = re.search(r'^VERIFIED = \{(.*?)^\}', src, re.S | re.M)
    if not block:
        sys.stderr.write('PREMISE FAILED: could not find the VERIFIED table in build-coverage-matrix.py\n')
        sys.exit(2)

    rows = []
    for m in re.finditer(
            r"^\s*['\"](?P<screen>[^'\"]+)['\"]\s*:\s*\(\s*"
            r"['\"](?P<state>[A-Z ]+)['\"]\s*,\s*"
            r"['\"](?P<wave>[^'\"]+)['\"]\s*,\s*"
            r"['\"](?P<prefix>[^'\"]*)['\"]",
            block.group(1), re.M):
        rows.append((m.group('screen'), m.group('state'), m.group('wave'), m.group('prefix')))
    return rows


def main():
    check_all = '--all' in sys.argv
    as_json = '--json' in sys.argv

    targets = []
    if check_all:
        for dirpath, _d, filenames in os.walk(EVID):
            for fn in sorted(filenames):
                if fn.endswith('.png'):
                    targets.append((fn, os.path.join(dirpath, fn), '(all)', ''))
    else:
        for screen, state, wave, prefix in matrix_cited_captures():
            for combo in COMBOS:
                path = os.path.join(EVID, wave, 'after', f'{prefix}{combo}.png')
                if os.path.exists(path):
                    targets.append((f'{prefix}{combo}.png', path, screen, state))

    if not targets:
        sys.stderr.write('PREMISE FAILED: 0 captures to check, so a pass would mean nothing.\n')
        return 2

    results, refusals = [], []
    for fn, path, screen, state in targets:
        frac = toast_fraction(path)
        rec = {'file': fn, 'surface': screen, 'state': state,
               'toast_red_fraction': round(frac, 6),
               'verdict': 'REFUSAL' if frac >= TOAST_AT else 'ok'}
        results.append(rec)
        if frac >= TOAST_AT:
            refusals.append(rec)

    if as_json:
        print(json.dumps({'checked': len(results), 'refusals': refusals, 'all': results}, indent=2))
        return 1 if refusals else 0

    print('REFUSAL-TOAST CHECK on captures the coverage matrix cites as evidence'
          if not check_all else 'REFUSAL-TOAST CHECK on every capture under evidence/')
    print('=' * 92)
    print(f'checked {len(results)} capture(s)')
    print(f'a capture is called a refusal at >= {TOAST_AT:.2%} toast-red in its top-right corner')
    print('=' * 92)

    if refusals:
        print(f'\n{len(refusals)} CAPTURE(S) PHOTOGRAPH A PERMISSION REFUSAL, not the surface claimed:\n')
        for r in sorted(refusals, key=lambda x: -x['toast_red_fraction']):
            print(f"  {r['toast_red_fraction']:.2%}  {r['file']:44} claimed as "
                  f"{r['state']} for \"{r['surface']}\"")
    else:
        print('\nno capture shows a permission-refusal toast.')

    worst = sorted((r for r in results if r['verdict'] == 'ok'),
                   key=lambda x: -x['toast_red_fraction'])[:5]
    if worst:
        print('\nclosest clean captures, so the separation is visible rather than asserted:')
        for r in worst:
            print(f"  {r['toast_red_fraction']:.2%}  {r['file']:44} \"{r['surface']}\"")

    print('\nA CLEAN RESULT MEANS no capture shows a refusal toast. It does NOT prove a capture')
    print('shows the RIGHT screen. check-distinct-surface-captures.py is the complementary check.')
    return 1 if refusals else 0


if __name__ == '__main__':
    sys.exit(main())
