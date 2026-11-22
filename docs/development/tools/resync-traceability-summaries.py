#!/usr/bin/env python3
"""Recompute the summary tables of the two traceability documents FROM THEIR OWN ROWS.

    python3 docs/development/tools/resync-traceability-summaries.py            # report only
    python3 docs/development/tools/resync-traceability-summaries.py --write    # repair

WHY THIS EXISTS. Both documents carry a summary table that a reader quotes instead of counting
350 sections or 181 rows, and both tables have drifted from their rows at least once:

  * the MATRIX, 2026-11-20 -- three rows re-traced from NOT to PARTIAL, the table never updated;
  * the REGISTER, the same day -- GAP-160 closed with a one-off status label, and the table went on
    counting it as NOT IMPLEMENTED for two days. The TOTAL stayed right, which is why nobody saw it.

`MasterPlanTraceabilityCountsTest` and `GapRegisterConsistencyTest` DETECT that drift. This repairs
it, so that changing a row's status is one edit and one command rather than one edit and several
hand-recounted numbers -- which is the step that was skipped both times.

THE ROWS ARE THE SOURCE OF TRUTH. This never changes a row. It rewrites only the count cells of
the two summary tables and the matrix's one-line headline, and it refuses to write if a row
carries a status the table has no line for: a new status is a decision, not a recount.

TWO MORE DERIVED FIGURES WERE ADDED ON 2026-11-22, the first time this tool was used in anger.
Re-tracing `P05-14` down from VERIFIED COMPLETE repaired the status table and the headline and
left two numbers behind, both derived from the same rows and both unchecked:

  * the PER-PHASE table, which still credited Phase 05/06 with three verified rows;
  * the PROJECT-WIDE sentence under the headline -- the matrix total plus the requirements
    recovered later and held in the register -- which still said 172 of 368.

A tool that repairs some of a document's summaries is a way to be confident and wrong, so it now
repairs all four. An AGGREGATE row (a section that summarises other requirements rather than being
one) is counted in neither, exactly as `MasterPlanTraceabilityCountsTest` counts.
"""
import io
import re
import sys
from collections import Counter

MATRIX = 'docs/development/MASTER-PLAN-REQUIREMENT-TRACEABILITY.md'
REGISTER = 'docs/development/MASTER-PLAN-GAP-REGISTER.md'


