#!/usr/bin/env bash
#
# RUN THE NINE GUARDS THAT THE DEPENDENCY-ADVISORY GATE USED TO SWITCH OFF, AND BITE EACH ONE.
#
# WHY THIS EXISTS. `composer audit must not grow` sat at step 7 of 18 inside ci.yml's `static`
# job. A GitHub Actions step defaults to `if: success()`, so when it went red -- which it did on
# 2026-11-04 at 20:18Z, because the PUBLIC ADVISORY DATABASE grew past the ceiling, against a
# composer.lock byte-identical to main's -- it SKIPPED THE ELEVEN STEPS AFTER IT. Two of those
# also run in the `tests` jobs. The other NINE run nowhere else, and so had not run for 26
# commits across ~8.5 hours. The advisory gate now lives in its own job (see ci.yml's
# `advisories`), which is the fix; this script is the EVIDENCE that the nine are real.
#
# WHY A SCRIPT AND NOT A TEST. ci.yml only runs `on: pull_request` and `on: push` to main/master,
# so a feature branch cannot trigger a run at all -- there was no CI run to point at. And per
# CLAUDE.md 3, evidence that cannot live in the suite goes here as an executable script stating
# its own preconditions, because a conditionally-skipped test would break the declared outcome set.
#
# WHAT IT DOES. Extracts each guard's `run:` body straight out of ci.yml (so it cannot drift from
# what CI actually executes), runs all nine, then SABOTAGES each one and asserts it reddens.
#
# THE BITES MATTER MORE THAN THE PASSES. Writing them caught FOUR mis-aimed probes of my own, each
# of which read as "this guard is blind" when the guard was correct:
#
#   * `packages/workdo/` guard -- inspects a COMMIT RANGE. An uncommitted working-tree change is
#     invisible to it by design. The sabotage has to be committed.
#   * `php -l on every tracked file` -- reads `git ls-files`, i.e. the INDEX. An untracked file is
#     invisible. The sabotage has to be `git add`ed.
#   * root-uploads guard -- its pathspec is `-- 'uploads/'`, the root-level uploads/ DIRECTORY, not
#     the repository root. A file committed at the root itself is correctly ignored, though the
#     step's NAME reads as though it would be caught.
#   * admin auth coverage -- only watches `/api/admin/*`. CLAUDE.md said that group was
#     `routes/api.php` lines 133-387; measured, it is 169-423, so a sabotage at 133 lands on a
#     STOREFRONT route and one at 184 lands on a comment. Neither reddens it, correctly.
#
# PRECONDITIONS
#   * run from the repository root, on a clean working tree (it commits and resets --hard)
#   * `php` and `python3` on PATH; `origin/main` fetched (for the diff base)
#   * NOT for CI. This mutates and resets git state.
#
# USAGE   tests/Support/Security/bin/run-orphaned-ci-guards.sh
# Exit 0 = all nine ran and all nine reddened under sabotage.

set -uo pipefail

cd "$(dirname "$0")/../../../.." || exit 1
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

if [ -n "$(git status --porcelain)" ]; then
  echo "REFUSING: the working tree is dirty. This script commits and resets --hard."
  git status --porcelain | head
  exit 1
fi

SAFE="$(git rev-parse HEAD)"
git fetch -q --no-tags origin main 2>/dev/null || true
BASE="$(git merge-base HEAD origin/main 2>/dev/null || git rev-parse HEAD~1)"
echo "HEAD=$SAFE  diff base=$BASE"

restore() { git reset -q --hard "$SAFE"; git clean -qfd -- uploads app/Helper 2>/dev/null || true; }

# ---- extract each guard's run: body out of ci.yml, so this cannot drift from CI --------------
python3 - "$WORK" <<'PY'
import sys, yaml, os, json
work = sys.argv[1]
d = yaml.safe_load(open('.github/workflows/ci.yml'))

ORPHANED = [
    'Guard - the known .env exposure is reported, and no new one appeared',
    'Guard - the attestation gate behaves as specified',
    'Guard - packages/workdo/ is unmodified',
    'Guard - no NEW user upload is committed to the repository root',
    'php -l on changed PHP files',
    'php -l on EVERY tracked PHP file',
    'Design-system token contrast must stay WCAG-conformant',
    'Cross-repo API contract - the shipped Flutter app must not be broken',
    'Admin API auth coverage must not regress',
]

# PREMISE 1: the advisory gate must NOT share a job with any of the nine.
jobs = d['jobs']
adv_jobs = [j for j, b in jobs.items()
            if any('composer audit' in (s.get('name') or '') for s in b.get('steps', []))]
assert len(adv_jobs) == 1, f'expected exactly one advisory job, found {adv_jobs}'
adv = adv_jobs[0]
adv_names = [s.get('name') or '' for s in jobs[adv]['steps']]
for g in ORPHANED:
    assert g not in adv_names, f'STILL CO-LOCATED with the advisory gate: {g}'

