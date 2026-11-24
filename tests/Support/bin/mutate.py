#!/usr/bin/env python3
"""
THE MUTATION HARNESS. Apply a one-line change that SHOULD break a test, and prove the test notices.

This project's TDD rule is stricter than the vendored skill's: after a fix, revert it and confirm
the test fails again. The skill's rule catches a test that never ran; ours catches a test that
passes for the wrong reason, which is the failure mode that has actually bitten here. This script
is that second check, made repeatable -- and it exists as a file because three earlier mutation runs
were meaningless from shell escaping alone (`\\$`, `\\\\App`) and reported "passed" against code that
had not been mutated.

THREE WAYS A MUTATION RUN LIES, all three measured in this repository and all three guarded here.

 1. THE ANCHOR DID NOT MATCH. A `str.replace` that finds nothing changes nothing, so the test
    passes and the mutation reads as "survived" -- or worse, as "the code is fine". Every mutation
    asserts its anchor occurs EXACTLY ONCE before writing.

 2. THE FILTER MATCHED NO TEST. PHPUnit exits 0 when `--filter` selects nothing, so an empty run
    is indistinguishable from a passing one. Measured 2026-11-21: six mutations in a row reported
    "SURVIVED" because the filters were Pest DISPLAY names (`hostile host round trips`) and the
    method names have underscores. A run with no `Tests:` summary line is rejected, not counted.

 3. A STALE COMPILED BLADE SERVES THE MUTATED VIEW AFTER THE REVERT. This one cost a real false
    reading on 2026-11-21 and is the subtlest of the three. `shutil.copy` does NOT preserve mtime,
    so copy-then-move-back gives the restored file the mtime of the COPY, which is EARLIER than the
    compiled view Blade wrote during the mutated run. Blade recompiles only when the source is
    newer than the compiled file, so the next run renders the MUTATION -- the view's test then fails
    against correct source, and in the opposite direction a mutation would look CAUGHT when the
    test never saw it. The mtime is restored and `view:clear` is run after any Blade mutation.

Usage: a caller imports `run()` and passes a list of Mutation tuples. There is deliberately no CLI:
a mutation list is written for one change and is not a reusable fixture.
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
from dataclasses import dataclass


@dataclass(frozen=True)
class Mutation:
    name: str
    path: str
    old: str
    new: str
    test_file: str
    # The METHOD name, not the Pest display name. `test_foo_bar`, never `foo bar`.
    filter: str


def _is_view(path: str) -> bool:
    return path.endswith('.blade.php')


def run(mutations: list[Mutation], timeout: int = 900) -> int:
    """Apply each mutation, run its test, restore, and report. Returns a process exit code."""
    survivors: list[str] = []

    for m in mutations:
        src = open(m.path).read()
        occurrences = src.count(m.old)

        if occurrences != 1:
            print(f'  !! {m.name}: anchor appears {occurrences} times -- NOT APPLIED, proves nothing')
            survivors.append(f'{m.name} (anchor x{occurrences})')
            continue

        # Preserve the mtime so the revert cannot leave a compiled view newer than its source.
        original_times = (os.stat(m.path).st_atime, os.stat(m.path).st_mtime)
        backup = m.path + '.mutation-backup'
        shutil.copy2(m.path, backup)

        try:
            with open(m.path, 'w') as handle:
                handle.write(src.replace(m.old, m.new, 1))

            result = subprocess.run(
                ['php', 'artisan', 'test', m.test_file, '--filter', m.filter],
                capture_output=True, text=True, timeout=timeout,
            )
        finally:
            shutil.move(backup, m.path)
            os.utime(m.path, original_times)

            if _is_view(m.path):
                subprocess.run(['php', 'artisan', 'view:clear'],
                               capture_output=True, text=True, timeout=120)

        summary = [line for line in result.stdout.splitlines() if 'Tests:' in line]

        if not summary or 'No tests' in result.stdout:
            print(f'  !! {m.name}: the filter matched NO TEST, so this run proves nothing')
            print('     last line: ' + (result.stdout.strip().splitlines() or ['(empty)'])[-1])
            survivors.append(f'{m.name} (filter matched nothing)')
            continue

        caught = result.returncode != 0
        print(f'  {"CAUGHT  " if caught else "SURVIVED"} {m.name}  ->  {summary[0].strip()}')

        if not caught:
            survivors.append(m.name)

    print()
    print('ALL CAUGHT' if not survivors else 'SURVIVORS: ' + '; '.join(survivors))

    return 1 if survivors else 0


if __name__ == '__main__':
    print(__doc__)
    sys.exit(0)
