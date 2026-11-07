#!/usr/bin/env python3
"""Which merchant tables does P2.5-WAVE-1 actually reach?

WAVE-1's rules are all scoped to `.ss-dt-card-body`, deliberately: unscoped they would reach every
table in the 70 vendored WorkDo modules, which DesignSystemStylesheetScopeTest refuses. The
consequence is that a merchant table NOT inside that wrapper got none of the redesign -- and the
WAVE-1 commit claimed "~100 of the 295 surfaces" on an INFERENCE from inventory area sizes, never
a measurement. This is the measurement.

It counts BLADE FILES, not inventory surfaces. One file can back several inventory rows and one
inventory row can be a component, so no ratio between this and the 295 is computed here: saying
"39 files" is defensible and saying "~100 surfaces" was not.

usage:  python3 docs/development/phase-2.5/tools/census-table-wrapper.py
exit 1 if the census clearly is not reaching the views.
"""
import os
import re
import sys

SKIP = {'front_end', 'plans', 'marketplace', 'vendor'}
TABLE = re.compile(r'<table\b|x-datatable|\{\{\s*\$dataTable->table')


def out_of_pattern(rel):
    """Legitimately outside the merchant table pattern. These are NOT misses."""
    low = rel.lower()
    if low.startswith('email/'):
        return 'email template'
    if any(k in low for k in ('receipt', 'shippinglabel', 'printview')):
        return 'print document'
    if 'bar-chart' in low or 'chart_data' in low:
        return 'chart'
    if low.startswith('roles/'):
        return 'permission matrix'
    if 'filter' in low or low.endswith('/tab'):
        return 'included fragment'
    return None


def main():
    here = os.path.dirname(os.path.abspath(__file__))
    base = os.path.normpath(os.path.join(here, '..', '..', '..', '..', 'resources', 'views'))

    wrapped, missed, out = [], [], []
    for dp, _, fns in os.walk(base):
        rel_dir = os.path.relpath(dp, base)
        if any(rel_dir == s or rel_dir.startswith(s + os.sep) for s in SKIP):
            continue
        for fn in fns:
            if not fn.endswith('.blade.php'):
                continue
            fp = os.path.join(dp, fn)
            rel = os.path.relpath(fp, base)[:-len('.blade.php')]
            src = open(fp, encoding='utf-8', errors='ignore').read()
            body = '\n'.join(l for l in src.splitlines() if '{{--' not in l)
            if not TABLE.search(body):
                continue
            if 'x-datatable' in body or 'card-body table-border-style ss-dt-card-body' in body:
                wrapped.append(rel)
            elif out_of_pattern(rel):
                out.append((rel, out_of_pattern(rel)))
            else:
                missed.append(rel)

    total = len(wrapped) + len(missed) + len(out)
    print('  merchant Blade files rendering a table : %d' % total)
    print('    reached by WAVE-1                    : %d' % len(wrapped))
    print('    correctly outside the pattern        : %d' % len(out))
    print('    GENUINE MISSES                       : %d' % len(missed))

    # PREMISE. A wrong base path would otherwise report a flattering "0 misses".
    if total < 50:
        print('\n  PREMISE FAILED: only %d table files found; the census is not reaching the '
              'views, so every number above is meaningless.' % total)
        return 1

    print('\n  genuine misses:')
    for m in sorted(missed):
        print('    %s' % m)
    return 0


if __name__ == '__main__':
    sys.exit(main())
