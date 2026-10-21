#!/usr/bin/env python3
"""Removal-check ("a test must bite") harness: apply ONE textual mutation at a time, run a test file, restore, report.

    python3 docs/development/phase-1/tools/mutation-harness.py mutants.json tests/Feature/Security/SomeTest.php [name-filter ...]

mutants.json is a list:  [{"name": "hook removed", "edits": [{"file": "app/Models/Setting.php", "old": "...", "new": "..."}]}]
`old` must occur in the file (the harness aborts that mutant if it does not -- a mutant that cannot be applied proves nothing).
Output per mutant: BITES (>= 1 test failed) or SURVIVED!! (every test passed: a test gap, or an equivalent mutant -- decide which, and
close the gap with a test or remove the equivalent code; do not excuse it).

RULES THAT COST REAL TIME TO LEARN
  * While it runs the working tree holds MUTATED source. Do not edit files, do not run another suite, do not commit, and do not let a
    read-only reviewer look at the tree. Ask first whether a Workflow review is running.
  * If you must stop it: kill the python process (use `pgrep -f "[m]utation-harness"`, never `pkill -f` with a pattern that matches your
    own shell), then RESTORE the files from backups you took before starting (`cp` each, then `cmp`) -- a kill mid-mutant leaves a
    mutated file on disk. The harness prints a SHA of every touched file before and after; compare them.
  * Take backups first:  cp app/Services/X.php /tmp/X.bak   and compare after:  cmp app/Services/X.php /tmp/X.bak
  * Run only the test file that owns the control; the full suite is for the gate, not for mutants.
"""
import hashlib
import json
import re
import subprocess
import sys

ROOT = subprocess.run(['git', 'rev-parse', '--show-toplevel'], capture_output=True, text=True).stdout.strip()


def sha(path):
    return hashlib.sha256(open(ROOT + '/' + path, 'rb').read()).hexdigest()[:12]


def run(mutant, test):
    saved = {}
    try:
        for e in mutant['edits']:
            s = open(ROOT + '/' + e['file'], encoding='utf-8').read()
            saved.setdefault(e['file'], s)
            if s.count(e['old']) < 1:
                print('%-60s CANNOT APPLY (old text not found in %s)' % (mutant['name'], e['file']))
                return
            open(ROOT + '/' + e['file'], 'w', encoding='utf-8').write(s.replace(e['old'], e['new'], 1))
        out = subprocess.run('php artisan test %s 2>&1' % test, shell=True, cwd=ROOT, capture_output=True, text=True).stdout
        fails = re.findall(r'⨯ (.*?)\s{2,}', out)
        summary = re.search(r'Tests:\s+(.*)', out)
        print('%-60s %s  %s' % (mutant['name'], 'BITES' if fails else 'SURVIVED!!', (summary.group(1) if summary else '?')[:40]), flush=True)
        if fails:
            print('     e.g.', ' | '.join(sorted(set(x[:52] for x in fails))[:2]), flush=True)
    finally:
        for f, s in saved.items():
            open(ROOT + '/' + f, 'w', encoding='utf-8').write(s)
        subprocess.run('git ls-files -d -z | xargs -0 -r git checkout --', shell=True, cwd=ROOT)


def main():
    mutants = json.load(open(sys.argv[1], encoding='utf-8'))
    test, only = sys.argv[2], sys.argv[3:]
    files = sorted({e['file'] for m in mutants for e in m['edits']})
    before = {f: sha(f) for f in files}
    for m in mutants:
        if only and not any(o in m['name'] for o in only):
            continue
        run(m, test)
    after = {f: sha(f) for f in files}
    print('files restored exactly:' , 'YES' if before == after else 'NO -- %s' % {f: (before[f], after[f]) for f in files if before[f] != after[f]})


if __name__ == '__main__':
    main()
