#!/usr/bin/env python3
"""Maintain ONE ledger block / JSON entry / GitHub issue body at a time.

WHY THIS EXISTS. `render-ledger.py` re-renders the WHOLE ledger from the JSON and does not reproduce the committed file (measured at
HEAD b0911661: ~440 changed lines), so running it wholesale rewrites evidence the ledger carries. This tool renders a single task in
the committed block shape and touches nothing else. Run `check` first: it tells you which blocks it reproduces byte-for-byte.

    python3 docs/development/phase-1/tools/ledger-tool.py check
    python3 docs/development/phase-1/tools/ledger-tool.py amend P1-SEC-30 '{"status":"DONE","evidence":"..."}'
    python3 docs/development/phase-1/tools/ledger-tool.py counts
    python3 docs/development/phase-1/tools/ledger-tool.py issue-body P1-SEC-30      # text for `issue_write`

`amend` takes a JSON object of fields to REPLACE (scalars, lists, strings). To append to a string field, read the old value from
phase-01-tasks.json first. After `amend`, run `counts` (the ledger header carries the totals) and commit the .md and .json together.
Adding a brand-new task is a manual edit: append the JSON entry, then insert `block <ID>` output (below) at the end of its workstream
section of the ledger and append a line to the ledger's Execution order list.

    python3 docs/development/phase-1/tools/ledger-tool.py block P1-SEC-30       # print the block for a task

Nothing here talks to GitHub. Issue labels and bodies are changed with the GitHub tools (status labels: status:ready|blocked|backlog|
in-progress|in-review|done; plus phase-01, ws:<workstream>, risk:<level>).
"""
import collections
import io
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PHASE = os.path.dirname(HERE)
LEDGER = os.path.join(PHASE, '12-PHASE-01-TASK-LEDGER.md')
INDEX = os.path.join(PHASE, 'phase-01-tasks.json')

WS_LABEL = {'orders': 'Orders & Checkout', 'security': 'Security & Identity', 'platform': 'Platform Foundation',
            'inventory': 'Inventory & Stock', 'payments': 'Payments & Financial Integrity',
            'migrations': 'Schema & Migration Readiness', 'design-system': 'Design System (foundation only)'}
WS_LABEL_SHORT = {'orders': 'ws:orders', 'security': 'ws:security', 'platform': 'ws:platform', 'inventory': 'ws:inventory',
                  'payments': 'ws:payments', 'migrations': 'ws:migrations', 'design-system': 'ws:design-system'}
BASE = 'https://github.com/fullstackfull/syriastore98/blob/claude/store-syria-p01a-ci-foundation/docs/development/phase-1/'
LEDGER_URL, JSON_URL, PROTO_URL = BASE + '12-PHASE-01-TASK-LEDGER.md', BASE + 'phase-01-tasks.json', BASE + '13-PHASE-01-EXECUTION-PROTOCOL.md'


def load():
    d = json.loads(io.open(INDEX, encoding='utf-8').read(), object_pairs_hook=collections.OrderedDict)
    return d, io.open(LEDGER, encoding='utf-8').read()


def _deps(t):
    return ', '.join('`%s`' % x for x in t['depends_on']) or '—'


def ledger_block(t):
    tick = t['status'] == 'DONE'
    L = ['### `%s` — %s' % (t['id'], t['title']), '', '| field | value |', '|---|---|',
         '| Status | **%s** |' % t['status'], '| Risk | %s |' % t['risk'], '| Workstream | %s |' % WS_LABEL[t['workstream']],
         '| GitHub issue | %s |' % (('#%s' % t['issue']) if t.get('issue') else '_pending_'),
         '| Owner decision required | %s |' % ('**yes**' if t['owner_decision'] else 'no')]
    if t.get('blocked_on'):
        L.append('| Blocked on | %s |' % t['blocked_on'])
    L += ['| Depends on | %s |' % _deps(t), '| Scope | %s |' % ', '.join('`%s`' % s for s in t['scope']),
          '| Queue references | %s |' % ', '.join(t['queue_refs']), '| Database / migration | %s |' % t['db'],
          '| Compatibility impact | %s |' % t['compatibility'], '', '**Existing implementation status.** %s' % t['existing_status'], '',
          '**Acceptance criteria.**']
    L += ['- [%s] %s' % ('x' if tick else ' ', a) for a in t['acceptance']]
    L += ['', '**Required tests.**'] + ['- %s' % x for x in t['tests']] + ['', '**Evidence.** %s' % t['evidence'], '']
    return '\n'.join(L)


