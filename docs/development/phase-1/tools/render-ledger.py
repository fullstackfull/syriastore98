#!/usr/bin/env python3
"""Render 12-PHASE-01-TASK-LEDGER.md from phase-01-tasks.json.

The JSON is the source of truth. This script also VALIDATES the task graph and exits
non-zero on: an unknown dependency, a dependency cycle, or a READY task whose
prerequisites are not DONE. Run it after editing the JSON, then commit both files.

    python3 docs/development/phase-1/tools/render-ledger.py
"""
import json, io, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
PHASE_DIR = os.path.dirname(HERE)
SRC = os.path.join(PHASE_DIR, 'phase-01-tasks.json')
OUT = os.path.join(PHASE_DIR, '12-PHASE-01-TASK-LEDGER.md')

WS = {'security': 'Security & Identity', 'orders': 'Orders & Checkout',
      'payments': 'Payments & Financial Integrity', 'inventory': 'Inventory & Stock',
      'platform': 'Platform Foundation', 'migrations': 'Schema & Migration Readiness',
      'design-system': 'Design System (foundation only)'}
ORDER = ('security', 'inventory', 'payments', 'orders', 'platform', 'migrations', 'design-system')


def validate(tasks):
    by = {t['id']: t for t in tasks}
    errs = []
    for t in tasks:
        for dep in t['depends_on']:
            if dep not in by:
                errs.append('%s depends on unknown task %s' % (t['id'], dep))
    state = {}

    def visit(i, stack):
        if state.get(i) == 1:
            errs.append('CYCLE: ' + ' -> '.join(stack + [i]))
            return
        if state.get(i) == 2 or i not in by:
            return
        state[i] = 1
        for dep in by[i]['depends_on']:
            visit(dep, stack + [i])
        state[i] = 2

    for t in tasks:
        visit(t['id'], [])
    for t in tasks:
        if t['status'] == 'READY':
            bad = [d for d in t['depends_on'] if by[d]['status'] != 'DONE']
            if bad:
                errs.append('%s is READY but depends on unfinished %s' % (t['id'], bad))
    return errs


def topo(tasks):
    by = {t['id']: t for t in tasks}
    out, seen = [], set()

    def emit(i):
        if i in seen:
            return
        for dep in by[i]['depends_on']:
            emit(dep)
        seen.add(i)
        out.append(i)

    for t in sorted(tasks, key=lambda x: x['id']):
        emit(t['id'])
    return out


