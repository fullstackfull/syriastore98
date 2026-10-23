#!/usr/bin/env python3
"""Re-point docs/security/P01-OBSERVABILITY-INVENTORY.md at its log lines' CURRENT line numbers.

WHY THIS EXISTS, and it is a cost this project accepted on purpose. P1-PLT-05's inventory keys
each row on `file:line`, and `SecurityLogInventoryTest` ratchets the document against `app/` in
both directions. Its docblock says the line-number brittleness "is accepted HERE and nowhere
else, because the thing being ratcheted is a DOCUMENT that must be regenerated anyway when the
code moves: a line-number drift failing this test is the correct outcome, not a false positive."

That is still the right call -- an operator reading the inventory needs a pointer they can open --
but it had no regeneration path, so the first package to edit a file above a logged line paid for
it by hand. P1-ORD-15 was that package: replacing 24 city-resolution blocks shifted 14 rows, CI
caught it, and this script is what made the repair mechanical instead of a manual re-derivation.

HOW IT MATCHES, and what it refuses to guess. A row is identified by (file, message) -- the parts
that do not move -- and the line number is the thing being corrected. Where one message occurs
more than once in the same file, (file, message) cannot identify a row, so the Nth occurrence in
the document is mapped to the Nth in the source: an edit that SHIFTS lines does not REORDER them.
If the counts disagree -- a log line was added or deleted rather than moved -- it reports and
writes nothing, because that is a real inventory change and a human has to say where the new line
goes and who reads it.

    python3 docs/development/phase-1/tools/resync-observability-inventory.py            # dry run
    python3 docs/development/phase-1/tools/resync-observability-inventory.py --write

It edits ONLY the line numbers inside the backticked `app/...:N` cells. It never adds, removes or
reorders a row, and it never touches a message, a destination or any prose.
"""
import io, re, os, sys, collections

# The repository root, derived from this file's own location (tools/ -> phase-1/ -> development/
# -> docs/ -> root), so the script is not pinned to one machine's checkout path.
ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '..'))
DOC = os.path.join(ROOT, 'docs/security/P01-OBSERVABILITY-INVENTORY.md')
LEVELS = ['critical', 'warning']
PAT = re.compile(r"(?:\\?Log::|logger\(\)->)(" + "|".join(LEVELS) + r")\s*\(\s*(?:__\()?\s*['\"]([^'\"]{10,200})")

# --- the source census, same rule as the test ---
source = {}                        # "path:line" -> message
by_file_msg = collections.defaultdict(list)
for dirpath, _dirs, files in os.walk(os.path.join(ROOT, 'app')):
    for f in files:
        if not f.endswith('.php'):
            continue
        full = os.path.join(dirpath, f)
        rel = os.path.relpath(full, ROOT)
        for i, line in enumerate(io.open(full, encoding='utf-8', errors='replace').read().splitlines(), 1):
            m = PAT.search(line)
            if m:
                msg = m.group(2).replace('\\', '').strip()
                source['%s:%d' % (rel, i)] = msg
                by_file_msg[(rel, msg)].append(i)

# --- the document ---
doc_lines = io.open(DOC, encoding='utf-8').readlines()
row = re.compile(r'^\|\s*`(app/[^`]+):(\d+)`\s*\|\s*(.*?)\s*\|\s*$')

rewritten = 0
unchanged = 0
problems = []
documented = set()

# Group the DOCUMENT's rows the same way the source is grouped. Where a message occurs more than
# once in one file, (file, message) cannot identify a row -- but ORDER can: an edit that shifts
# lines does not reorder them, so the Nth occurrence in the document is the Nth in the source.
doc_rows = []                       # (doc_line_index, path, old_line, msg)
doc_groups = {}
for idx, line in enumerate(doc_lines):
    m = row.match(line)
    if not m:
        continue
    path, old_line, msg = m.group(1), int(m.group(2)), m.group(3).strip()
    doc_rows.append((idx, path, old_line, msg))
    doc_groups.setdefault((path, msg), []).append((old_line, idx))

for key, entries in sorted(doc_groups.items()):
    path, msg = key
    candidates = sorted(by_file_msg.get(key, []))
    entries = sorted(entries)                      # by the line number the document carries

    if len(candidates) != len(entries):
        problems.append('COUNT MISMATCH %s %r: document has %d row(s) at %s, source has %d line(s) at %s'
                        % (path, msg, len(entries), [e[0] for e in entries], len(candidates), candidates))
        continue

    for (old_line, idx), new_line in zip(entries, candidates):
        documented.add('%s:%d' % (path, new_line))
        if new_line != old_line:
            doc_lines[idx] = doc_lines[idx].replace('`%s:%d`' % (path, old_line),
                                                    '`%s:%d`' % (path, new_line), 1)
            rewritten += 1
        else:
            unchanged += 1

missing = sorted(set(source) - documented)

print('rows re-pointed : %d' % rewritten)
print('rows unchanged  : %d' % unchanged)
print('source lines    : %d' % len(source))
print('documented      : %d' % len(documented))
for p in problems:
    print('  PROBLEM  ' + p)
for k in missing:
    print('  NOT IN DOC  %s  %s' % (k, source[k]))

if '--write' in sys.argv and not problems and not missing:
    io.open(DOC, 'w', encoding='utf-8').writelines(doc_lines)
    print('WROTE ' + DOC)
elif '--write' in sys.argv:
    print('NOT WRITTEN: resolve the problems above first.')
