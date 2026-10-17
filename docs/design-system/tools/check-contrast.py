#!/usr/bin/env python3
"""WCAG 2.1 contrast checker for the Store Syria design tokens.

Resolves `var()` indirection (semantic tokens alias primitives) and keeps the
light and dark scopes separate, so the ratios reported are the ones a browser
actually computes. Exit 1 if any pair misses its target.
"""
import re
import sys
import pathlib


def srgb_to_lin(c):
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def rel_luminance(hex_color):
    h = hex_color.strip().lstrip('#')
    if len(h) == 3:
        h = ''.join(ch * 2 for ch in h)
    r, g, b = (int(h[i:i + 2], 16) / 255 for i in (0, 2, 4))
    return 0.2126 * srgb_to_lin(r) + 0.7152 * srgb_to_lin(g) + 0.0722 * srgb_to_lin(b)


def contrast(fg, bg):
    l1, l2 = rel_luminance(fg), rel_luminance(bg)
    hi, lo = max(l1, l2), min(l1, l2)
    return (hi + 0.05) / (lo + 0.05)


DECL = re.compile(r'(--ss-[a-z0-9-]+)\s*:\s*([^;]+);')


def block_body(text, selector):
    """Return the concatenated bodies of every top-level block whose selector
    line matches `selector` exactly (brace-matched, so nested rules are safe)."""
    bodies, i = [], 0
    while True:
        i = text.find(selector, i)
        if i == -1:
            return '\n'.join(bodies)
        j = text.find('{', i)
        if j == -1:
            return '\n'.join(bodies)
        # Reject matches where the selector isn't immediately before the brace.
        between = text[i + len(selector):j]
        if between.strip() not in ('', ','):
            i += len(selector)
            continue
        depth, k = 1, j + 1
        while k < len(text) and depth:
            if text[k] == '{':
                depth += 1
            elif text[k] == '}':
                depth -= 1
            k += 1
        bodies.append(text[j + 1:k - 1])
        i = k


def scope_tokens(text, selectors):
    """Collect raw declarations for a list of selectors, later ones winning."""
    raw = {}
    for sel in selectors:
        for name, val in DECL.findall(block_body(text, sel)):
            raw[name] = val.strip()
    return raw


def resolve(name, raw, seen=None):
    """Follow var() chains down to a literal colour."""
    seen = seen or set()
    if name in seen or name not in raw:
        return None
    seen.add(name)
    val = raw[name]
    m = re.fullmatch(r'var\(\s*(--ss-[a-z0-9-]+)\s*\)', val)
    if m:
        return resolve(m.group(1), raw, seen)
    if re.fullmatch(r'#[0-9a-fA-F]{3,8}', val):
        return val
    return None


PAIRS = [
    ('--ss-fg', '--ss-bg', 'body text on page background', 4.5),
    ('--ss-fg', '--ss-surface', 'body text on card surface', 4.5),
    ('--ss-fg-muted', '--ss-surface', 'muted text on card surface', 4.5),
    ('--ss-fg-subtle', '--ss-surface', 'subtle/meta text on card', 4.5),
    ('--ss-fg-muted', '--ss-surface-sunken', 'table header on sunken fill', 4.5),
    ('--ss-on-brand', '--ss-brand', 'button label on brand fill', 4.5),
    ('--ss-on-brand', '--ss-brand-hover', 'button label on brand hover', 4.5),
    ('--ss-link', '--ss-surface', 'link on card surface', 4.5),
    ('--ss-brand', '--ss-surface', 'brand text/icon on card', 4.5),
    ('--ss-brand', '--ss-brand-subtle', 'brand on its tinted fill', 4.5),
    ('--ss-focus-ring', '--ss-bg', 'focus ring on page background', 3.0),
    ('--ss-focus-ring', '--ss-surface', 'focus ring on card surface', 3.0),
    ('--ss-border-strong', '--ss-surface', 'input border on card surface', 3.0),
    ('--ss-danger', '--ss-surface', 'danger text/icon on card', 4.5),
    ('--ss-on-danger', '--ss-danger', 'label on SOLID danger fill', 4.5),
    ('--ss-success-fg', '--ss-success-bg', 'success badge text on fill', 4.5),
    ('--ss-warning-fg', '--ss-warning-bg', 'warning badge text on fill', 4.5),
    ('--ss-danger-fg', '--ss-danger-bg', 'danger badge text on fill', 4.5),
    ('--ss-info-fg', '--ss-info-bg', 'info badge text on fill', 4.5),
    ('--ss-neutral-fg', '--ss-neutral-bg', 'neutral badge text on fill', 4.5),
]

THEMES = [
    ('LIGHT', [':root']),
    ('DARK', [':root', '[data-ss-theme="dark"]']),
]


def run(css_path):
    text = css_path.read_text(encoding='utf-8')
    # Comments out first: prose such as "--ss-bg: a card must..." inside a
    # comment would otherwise parse as a declaration.
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    failures = []

    for label, selectors in THEMES:
        raw = scope_tokens(text, selectors)
        print(f'\n=== {label} THEME ===')
        print(f'{"result":7}{"ratio":>9}{"min":>6}  {"fg":9} {"bg":9} pair')
        for fg, bg, desc, minimum in PAIRS:
            fv, bv = resolve(fg, raw), resolve(bg, raw)
            if not fv or not bv:
                print(f'{"MISSING":7}{"--":>9}{minimum:6.1f}  {str(fv):9} {str(bv):9} {desc}')
                failures.append((label, desc, 'unresolved'))
                continue
            r = contrast(fv, bv)
            ok = r >= minimum
            if not ok:
                failures.append((label, desc, f'{r:.2f}:1 < {minimum}'))
            print(f'{"PASS" if ok else "FAIL":7}{r:8.2f}:1{minimum:6.1f}  {fv:9} {bv:9} {desc}')

    # The auto scope (prefers-color-scheme) must carry exactly the dark values.
    # CSS cannot share one block between the two selectors, so it is a copy,
    # and a copy drifts unless something checks it.
    dark = scope_tokens(text, ['[data-ss-theme="dark"]'])
    auto = scope_tokens(text, ['[data-ss-theme="auto"]'])
    drift = sorted(k for k in set(dark) | set(auto) if dark.get(k) != auto.get(k))
    print('\n=== AUTO SCOPE vs DARK SCOPE ===')
    if drift:
        for k in drift:
            print(f'  DRIFT {k}: dark={dark.get(k)} auto={auto.get(k)}')
            failures.append(('AUTO', k, 'differs from dark'))
    else:
        print(f'  identical ({len(dark)} tokens)')

    print()
    if failures:
        print(f'{len(failures)} FAILURE(S):')
        for theme, desc, why in failures:
            print(f'  [{theme}] {desc}: {why}')
    else:
        print(f'ALL {len(PAIRS) * len(THEMES)} PAIRS PASS (light + dark)')
    return 1 if failures else 0


if __name__ == '__main__':
    p = pathlib.Path(sys.argv[1] if len(sys.argv) > 1
                     else 'public/css/store-syria-ds.css')
    sys.exit(run(p))
