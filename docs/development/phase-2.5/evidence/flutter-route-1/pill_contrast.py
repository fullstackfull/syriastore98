# Validate or discard the earlier sub-3.0:1 pill readings.
#
# The earlier probe ranked candidate "glyph" colours by pixel frequency inside a
# box and took the worst ratio above an arbitrary 1.8 floor. That cannot tell a
# glyph from an anti-aliased edge, and it reported #A0B3D6 (a light blue-grey)
# as the glyph colour of a pill whose declared foreground is #165E34 (dark
# green) -- so the candidate was not even inside the pill.
#
# This probe does two independent things:
#   A. computes the ratio from the DECLARED colours in status_pill.dart,
#      compositing the 9%-alpha tint over the real card colour. That is what
#      WCAG 1.4.3 measures: specified foreground against specified background.
#   B. finds the pill in the captured PNG by its exact composited background
#      value, restricts to that connected region ONLY, and reports the extreme
#      pixel. If the declared foreground appears there, the render matches the
#      declaration and (A) is the answer.
import sys, struct, zlib, collections

def srgb_lin(c):
    c = c / 255.0
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4

def lum(rgb):
    r, g, b = (srgb_lin(x) for x in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b

def ratio(a, b):
    la, lb = lum(a), lum(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)

def over(fg, alpha, bg):
    return tuple(round(fg[i] * alpha + bg[i] * (1 - alpha)) for i in range(3))

def hx(c):
    return '#%02X%02X%02X' % c

def read_png(path):
    d = open(path, 'rb').read()
    assert d[:8] == b'\x89PNG\r\n\x1a\n'
    i, idat, w, h, bd, ct = 8, b'', 0, 0, 0, 0
    while i < len(d):
        ln = struct.unpack('>I', d[i:i+4])[0]
        typ = d[i+4:i+8]
        body = d[i+8:i+8+ln]
        if typ == b'IHDR':
            w, h, bd, ct = struct.unpack('>IIBB', body[:10])
        elif typ == b'IDAT':
            idat += body
        i += 12 + ln
    assert bd == 8 and ct in (2, 6), (bd, ct)
    nch = 3 if ct == 2 else 4
    raw = zlib.decompress(idat)
    stride = w * nch
    out, prev = [], bytearray(stride)
    pos = 0
    for _ in range(h):
        ft = raw[pos]; pos += 1
        line = bytearray(raw[pos:pos+stride]); pos += stride
        if ft == 1:
            for x in range(nch, stride): line[x] = (line[x] + line[x-nch]) & 255
        elif ft == 2:
            for x in range(stride): line[x] = (line[x] + prev[x]) & 255
        elif ft == 3:
            for x in range(stride):
                a = line[x-nch] if x >= nch else 0
                line[x] = (line[x] + ((a + prev[x]) >> 1)) & 255
        elif ft == 4:
            for x in range(stride):
                a = line[x-nch] if x >= nch else 0
                b = prev[x]; c = prev[x-nch] if x >= nch else 0
                p = a + b - c
                pa, pb, pc = abs(p-a), abs(p-b), abs(p-c)
                pr = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[x] = (line[x] + pr) & 255
        out.append(bytes(line)); prev = line
    return w, h, nch, out

WHITE = (0xFF, 0xFF, 0xFF)
DARK_CARD = (0x18, 0x26, 0x3F)   # DarkThemeColor.cCardBackground, colors.dart:47
TINT_ACTIVE = (0x00, 0xB8, 0x94)
TINT_INACTIVE = (0xFF, 0x5C, 0x5C)
ALPHA = 0.09
FG = {
    ('active', 'light'):   (0x16, 0x5E, 0x34),
    ('inactive', 'light'): (0x8C, 0x1D, 0x17),
    ('active', 'dark'):    (0x7F, 0xD6, 0xA0),
    ('inactive', 'dark'):  (0xF3, 0xA9, 0xA4),
}

print('=== A. declared colours (status_pill.dart) ===')
declared = {}
for state, tint in (('active', TINT_ACTIVE), ('inactive', TINT_INACTIVE)):
    for theme, card in (('light', WHITE), ('dark', DARK_CARD)):
        bg = over(tint, ALPHA, card)
        fg = FG[(state, theme)]
        r = ratio(fg, bg)
        declared[(state, theme)] = (bg, fg, r)
        # 9sp bold ~= 12px bold at dpr 1.33 -> NOT large text, so the 4.5:1 threshold applies
        print('  %-8s %-5s  bg %s (tint over card)  fg %s  ratio %.2f:1  %s'
              % (state, theme, hx(bg), hx(fg), r, 'PASS' if r >= 4.5 else 'FAIL (needs 4.5:1)'))

print()
print()
print('=== B. the captured PNG, restricted to the pill region ===')
import os
GOLD = '/home/user/StoreSyria_app98/test/golden/goldens'
# customer fixture status == 1 -> ACTIVE; delivery-boy fixture -> whichever the
# capture rendered. Detect which tint is present rather than assume.
cases = []
for f in sorted(os.listdir(GOLD)):
    if f.endswith('.png'):
        theme = 'dark' if '.dark' in f else 'light'
        cases.append((os.path.join(GOLD, f), f, theme))

for path, name, theme in cases:
    w, h, nch, rows = read_png(path)
    card = DARK_CARD if theme == 'dark' else WHITE
    found = None
    for state, tint in (('active', TINT_ACTIVE), ('inactive', TINT_INACTIVE)):
        target = over(tint, ALPHA, card)
        # count exact matches; the pill fill is a flat region so it is the only
        # place this value occurs in quantity
        n = 0
        xs, ys = [], []
        for y in range(h):
            r = rows[y]
            for x in range(w):
                px = (r[x*nch], r[x*nch+1], r[x*nch+2])
                if px == target:
                    n += 1
                    xs.append(x); ys.append(y)
        if n > 50:
            found = (state, target, n, min(xs), max(xs), min(ys), max(ys))
            break
    if not found:
        print('  %-32s  pill fill NOT FOUND at either declared tint' % name)
        continue
    state, target, n, x0, x1, y0, y1 = found
    fg = FG[(state, theme)]
    # every pixel inside the pill's bounding box, excluding the fill itself
    inside = collections.Counter()
    for y in range(y0, y1+1):
        r = rows[y]
        for x in range(x0, x1+1):
            px = (r[x*nch], r[x*nch+1], r[x*nch+2])
            if px != target:
                inside[px] += 1
    # the extreme: for a light theme the darkest, for dark the lightest
    if not inside:
        print('  %-32s  pill found, no non-fill pixel inside' % name)
        continue
    key = (lambda c: lum(c)) if theme == 'light' else (lambda c: -lum(c))
    extreme = min(inside, key=key)
    exact = inside.get(fg, 0)
    r_ext = ratio(extreme, target)
    r_dec = declared[(state, theme)][2]
    print('  %-32s %-8s fill %s (%d px, box %dx%d)' %
          (name, state, hx(target), n, x1-x0+1, y1-y0+1))
    print('       declared fg %s  ratio %.2f:1 | fully-covered fg pixels in box: %d'
          % (hx(fg), r_dec, exact))
    print('       extreme pixel in box %s  ratio %.2f:1  (%d px)'
          % (hx(extreme), r_ext, inside[extreme]))
