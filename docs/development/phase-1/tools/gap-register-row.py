#!/usr/bin/env python3
"""
Append or amend a gap-register row WITHOUT the hand-written cell separator that has now bitten
three times.

WHY THIS EXISTS. The register's measured-schema rows are `| id | ids | phase | body | next |` --
FIVE cells. Writing a row by hand means typing ` | ` between body and next-action, and three times
in one session I also typed one INSIDE the body, which makes a six-cell row. Six cells is the
STATUS schema, so `GapRegisterConsistencyTest` then reads the body's opening phrase as a status
term, finds it is not in the controlled vocabulary, and refuses -- correctly, and with a message
about something that is not what went wrong. The gate caught all three; the cost was mine.

So the separator is never typed here. Cells go in as a list and this joins them.

    python3 addrow.py append  <register.md> <id> <ids> <phase> <body-file> <next-file>
    python3 addrow.py amend   <register.md> <id> <body-file> <next-file>

`amend` replaces an existing row's body and next-action cells and leaves its id/ids/phase alone.
Both verify the result is five cells before writing, and both refuse if a cell contains ' | '.
"""
import sys, re

def cells_ok(parts):
    bad = [i for i, c in enumerate(parts) if ' | ' in c]
    if bad:
        sys.exit(f'REFUSED: cell(s) {bad} contain " | ", which would split the row. '
                 'That is the mistake this script exists to prevent.')

def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)

    mode = sys.argv[1]
    path = sys.argv[2]
    s = open(path).read()
    if mode == 'append':
        gid, ids, phase, bodyf, nextf = sys.argv[3:8]
        body, nxt = open(bodyf).read().strip(), open(nextf).read().strip()
        parts = [f'| `{gid}`', ids, phase, body, nxt]
        cells_ok(parts[1:])
        row = ' | '.join(parts) + ' |'
        if len(row.split(' | ')) != 5:
            sys.exit(f'REFUSED: built {len(row.split(" | "))} cells, want 5.')
        open(path, 'w').write(s.rstrip('\n') + '\n' + row + '\n')
        print(f'{gid} appended, 5 cells')
    elif mode == 'amend':
        gid, bodyf, nextf = sys.argv[3:6]
        body, nxt = open(bodyf).read().strip(), open(nextf).read().strip()
        cells_ok([body, nxt])
        out, hits = [], 0
        for line in s.split('\n'):
            if line.startswith(f'| `{gid}` |'):
                old = line.split(' | ')
                line = ' | '.join(old[:3] + [body, nxt]) + ' |'
                if len(line.split(' | ')) != 5:
                    sys.exit(f'REFUSED: built {len(line.split(" | "))} cells, want 5.')
                hits += 1
            out.append(line)
        if hits != 1:
            sys.exit(f'REFUSED: matched {hits} rows for {gid}, want exactly 1.')
        open(path, 'w').write('\n'.join(out))
        print(f'{gid} amended, 5 cells')
    else:
        sys.exit(__doc__)

main()
