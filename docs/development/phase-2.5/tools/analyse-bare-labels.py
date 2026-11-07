#!/usr/bin/env python3
"""
P2.5 — count the `<label>` tags that genuinely name nothing.

A RAW GREP OVER-COUNTS, and this project has already been bitten once today by a census that
flagged `<input type="submit">` as unlabelled when its `value` names it. Two shapes are VALID
without a `for`:

  - a label that WRAPS its control: `<label><input type="checkbox"> Enabled</label>`
  - a label that carries `aria-label`/`aria-labelledby` of its own

So this reads each `<label ...>` and looks for a control between it and its `</label>`. Only the
ones with neither a `for` nor a wrapped control are reported.
"""
import re, pathlib, json, collections

ROOT = pathlib.Path(__file__).resolve().parents[4]
VIEWS = ROOT / "resources" / "views"
if not VIEWS.is_dir():
    raise SystemExit(f"resources/views not found under {ROOT}")

LABEL_OPEN = re.compile(r"<label\b([^>]*)>", re.I)
HAS_FOR = re.compile(r"\bfor\s*=\s*[\"'][^\"']+[\"']", re.I)
HAS_ARIA = re.compile(r"\baria-label(ledby)?\s*=", re.I)
CONTROL = re.compile(r"<(input|select|textarea)\b|Form::(text|email|password|number|date|checkbox|radio|select|textarea|file)\b", re.I)
COMMENT = re.compile(r"\{\{--.*?--\}\}", re.S)

rows = []
for path in sorted(VIEWS.rglob("*.blade.php")):
    rel = str(path.relative_to(ROOT))
    raw = COMMENT.sub(lambda m: "\n" * m.group(0).count("\n"),
                      path.read_text(encoding="utf-8", errors="replace"))
    for m in LABEL_OPEN.finditer(raw):
        attrs = m.group(1)
        if HAS_FOR.search(attrs) or HAS_ARIA.search(attrs):
            continue
        close = raw.find("</label>", m.end())
        inner = raw[m.end():close] if close != -1 else raw[m.end():m.end() + 400]
        wraps = bool(CONTROL.search(inner))
        line = raw[:m.start()].count("\n") + 1
        caption = re.search(r"__\('([^']{1,40})'\)", inner)
        # OUT-OF-PHASE FAMILIES, classified the way ViewportAllowsZoomTest classifies them, so
        # the Phase 2.5 figure is the merchant admin and not a number inflated by other phases'
        # screens. Phase 03 owns the storefront; Phase 06 owns plan purchase and the marketplace;
        # the gateway hand-off documents belong to the gateway's own flow.
        if "/front_end/" in rel:
            area = "storefront (Phase 03)"
        elif "/plans/" in rel or "/marketplace/" in rel:
            area = "plans and marketplace (Phase 06)"
        elif "/DPO/" in rel or "/payment/" in rel:
            area = "gateway hand-off"
        else:
            area = "merchant"
        rows.append({"file": rel, "line": line, "wraps_a_control": wraps, "area": area,
                     "caption": caption.group(1) if caption else inner.strip()[:28]})

bad = [r for r in rows if not r["wraps_a_control"]]
merchant_bad = [r for r in bad if r["area"] == "merchant"]
by_area = collections.Counter(r["area"] for r in bad)
print(f"<label> tags with no for= and no aria-*: {len(rows)}")
print(f"  of those, WRAPPING their control (valid): {len(rows) - len(bad)}")
print(f"  genuinely naming nothing:                {len(bad)}")
for a, n in by_area.most_common():
    print(f"     {a:<34} {n}")
print()
by = collections.Counter(r["file"] for r in merchant_bad)
print("merchant files, worst first:")
for f, n in by.most_common(12):
    print(f"  {n:>3}  {f.replace('resources/views/','')}")
# PROPOSE A TARGET for the merchant ones: the first control after the label that carries an id.
# Nothing is applied here -- the empty-label wave proved that a window too narrow produces wrong
# targets, so this reports and a human decides.
ID_ATTR = re.compile(r"""\bid\s*=\s*["']([^"']+)["']""")
for r in merchant_bad:
    raw = COMMENT.sub(lambda m: "\n" * m.group(0).count("\n"),
                      (ROOT / r["file"]).read_text(encoding="utf-8", errors="replace"))
    lines = raw.splitlines()
    i = r["line"] - 1
    target, why = None, "no control with an id within 8 lines"
    for j in range(i, min(i + 9, len(lines))):
        if j > i and LABEL_OPEN.search(lines[j]):
            why = "another label comes first — this is a group caption"
            break
        m = CONTROL.search(lines[j])
        if not m:
            continue
        eid = ID_ATTR.search(lines[j])
        if eid:
            target, why = eid.group(1), "ok"
        else:
            why = "the control carries no id of its own"
        break
    r["target"], r["why"] = target, why

ok = [r for r in merchant_bad if r.get("target")]
print(f"\nof the {len(merchant_bad)} merchant sites, {len(ok)} have a control with an id right after them")
rest = collections.Counter(r["why"] for r in merchant_bad if not r.get("target"))
for w, n in rest.most_common():
    print(f"   {n:>3}  {w}")

(ROOT / "docs/development/phase-2.5/tools/bare-labels.json").write_text(
    json.dumps(merchant_bad, indent=1, ensure_ascii=False), encoding="utf-8")
print(f"\nwritten: docs/development/phase-2.5/tools/bare-labels.json")
