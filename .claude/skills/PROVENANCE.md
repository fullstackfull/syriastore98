# Vendored skill provenance

Third-party skills are **supply-chain dependencies**. This file records what was taken, from
where, at what version, under what licence, and every byte that was changed on the way in — so the
diff that introduced them is reviewable and so a later update is a deliberate act rather than a
silent one.

Project-local skills written here (currently `store-syria-laravel-tenancy/`) are not listed: they
have no upstream.

---

## `superpowers` — a three-skill subset

| | |
|---|---|
| **Upstream** | https://github.com/obra/superpowers |
| **Licence** | MIT — `Copyright (c) 2025 Jesse Vincent`, read from `raw.githubusercontent.com/obra/superpowers/main/LICENSE`, corroborated by `.claude-plugin/plugin.json` and the README |
| **Version** | v7.0.0 (`RELEASE-NOTES.md`, dated 2026-10-09) |
| **Fetched** | 2026-10-20, from `raw.githubusercontent.com/obra/superpowers/main/skills/…` |
| **Pinned by** | SHA-256 of each file as fetched (below). The GitHub API is not reachable for this repository from this session, so a commit SHA could not be recorded — a content hash is a stricter pin anyway: it detects a change to the file even if the branch pointer is rewritten. |
| **Install method** | **NOT installed.** No marketplace install, no plugin, no hook. Seven Markdown files copied into `.claude/skills/` and committed, so they travel with the repository and are reviewed in the diff. The upstream `SessionStart` hook, the bundled `scripts/server.cjs` and every maintainer script are deliberately absent. |
| **Egress** | None. Scanned: no URL, no `curl`/`wget`/`npx`, no script invocation, in any of the seven files. Upstream's only telemetry (a browser-side brand image in the brainstorming companion server) is in a file not vendored here. |
| **Credentials** | None. |
| **Executables** | None. |

### Why these three, and not the other twelve

Read before deciding, not after. Upstream has 15 skills; the owner asked for a minimal
nonconflicting subset covering **planning, TDD, debugging and verification**.

**Adopted:**

| skill | files | why it is safe here |
|---|---|---|
| `test-driven-development` | `SKILL.md`, `writing-good-tests.md` | No mandated path, no git command, no script, no hard dependency on another skill. Complements rather than competes with `CLAUDE.md`'s own stricter rule — upstream asks that a test fail *before* the fix; this project additionally requires reverting the fix afterwards to prove the test bites. |
| `systematic-debugging` | `SKILL.md`, `root-cause-tracing.md`, `defense-in-depth.md`, `condition-based-waiting.md` | Same: prose only. Its two cross-references are to the other two adopted skills. |
| `verification-before-completion` | `SKILL.md` | Entirely self-contained — no path, no git, no script, no reference to any other skill. Its rule ("evidence before claims, always") is `CLAUDE.md`'s "claim only what you executed" in different words. |

**Rejected, each for a read reason:**

| skill | why not |
|---|---|
| `writing-plans` | Mandates `docs/superpowers/plans/YYYY-MM-DD-*.md` with checkbox steps — a second task ledger beside `phase-01-tasks.json` and GitHub Issues. Explicitly forbidden. |
| `executing-plans` | Worse: keeps its **own progress ledger** at `.superpowers/sdd/<plan>/progress.md`, depends on `writing-plans` *and* `finishing-a-development-branch`, creates git worktrees and commits as the plan directs. Eight skill dependencies. |
| `finishing-a-development-branch` | Offers "merge back to <base-branch> locally" and "push and create a Pull Request", and runs `git merge` / `git push -u origin` on that choice. Against "never merge into `main`" and "keep PR #3 Draft". |
| `brainstorming` | Two problems. It mandates a second committed document tree (`docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md`), and it **hard-gates into the excluded skill**: "After a project's document is approved … invoke superpowers:writing-plans and no other skill." Its description also begins "You MUST use this before any creative work", which would fire on nearly every task and compete with `CLAUDE.md`. |
| `requesting-code-review`, `receiving-code-review` | Nonconflicting — read-only git, no artifacts, no hard dependencies — but **duplicative**, and the duplication is not neutral. This project's `tenant-authz-reviewer` carries the codebase's own facts in its prompt, and that specificity is what has actually found defects: a generic reviewer would not know that `@can` is always false here, or that absence from `scoped_models` is what decides. A generic reviewer alongside it invites using the weaker one. `/code-review` and `/security-review` ship with the harness anyway. |
| `subagent-driven-development`, `dispatching-parallel-agents` | Duplicative of the `Workflow` and `Agent` tooling already in use, and both are tied to the `.superpowers/sdd/` workspace format. |
| `using-git-worktrees` | Branch machinery, against the branch and PR constraints. |
| `using-superpowers`, `diagnosing-superpowers`, `writing-skills` | Bootstrap and meta-tooling for the plugin that is deliberately **not** installed. `using-superpowers` is what the `SessionStart` hook injects; without the hook it has no job. |