def render(d):
    tasks = d['tasks']
    by = {t['id']: t for t in tasks}
    n = lambda s: sum(1 for t in tasks if t['status'] == s)
    L = []
    A = L.append
    A('# Phase 01 — Task Ledger  ·  Foundation & Stability')
    A('')
    A('> **GENERATED FILE — do not hand-edit.** The source of truth is'
      ' [`phase-01-tasks.json`](phase-01-tasks.json); regenerate with')
    A('> `python3 docs/development/phase-1/tools/render-ledger.py`, then commit both files.')
    A('> GitHub Issues carry the same records and must agree with this ledger.')
    A('')
    A('| | |')
    A('|---|---|')
    A('| Phase | **01 — Foundation & Stability** (milestone #%s) |' % d['milestone'])
    A('| Branch | `%s` |' % d['branch'])
    A('| Pull request | #%s — **stays Draft**; no merge to `main`, no deployment |' % d['pr'])
    A('| Baseline head at planning time | `%s` (`main` at `%s`) |' % (d['baseline_head'], d['main_at']))
    A('| Tasks | %d — %d DONE, %d READY, %d BACKLOG, %d BLOCKED, %d IN_PROGRESS, %d IN_REVIEW |'
      % (len(tasks), n('DONE'), n('READY'), n('BACKLOG'), n('BLOCKED'), n('IN_PROGRESS'), n('IN_REVIEW')))
    A('| Requiring an owner decision | %d |' % sum(1 for t in tasks if t['owner_decision']))
    A('')
    A('**Status values** are exactly the six the owner authorised:'
      ' `BACKLOG` `READY` `IN_PROGRESS` `BLOCKED` `IN_REVIEW` `DONE`.')
    A('A task is **not** `DONE` because code was committed — `DONE` requires its acceptance criteria'
      ' verified, with the evidence recorded in its row.')
    A('')
    A('## How a future session picks up this phase')
    A('')
    A('1. Read this file and `phase-01-tasks.json`.')
    A('2. `gh issue list --milestone "Phase 01 — Foundation & Stability" --state open` for live state.')
    A('3. Take the first task whose status is `READY` and whose dependencies are all `DONE`.')
    A('4. Follow the execution loop in [`13-PHASE-01-EXECUTION-PROTOCOL.md`](13-PHASE-01-EXECUTION-PROTOCOL.md).')
    A('5. Never start a `BLOCKED` task: its `blocked_on` names what must happen first, and owner'
      ' silence is not approval.')
    A('')
    A('## Dependency graph')
    A('')
    A('```mermaid')
    A('graph TD')
    linked = set()
    for t in tasks:
        for dep in t['depends_on']:
            A('  %s --> %s' % (dep.replace('-', '_'), t['id'].replace('-', '_')))
            linked.add(dep)
            linked.add(t['id'])
    for t in tasks:
        if t['id'] not in linked:
            A('  %s' % t['id'].replace('-', '_'))
    A('```')
    A('')
    A('Most Phase 01 work is genuinely independent; the real couplings are few, and they are stated'
      ' here rather than left implicit in the diagram:')
    A('')
    A('| dependency | why |')
    A('|---|---|')
    A('| `P1-INV-03` ← `P1-INV-01` | both change `ProductController`; serialised so two agents never'
      ' edit the same working file |')
    A('| `P1-INV-05` ← `P1-INV-02` | reservation semantics need the return path settled first |')
    A('| `P1-ORD-01` ← `P1-ORD-02` | the coupon fix must land on one server-authoritative total, not four |')
    A('| `P1-PLT-03` ← `P1-PLT-02` | tightening tenant scope before the NULL-store writers are fixed'
      ' empties the storefront |')
    A('| `P1-MIG-01` ← `P1-PAY-04`, `P1-MIG-03` | releasing the money tables while COD settles at order'
      ' time, or with no cost input, makes the ledger actively wrong |')
    A('| `P1-MIG-02` ← `P1-PLT-01` | the defect is invisible on SQLite; it needs the MySQL harness to be'
      ' provable |')
    A('')
    A('## Execution order')
    A('')
    A("The directive's priority order, applied: (1) critical reachable security, (2) cross-tenant"
      ' financial and stock integrity, (3) order and payment correctness, (4) migration prerequisites,'
      ' (5) shared architecture, (6) observability and acceptance.')
    A('')
    for i, tid in enumerate([x for x in topo(tasks) if by[x]['status'] != 'DONE'], 1):
        t = by[tid]
        A('%d. `%s` — %s *(%s, %s, risk %s)*' % (i, tid, t['title'], t['workstream'], t['status'], t['risk']))
    A('')
    for ws in ORDER:
        group = [t for t in tasks if t['workstream'] == ws]
        if not group:
            continue
        A('---')
        A('')
        A('## %s' % WS[ws])
        A('')
        for t in group:
            A('### `%s` — %s' % (t['id'], t['title']))
            A('')
            A('| field | value |')
            A('|---|---|')
            A('| Status | **%s** |' % t['status'])
            A('| Risk | %s |' % t['risk'])
            A('| Workstream | %s |' % WS[ws])
            A('| GitHub issue | %s |' % (('#%s' % t['issue']) if t.get('issue') else '_pending_'))
            A('| Owner decision required | %s |' % ('**yes**' if t['owner_decision'] else 'no'))
            if t.get('blocked_on'):
                A('| Blocked on | %s |' % t['blocked_on'])
            A('| Depends on | %s |' % (', '.join('`%s`' % x for x in t['depends_on']) or '—'))
            A('| Scope | %s |' % ', '.join('`%s`' % s for s in t['scope']))
            A('| Queue references | %s |' % ', '.join(t['queue_refs']))
            A('| Database / migration | %s |' % t['db'])
            A('| Compatibility impact | %s |' % t['compatibility'])
            A('')
            A('**Existing implementation status.** %s' % t['existing_status'])
            A('')
            A('**Acceptance criteria.**')
            for a in t['acceptance']:
                A('- [ ] %s' % a)
            A('')
            A('**Required tests.**')
            for x in t['tests']:
                A('- %s' % x)
            A('')
            A('**Evidence.** %s' % t['evidence'])
            A('')
    return '\n'.join(L) + '\n'


def main():
    d = json.load(open(SRC, encoding='utf-8'))
    errs = validate(d['tasks'])
    if errs:
        print('TASK GRAPH INVALID:', file=sys.stderr)
        for e in errs:
            print('  - ' + e, file=sys.stderr)
        return 1
    io.open(OUT, 'w', encoding='utf-8').write(render(d))
    print('rendered %s (%d tasks)' % (os.path.relpath(OUT), len(d['tasks'])))
    return 0


if __name__ == '__main__':
    sys.exit(main())
