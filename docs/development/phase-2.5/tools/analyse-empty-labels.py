#!/usr/bin/env python3
"""
P2.5-WAVE-26 — propose a `for` target for every `Form::label('')` in the merchant views.

`Form::label('')` renders `<label for="">`, which associates the label with NOTHING. Laravel
Collective derives a control's id from its NAME unless an explicit `id` is given in the options
array, so the repair is normally `Form::label('<the next control's name>', ...)`.

NORMALLY. This script does not guess: it reports what it found and marks anything it cannot
settle as AMBIGUOUS for a human to read. The three cases it refuses to decide are:
  - no form control between this label and the next label,
  - the control carries an explicit 'id' that differs from its name,
  - more than one control before the next label (a control GROUP, which needs a fieldset or an
    aria-label rather than a `for`).
"""
import re, sys, pathlib, json

ROOT = pathlib.Path(__file__).resolve().parents[4]
VIEWS = ROOT / "resources" / "views"
if not VIEWS.is_dir():
    raise SystemExit(f"resources/views not found under {ROOT} — fix the path depth")

LABEL = re.compile(r"\{\{!?!?\s*Form::label\(\s*''\s*,|\{!!\s*Form::label\(\s*''\s*,")
ANY_LABEL = re.compile(r"Form::label\(|<label")
CONTROL = re.compile(
    r"Form::(text|email|password|number|date|datetime|time|textarea|select|file|hidden|checkbox|radio|color|url|tel)"
    r"\(\s*'([^']+)'", re.I)
EXPLICIT_ID = re.compile(r"'id'\s*=>\s*'([^']+)'")
COMMENT = re.compile(r"\{\{--.*?--\}\}", re.S)

rows = []
for path in sorted(VIEWS.rglob("*.blade.php")):
    if "/front_end/" in str(path):
        continue
    raw = path.read_text(encoding="utf-8", errors="replace")
    code = COMMENT.sub(lambda m: "\n" * m.group(0).count("\n"), raw)   # keep line numbers
    lines = code.splitlines()
    for i, line in enumerate(lines):
        if not LABEL.search(line):
            continue
        # look ahead to the next label; every control before it is a candidate
        controls = []
        for j in range(i + 1, min(i + 14, len(lines))):
            if ANY_LABEL.search(lines[j]):
                break
            for m in CONTROL.finditer(lines[j]):
                # THE OPTIONS ARRAY OFTEN SPANS SEVERAL LINES. The first version of this read only
                # `lines[j]`, so `'id' => 'brand-dropdown'` two lines down was invisible and the
                # site was classified AUTO. MEASURED consequence: three labels were given a `for`
                # that pointed at nothing -- brand_id (real id `brand-dropdown`), label_id
                # (`label-dropdown`) and tax_status (`tax_id`) on product/create and product/edit.
                # The window closes at the call's own terminator, or after eight lines.
                window = []
                for k in range(j, min(j + 9, len(lines))):
                    window.append(lines[k])
                    if re.search(r"\]\s*\)\s*(!!\}|\}\})|\)\s*(!!\}|\}\})", lines[k]):
                        break
                eid = EXPLICIT_ID.search("\n".join(window))
                controls.append({"kind": m.group(1), "name": m.group(2),
                                 "explicit_id": eid.group(1) if eid else None, "line": j + 1})
        caption = re.search(r"__\('([^']*)'\)", line)
        row = {"file": str(path.relative_to(ROOT)), "line": i + 1,
               "caption": caption.group(1) if caption else "", "controls": controls}
        if len(controls) == 1 and not controls[0]["explicit_id"]:
            row["verdict"] = "AUTO"; row["target"] = controls[0]["name"]
        elif len(controls) == 1 and controls[0]["explicit_id"]:
            row["verdict"] = "AMBIGUOUS"; row["why"] = "explicit id " + controls[0]["explicit_id"]
            row["target"] = controls[0]["explicit_id"]
        elif not controls:
            row["verdict"] = "AMBIGUOUS"; row["why"] = "no control found before the next label"
        else:
            row["verdict"] = "AMBIGUOUS"; row["why"] = f"{len(controls)} controls — a group"
        rows.append(row)

auto = [r for r in rows if r["verdict"] == "AUTO"]
amb = [r for r in rows if r["verdict"] == "AMBIGUOUS"]
print(f"empty-label sites: {len(rows)}   AUTO: {len(auto)}   AMBIGUOUS: {len(amb)}")
print(f"files: {len({r['file'] for r in rows})}\n")
print("AMBIGUOUS, which need a human read:")
for r in amb:
    print(f"  {r['file']}:{r['line']:<5} {r['caption'][:26]:<28} {r['why']}")
(ROOT / "docs/development/phase-2.5/tools/empty-labels.json").write_text(
    json.dumps(rows, indent=1, ensure_ascii=False), encoding="utf-8")
print(f"\nwritten: docs/development/phase-2.5/tools/empty-labels.json")