### Modifications made on vendoring

Four, all mechanical, all because the plugin namespace and two sibling files do not exist here.
Nothing was changed for taste.

| # | file | change |
|---|---|---|
| M1 | `systematic-debugging/SKILL.md` | the reference `superpowers:test-driven-development` → `test-driven-development` |
| M2 | `systematic-debugging/SKILL.md` | the reference `superpowers:verification-before-completion` → `verification-before-completion` |
| M3 | `test-driven-development/writing-good-tests.md` | removed the parenthetical `(superpowers:writing-skills)` — that skill is not vendored, and the sentence is intact and true without it |
| M4 | `systematic-debugging/root-cause-tracing.md` | replaced the "Finding Which Test Causes Pollution" section, whose whole content was `bash ./find-polluter.sh` for a script not vendored here, with the PHPUnit equivalent **and the pollution this codebase actually produces** — `loadAddons()` mutating the process-global Composer `ClassLoader`, `Cachable` models under `CACHE_STORE=array`, and `Cache::remember('admin_details')`. All three are measured, and the first cost a real false reading. |

M1–M3 are reversible one-token edits. M4 is a deliberate localisation: upstream's version was
inert here, and the replacement is the technique that would have saved time on a real incident in
this repository.

### Content pins, as fetched (before M1–M4)

```
51c6435d98c83c42c96ba7c35725a0b2c376cd487fb1a4e6bd0ddbe7e33ef202  systematic-debugging/SKILL.md
e89fec8400d6cd50f43407cec9fab50976ba4d55d0ec2eb51c0bd68036b54c26  systematic-debugging/condition-based-waiting.md
1e175fb86fc357e58c6aebf5441e481e1b7868b4380c0456b63a17eefbd18ba7  systematic-debugging/defense-in-depth.md
75b933b6a8c40bdb2031b10f21654395b56ec6ab6bc7b018c18d3fe57aeb7fb8  systematic-debugging/root-cause-tracing.md
64b03fce4aee5a97a93160cea8111f3ba13a17b7c001db4bd5836d67fd10705d  test-driven-development/SKILL.md
51471c853306ff92ca8bb41dcaea05f31c0e46b03651f8f3c99754b7172f4ae1  test-driven-development/writing-good-tests.md
2befe7fc55bcadaa3d97dd9e8efeb633d2561c0ebe74c5a8b17c4d9e7e4520b3  verification-before-completion/SKILL.md
```

To check for an upstream change without trusting a branch pointer:

```bash
for f in test-driven-development/SKILL.md test-driven-development/writing-good-tests.md \
         systematic-debugging/SKILL.md systematic-debugging/root-cause-tracing.md \
         systematic-debugging/defense-in-depth.md systematic-debugging/condition-based-waiting.md \
         verification-before-completion/SKILL.md ; do
  printf '%s  %s\n' \
    "$(curl -sS "https://raw.githubusercontent.com/obra/superpowers/main/skills/$f" | sha256sum | cut -d' ' -f1)" "$f"
done
```

A hash that differs means upstream moved. That is a prompt to re-read the diff, not to pull it.

### Reverting

```bash
rm -rf .claude/skills/test-driven-development \
       .claude/skills/systematic-debugging \
       .claude/skills/verification-before-completion
```

Nothing else depends on them: no hook, no config entry, no test asserts their contents. The
project-local `store-syria-laravel-tenancy` skill is independent and must not be removed —
`CLAUDE.md` and `tests/Unit/Ci/ClaudeConfigurationTest.php` both depend on it.
