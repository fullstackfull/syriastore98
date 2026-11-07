#!/usr/bin/env python3
"""Regenerate `.ss-table--stack` in store-syria-ds.css from the P2.5-WAVE-28 block.

TWO STACKED TABLE FAMILIES, ONE SET OF DECLARATIONS. Wave 28 stacks the host's DataTables inside
`.ss-dt-card-body`; Wave 29 converged the design system's own `.ss-table--stack` onto exactly the
same mechanism. They cannot share a rule because they are in different stylesheets on different
breakpoints -- and CSS has no mixins -- so the modifier's declarations are GENERATED from the
Wave 28 block rather than retyped, and StackedTableRowTest asserts the two sets stay identical.

Run this after changing the Wave 28 block. It rewrites only the region between the generated
markers in store-syria-ds.css; the comment above them and the rules below them are left alone.

usage:  python3 docs/development/phase-2.5/tools/sync-stack-block.py [--check]
        --check exits 1 instead of writing, for a pre-commit hook or a quick verification.
"""
import re
import sys

SOURCE = 'public/css/custom.css'
TARGET = 'public/css/store-syria-ds.css'
OPEN_MARK = '    /* generated from P2.5-WAVE-28 by sync-stack-block.py -- do not edit by hand */\n'
END_MARK = '    /* end generated */'
FROM_SELECTOR = '.ss-dt-card-body table.dataTable'
# `.ss-table.ss-table--stack`, not `.ss-table--stack`, and the extra class is load-bearing.
# `.ss-table--stack > tbody > tr` is (0,1,2) and this same stylesheet carries
# `.ss-table tbody tr:last-child { border-block-end: 0 }` at (0,2,2) two thousand lines earlier,
# which wins on specificity whatever the source order -- so the LAST card in every stacked table
# rendered with three borders and no bottom edge. The Wave 28 block never fought this contest
# because its rows are `table.dataTable > tbody > tr`, which that rule cannot match; the converged
# modifier inherited it. Every call site already writes both classes together.
TO_SELECTOR = '.ss-table.ss-table--stack'


def wave28_rules(css):
    i = css.find('P2.5-WAVE-28 —')
    j = css.find('/* end P2.5-WAVE-28 */')
    if i < 0 or j < i:
        sys.exit('no P2.5-WAVE-28 block in ' + SOURCE)
    block = re.sub(r'/\*.*?\*/', '', css[i:j], flags=re.S)
    rules = []
    for m in re.finditer(r'([^{}]+)\{([^{}]*)\}', block):
        selector = ' '.join(m.group(1).split()).split('*/')[-1].strip()
        if not selector.startswith(FROM_SELECTOR):
            continue
        decls = [d.strip() for d in m.group(2).split(';') if d.strip()]
        rules.append((selector, decls))
    return rules


def render(rules):
    out = []
    for selector, decls in rules:
        parts = [p.strip() for p in selector.replace(FROM_SELECTOR, TO_SELECTOR).split(',')]
        out.append('    ' + ',\n    '.join(parts) + ' {')
        out += ['        ' + d + ';' for d in decls]
        out.append('    }\n')
    return '\n'.join(out)


def main():
    check = '--check' in sys.argv
    rules = wave28_rules(open(SOURCE, encoding='utf-8').read())
    generated = render(rules)
    target = open(TARGET, encoding='utf-8').read()

    i = target.find(OPEN_MARK)
    j = target.find(END_MARK)
    if i < 0 or j < i:
        sys.exit('no generated region in ' + TARGET + ' -- add the markers first')

    current = target[i + len(OPEN_MARK):j]
    if current == generated:
        print(f'  up to date: {len(rules)} rules')
        return 0

    if check:
        print(f'  OUT OF DATE: {TARGET} does not match the Wave 28 block. Run without --check.')
        return 1

    open(TARGET, 'w', encoding='utf-8').write(target[:i + len(OPEN_MARK)] + generated + target[j:])
    print(f'  regenerated: {len(rules)} rules')
    return 0


if __name__ == '__main__':
    sys.exit(main())