def matrix(write: bool) -> list[str]:
    doc = io.open(MATRIX, encoding='utf-8').read()
    body = Counter(s.strip() for s in re.findall(r'^\*\*Status: ([^*]+)\*\*', doc, re.M))
    table = dict((m.group(1), int(m.group(2)))
                 for m in re.finditer(r'^\| ([A-Z][A-Z \-]+) \| \*\*(\d+)\*\* \|$', doc, re.M))
    changes, out = [], doc

    for status, declared in table.items():
        actual = body.get(status, 0)
        if actual != declared:
            changes.append(f'matrix   {status}: table {declared} -> rows {actual}')
            out = re.sub(r'^\| ' + re.escape(status) + r' \| \*\*\d+\*\* \|$',
                         f'| {status} | **{actual}** |', out, flags=re.M)

    table_after = {s: body.get(s, 0) for s in table}
    total = sum(table_after.values())
    verified = table_after.get('VERIFIED COMPLETE', 0)
    headline = f'**{total} requirements recovered. {verified} VERIFIED COMPLETE. {total - verified} are not.**'
    found = re.search(r'\*\*\d+ requirements recovered\. \d+ VERIFIED COMPLETE\. \d+ are not\.\*\*', out)
    if found is None:
        sys.exit('matrix: the narrative headline could not be found; refusing to guess where it goes.')
    if found.group(0) != headline:
        changes.append(f'matrix   headline: "{found.group(0)}" -> "{headline}"')
        out = out.replace(found.group(0), headline)

    # ---- the per-phase table: one row per `## Phase` section, requirement rows only ----------
    headings = [(m.start(), m.group(1)) for m in re.finditer(r'^## (.+)$', out, re.M)]
    sections = []
    for index, (offset, title) in enumerate(headings):
        if not title.startswith('Phase '):
            continue
        end = headings[index + 1][0] if index + 1 < len(headings) else len(out)
        statuses = [s.strip() for s in re.findall(r'^\*\*Status: ([^*]+)\*\*', out[offset:end], re.M)]
        statuses = [s for s in statuses if s in table]          # an aggregate row is not a requirement
        sections.append((title[len('Phase '):], len(statuses), statuses.count('VERIFIED COMPLETE')))

    phase_rows = list(re.finditer(r'^\| (\d[^|]*?) \| (\d+) \| (\d+) \| \*\*(\d+)\*\* \|$', out, re.M))
    if len(phase_rows) != len(sections):
        sys.exit(f'matrix: the per-phase table has {len(phase_rows)} rows for {len(sections)} '
                 '`## Phase` sections; refusing to guess which is which.')

    # Rewritten last-to-first so earlier match offsets stay valid.
    for found_row, (title, count, done) in reversed(list(zip(phase_rows, sections))):
        label = found_row.group(1)
        if not title.startswith(label):
            sys.exit(f"matrix: per-phase row '{label}' is not the section in its position ('{title}').")
        declared = tuple(int(found_row.group(i)) for i in (2, 3, 4))
        actual = (count, done, count - done)
        if declared != actual:
            changes.append(f'matrix   phase "{label[:28]}": table {declared} -> rows {actual}')
            line = f'| {label} | {count} | {done} | **{count - done}** |'
            out = out[:found_row.start()] + line + out[found_row.end():]

    # ---- the project-wide sentence: the matrix plus the requirements recovered later ---------
    plus = re.search(r'\*\*Plus (\d+) recovered on ', out)
    sentence = re.search(r'\*\*\d+ known requirements, \d+ verified complete, \d+ not\.\*\*', out)
    if plus is None or sentence is None:
        sys.exit('matrix: the project-wide sentence could not be found; refusing to guess where it goes.')
    known = total + int(plus.group(1))
    wanted = f'**{known} known requirements, {verified} verified complete, {known - verified} not.**'
    if sentence.group(0) != wanted:
        changes.append(f'matrix   project-wide: "{sentence.group(0)}" -> "{wanted}"')
        out = out.replace(sentence.group(0), wanted)

    if write and out != doc:
        io.open(MATRIX, 'w', encoding='utf-8').write(out)
    return changes


def register(write: bool) -> list[str]:
    doc = io.open(REGISTER, encoding='utf-8').read()
    body = Counter()
    for line in doc.split('\n'):
        # Six cells is the status schema; the later "measured" rows have no status column.
        if re.match(r'^\| `GAP-\d+` \|', line) and len(line.split(' | ')) == 6:
            body[line.split(' | ')[3].strip()] += 1

    found = re.search(r'(\| status \| count \|\n\|[-| ]+\|\n)((?:\|.*\n)+)', doc)
    if found is None:
        sys.exit('register: the status table could not be found.')

    table = {}
    for line in found.group(2).strip().split('\n'):
        cells = [c.strip() for c in line.strip('|').split('|')]
        table[cells[0]] = int(cells[1])

    unknown = sorted(set(body) - set(table))
    if unknown:
        sys.exit('register: rows carry a status the summary table has no line for: '
                 + ', '.join(unknown) + '. A new status is a decision; add its line by hand.')

    changes, lines = [], []
    for status, declared in table.items():
        actual = body.get(status, 0)
        if actual != declared:
            changes.append(f'register {status}: table {declared} -> rows {actual}')
        lines.append(f'| {status} | {actual} |')

    out = doc.replace(found.group(2), '\n'.join(lines) + '\n')
    if write and out != doc:
        io.open(REGISTER, 'w', encoding='utf-8').write(out)
    return changes


if __name__ == '__main__':
    write = '--write' in sys.argv[1:]
    changes = matrix(write) + register(write)
    for change in changes:
        print(('repaired ' if write else 'STALE    ') + change)
    if not changes:
        print('both summary tables agree with their rows')
    sys.exit(0 if (write or not changes) else 1)
