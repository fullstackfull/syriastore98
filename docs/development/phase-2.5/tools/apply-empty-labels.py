#!/usr/bin/env python3
"""
P2.5-WAVE-26 — give every resolvable `Form::label('')` the control it names.

PREMISE, VERIFIED IN THE RUNNING APPLICATION BEFORE ANY OF THIS RAN. Laravel Collective derives a
control's `id` from the labels it has seen, so `Form::label('')` leaves the control with NO id and
the label pointing at nothing. Naming the label creates BOTH halves. Measured on taxes/create
after Wave 25 repaired it by hand:

    <label for="name" class="form-label">Name</label>
    <input class="form-control" name="name" type="text" id="name">
    AX: role=textbox  name="Name"

Only sites the analyser marked AUTO, or AMBIGUOUS solely because the control carries an explicit
`id`, are touched. Everything else is left for a human read.
"""
import json, pathlib, re, sys

ROOT = pathlib.Path(__file__).resolve().parents[4]
DATA = ROOT / "docs/development/phase-2.5/tools/empty-labels.json"
if not DATA.exists():
    raise SystemExit("run analyse-empty-labels.py first")

rows = json.loads(DATA.read_text(encoding="utf-8"))
todo = {}
for r in rows:
    if r["verdict"] == "AUTO":
        todo.setdefault(r["file"], []).append((r["line"], r["target"], "auto"))
    elif r["verdict"] == "AMBIGUOUS" and r.get("why", "").startswith("explicit id"):
        todo.setdefault(r["file"], []).append((r["line"], r["target"], "explicit-id"))

EMPTY = re.compile(r"(Form::label\(\s*)''(\s*,)")
changed = skipped = 0
for rel, sites in sorted(todo.items()):
    path = ROOT / rel
    lines = path.read_text(encoding="utf-8").splitlines(keepends=True)
    for line_no, target, how in sites:
        i = line_no - 1
        if i >= len(lines) or not EMPTY.search(lines[i]):
            print(f"  SKIP {rel}:{line_no} — the line no longer matches")
            skipped += 1
            continue
        lines[i] = EMPTY.sub(lambda m: f"{m.group(1)}'{target}'{m.group(2)}", lines[i], count=1)
        changed += 1
    path.write_text("".join(lines), encoding="utf-8")
    print(f"  {rel}: {len(sites)} site(s)")

print(f"\nchanged {changed}, skipped {skipped}, across {len(todo)} files")