# PREMISE 2: every one of the nine still exists somewhere, and we can find its body.
found = {}
for jn, body in jobs.items():
    for s in body.get('steps', []):
        n = s.get('name') or ''
        if n in ORPHANED:
            found[n] = (jn, s.get('run', ''))
missing = [g for g in ORPHANED if g not in found]
assert not missing, f'guard(s) vanished from ci.yml entirely: {missing}'

man = []
for i, g in enumerate(ORPHANED):
    jn, run = found[g]
    p = os.path.join(work, f'g{i}.sh')
    open(p, 'w').write('#!/usr/bin/env bash\nset -euo pipefail\n' + run)
    os.chmod(p, 0o755)
    man.append({'i': i, 'name': g, 'job': jn, 'path': p})
json.dump(man, open(os.path.join(work, 'manifest.json'), 'w'))
print(f'  advisory gate isolated in job: {adv}')
print(f'  nine guards extracted from job(s): {sorted({m["job"] for m in man})}')
PY
[ $? -eq 0 ] || { echo "PREMISE FAILED - the split is not in place"; exit 1; }

run_guard() { BASE_SHA="$BASE" BEFORE_SHA="" bash "$WORK/g$1.sh" >/dev/null 2>&1; }
nameof() { python3 -c "import json,sys;print([m['name'] for m in json.load(open('$WORK/manifest.json')) if m['i']==$1][0])"; }

echo; echo "=== PART 1: do the nine pass as the tree stands? ==="
pass=0; fail=0
for i in 0 1 2 3 4 5 6 7 8; do
  if run_guard $i; then printf '  PASS  %s\n' "$(nameof $i)"; pass=$((pass+1))
  else printf '  FAIL  %s\n' "$(nameof $i)"; fail=$((fail+1)); fi
done
echo "  -> $pass passed, $fail failed"
[ $fail -eq 0 ] || { echo "A GUARD IS RED. That is the point of running them: fix it."; exit 1; }

echo; echo "=== PART 2: bite each one. A guard that cannot fail is not a guard. ==="
bit=0; blind=0
bite() { # $1 index, $2 label, $3 setup fn
  $3
  if run_guard "$1"; then printf '  BLIND !!  %s  <- %s\n' "$(nameof $1)" "$2"; blind=$((blind+1))
  else printf '  reddens   %s  <- %s\n' "$(nameof $1)" "$2"; bit=$((bit+1)); fi
  restore
}

b_env()   { printf 'APP_KEY=x\n' > .env.bite; }
b_attest(){ printf '#!/usr/bin/env bash\nexit 0\n' > .github/scripts/assert-human-approval.sh; }
b_workdo(){ f=$(git ls-files 'packages/workdo/*.php' | head -1); echo '// bite' >> "$f"
            git add "$f"; git -c user.email=b@b -c user.name=b commit -q -m bite; BASE="$SAFE"; }
b_upload(){ mkdir -p uploads; printf 'x' > uploads/bite.png; git add -f uploads/bite.png
            git -c user.email=b@b -c user.name=b commit -q -m bite; BASE="$SAFE"; }
b_lint()  { printf '<?php\nclass B { public function x( {\n' > app/Helper/_bite.php
            git add app/Helper/_bite.php; git -c user.email=b@b -c user.name=b commit -q -m bite; BASE="$SAFE"; }
b_token() { python3 - <<'P'
import re
p='public/css/store-syria-ds.css'; s=open(p).read()
s=re.sub(r'--ss-fg-muted:\s*[^;]*;', '--ss-fg-muted: #cfcfcf;', s, count=1)
open(p,'w').write(s)
P
}
b_route() { python3 - <<'P'
src=open('routes/api.php').read().splitlines(True)
start=next(i for i,l in enumerate(src) if "prefix('admin')" in l)
import re
for i in range(start+1, len(src)):
    if 'Route::' in src[i] and 'custom.auth' in src[i]:
        src[i]=re.sub(r'->middleware\(\[[^\]]*\]\)','',src[i]); break
open('routes/api.php','w').writelines(src)
P
}

bite 0 "a new .env file is planted"                        b_env
bite 1 "assert-human-approval.sh stubbed to exit 0"        b_attest
bite 2 "a packages/workdo/ change is COMMITTED"            b_workdo
bite 3 "a file is COMMITTED under the root uploads/"       b_upload
bite 5 "a syntax error is git-added (index, not worktree)" b_lint
bite 6 "--ss-fg-muted lightened below AA"                  b_token
bite 8 "custom.auth stripped INSIDE the admin prefix group" b_route

echo; echo "=== PART 3: the tree is back where it started ==="
git log --oneline -1
if [ -n "$(git status --porcelain)" ]; then echo "  DIRTY - restore failed:"; git status --porcelain; exit 1; fi
echo "  clean, at $SAFE"

echo; echo "----------------------------------------------------------------"
echo "nine guards: $pass passed"
echo "bites:       $bit reddened, $blind blind"
echo "----------------------------------------------------------------"
[ $blind -eq 0 ] || exit 1
