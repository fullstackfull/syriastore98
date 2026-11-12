#!/usr/bin/env python3
"""
P2.5-WAVE-18 — put the design system's required marker on every required merchant field.

WHY A TOOL AND NOT 145 HAND EDITS: the pairing rule has to be stated once and applied identically,
and it has to be RE-RUNNABLE so the ratchet test and the edit cannot drift apart. Run with no
arguments it is a DRY RUN and changes nothing.

THE MARKER IS THE DESIGN SYSTEM'S OWN, already defined and already used:
    <span class="ss-label__required" aria-hidden="true">*</span>
  - store-syria-ds.css:907 defines it, with the reason recorded beside it ("the host's forms mark
    required, so we match the host").
  - resources/views/components/ss/secret-field.blade.php:41 already emits exactly this shape.
  - It is self-confining: the class carries the `ss-` prefix, so using it in a host view does not
    breach the rule DesignSystemStylesheetScopeTest enforces on the stylesheet.

IT GOES AFTER THE LABEL, NOT INSIDE IT, and that is deliberate. `Form::label()` escapes its text
unless you pass `$escape_html = false`, and threading an unescaped fourth argument through 145
call sites to inject markup is a worse trade than one sibling span. `aria-hidden` keeps the
asterisk out of the accessible name either way -- the `required` ATTRIBUTE is what reaches
assistive technology, not the glyph -- so placing it outside the label is also the safer semantics.

WHAT COUNTS AS REQUIRED: a control in the same block carrying `'required' => 'required'` (the
Form:: helper form) or a bare `required` attribute (the plain-HTML form). Blade and HTML comments
are stripped before any of this, so the tool cannot match its own prose.

WHAT IS SKIPPED, and why each is correct rather than convenient:
  - resources/views/front_end/** -- storefront, Phase 03 scope.
  - a block that already carries a marker, in ANY of its shapes (`ss-label__required`, or the
    `<sup class="text-danger">*</sup>` fourteen views already use). This tool does not unify the
    two shapes; it only adds one where there is none.
  - a block with no label at all -- there is nothing to mark.

usage:
    python3 docs/development/phase-2.5/tools/mark-required-fields.py            # dry run
    python3 docs/development/phase-2.5/tools/mark-required-fields.py --apply
    python3 docs/development/phase-2.5/tools/mark-required-fields.py --list     # dry run, verbose
"""
import os
import re
import sys

# tools -> phase-2.5 -> development -> docs -> repo root. FOUR levels; three lands in docs/ and
# the walk then yields nothing, which looks exactly like "no work to do".
ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '..'))
VIEWS = os.path.join(ROOT, 'resources', 'views')

if not os.path.isdir(VIEWS):                       # fail loudly rather than report a clean zero
    raise SystemExit(f'resources/views not found under {ROOT!r} -- check ROOT in this file.')

MARKER = '<span class="ss-label__required" aria-hidden="true">*</span>'

# A block opens at a form-group / column wrapper and runs to the next one or to the end.
BLOCK_SPLIT = re.compile(
    r'(<div[^>]*class="[^"]*(?:form-group|col-md-|col-lg-|col-sm-|col-xl-|col-12)[^"]*"[^>]*>)')

# the three label shapes this codebase uses, with their closing delimiter
LABEL_PATTERNS = [
    (re.compile(r'\{\{\s*Form::label\s*\((?:[^{}]|\{[^{}]*\})*?\)\s*\}\}'), '}}'),
    (re.compile(r'\{!!\s*Form::label\s*\((?:[^{}]|\{[^{}]*\})*?\)\s*!!\}'), '!!}'),
    (re.compile(r'<label\b[^>]*>.*?</label\s*>', re.S), '</label>'),
]

REQUIRED = re.compile(
    r"""['"]required['"]\s*=>\s*['"]required['"]"""   # Form:: helper option
    r"""|(?<![\w-])required(?:\s*=\s*["']required["'])?\s*(?=[/>\s])""",  # bare HTML attribute
    re.I)

ALREADY_MARKED = re.compile(r'ss-label__required|<sup[^>]*>\s*\*|text-danger[^>]*>\s*\*')


def strip_comments(text: str) -> str:
    text = re.sub(r'\{\{--.*?--\}\}', lambda m: ' ' * len(m.group(0)), text, flags=re.S)
    return re.sub(r'<!--.*?-->', lambda m: ' ' * len(m.group(0)), text, flags=re.S)


def merchant_views():
    for root, _, files in os.walk(VIEWS):
        if 'front_end' in root.replace('\\', '/').split('/'):
            continue
        for fn in sorted(files):
            if fn.endswith('.blade.php'):
                yield os.path.join(root, fn)


def plan(path):
    """Return (insert_offsets, label_texts) for one file, computed on the comment-stripped copy."""
    raw = open(path, encoding='utf-8', errors='replace').read()
    probe = strip_comments(raw)

    parts = BLOCK_SPLIT.split(probe)
    inserts, labels = [], []
    offset = len(parts[0])

    # parts is [pre, opener, body, opener, body, ...]
    for i in range(1, len(parts) - 1, 2):
        opener, body = parts[i], parts[i + 1]
        block_start = offset + len(opener)
        offset += len(opener) + len(body)

        if not REQUIRED.search(body) or ALREADY_MARKED.search(body):
            continue

        best = None
        for pattern, _closer in LABEL_PATTERNS:
            m = pattern.search(body)
            if m and (best is None or m.start() < best.start()):
                best = m
        if best is None:
            continue

        inserts.append(block_start + best.end())
        text = re.search(r"__\(\s*'([^']{1,60})'", best.group(0))
        labels.append(text.group(1) if text else best.group(0)[:40].replace('\n', ' '))

    return raw, inserts, labels


def main():
    apply_it = '--apply' in sys.argv
    verbose = '--list' in sys.argv

    total, touched = 0, 0
    for path in merchant_views():
        raw, inserts, labels = plan(path)
        if not inserts:
            continue
        touched += 1
        total += len(inserts)
        rel = os.path.relpath(path, os.path.normpath(ROOT))
        print(f'  {len(inserts):3d}  {rel}')
        if verbose:
            for lab in labels:
                print(f'          * {lab}')
        if apply_it:
            out = raw
            for pos in sorted(inserts, reverse=True):   # back to front, so offsets stay valid
                out = out[:pos] + MARKER + out[pos:]
            open(path, 'w', encoding='utf-8').write(out)

    print(f'\n{total} required fields in {touched} merchant views'
          + (' -- MARKED.' if apply_it else ' would be marked. Re-run with --apply.'))
    return 0


if __name__ == '__main__':
    sys.exit(main())