def issue_body(t):
    L = ['**Task `%s` · Phase 01 · %s**' % (t['id'], WS_LABEL[t['workstream']]), '', '| field | value |', '|---|---|',
         '| Status | **%s** |' % t['status'], '| Risk | %s |' % t['risk'],
         '| Owner decision required | %s |' % ('**yes**' if t['owner_decision'] else 'no')]
    if t.get('blocked_on'):
        L.append('| Blocked on | %s |' % t['blocked_on'])
    L += ['| Depends on | %s |' % _deps(t), '| Scope | %s |' % ', '.join('`%s`' % s for s in t['scope']),
          '| Queue references | %s |' % ', '.join(t['queue_refs']), '| Database / migration | %s |' % t['db'],
          '| Compatibility impact | %s |' % t['compatibility'], '', '### Existing implementation status', '', t['existing_status'], '',
          '### Acceptance criteria', ''] + ['- [ ] %s' % a for a in t['acceptance']] + ['', '### Required tests', ''] \
        + ['- %s' % x for x in t['tests']] + ['', '### Evidence', '', t['evidence'], '', '---', '',
                                               'Ledger row: [`12-PHASE-01-TASK-LEDGER.md`](%s) · source of truth: [`phase-01-tasks.json`](%s) · protocol: [`13-PHASE-01-EXECUTION-PROTOCOL.md`](%s)' % (LEDGER_URL, JSON_URL, PROTO_URL),
          '', '_Status here and in the ledger must agree. `DONE` requires verified acceptance criteria, not a commit._', '', '',
          '---', '_Generated by [Claude Code](https://claude.ai/code)_']
    return '\n'.join(L)


def block_span(led, tid, required=True):
    m = re.search(r'^### `%s` — .*?(?=^### `|^---$)' % re.escape(tid), led, re.S | re.M)
    if not m:
        if required:
            sys.exit('no ledger block for ' + tid)
        return None
    return m.start(), m.end()


def counts(d, led):
    c = collections.Counter(t['status'] for t in d['tasks'])
    od = sum(1 for t in d['tasks'] if t['owner_decision'])
    old = re.search(r'\| Tasks \| .* \|\n\| Requiring an owner decision \| \d+ \|', led).group(0)
    new = '| Tasks | %d — %d DONE, %d READY, %d BACKLOG, %d BLOCKED, %d IN_PROGRESS, %d IN_REVIEW |\n| Requiring an owner decision | %d |' % (
        len(d['tasks']), c['DONE'], c['READY'], c['BACKLOG'], c['BLOCKED'], c['IN_PROGRESS'], c['IN_REVIEW'], od)
    return led.replace(old, new), dict(c), od


def main(argv):
    cmd = argv[1] if len(argv) > 1 else 'check'
    d, led = load()
    by = {t['id']: t for t in d['tasks']}
    if cmd == 'check':
        same, differ, missing = 0, [], []
        for t in d['tasks']:
            span = block_span(led, t['id'], required=False)
            if span is None:
                missing.append(t['id'])
            elif led[span[0]:span[1]].rstrip('\n') == ledger_block(t).rstrip('\n'):
                same += 1
            else:
                differ.append(t['id'])
        print('%d of %d blocks reproduced byte-for-byte' % (same, len(d['tasks'])))
        if differ:
            print('differ (amend these only after reading the committed block):', ' '.join(differ))
        if missing:
            print('no `### ` block in the ledger (rendered another way):', ' '.join(missing))
        return 0
    if cmd == 'block':
        print(ledger_block(by[argv[2]]))
        return 0
    if cmd == 'issue-body':
        print(issue_body(by[argv[2]]))
        return 0
    if cmd == 'amend':
        t = by[argv[2]]
        for k, v in json.loads(argv[3]).items():
            t[k] = v
        s, e = block_span(led, t['id'])
        led = led[:s] + ledger_block(t) + '\n' + led[e:]
        led, c, od = counts(d, led)
        io.open(LEDGER, 'w', encoding='utf-8').write(led)
        io.open(INDEX, 'w', encoding='utf-8').write(json.dumps(d, indent=2, ensure_ascii=False) + '\n')
        print('amended %s | %s | owner-decision %d' % (t['id'], c, od))
        return 0
    if cmd == 'counts':
        led, c, od = counts(d, led)
        io.open(LEDGER, 'w', encoding='utf-8').write(led)
        print(c, 'owner-decision', od)
        return 0
    sys.exit(__doc__)


if __name__ == '__main__':
    sys.exit(main(sys.argv))
