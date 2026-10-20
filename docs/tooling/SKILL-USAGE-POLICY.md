# Store Syria — skill and tooling usage policy

**Issue:** #66. Companion to `SKILLS-MANIFEST.md`, which holds the per-tool evidence. This file
says *when to reach for what*, and what may never be reached for.

## 1. The existing execution protocol is the authority

New tooling **enhances** this and never replaces it:

| | |
|---|---|
| the task ledger | `docs/development/phase-1/phase-01-tasks.json` → rendered to `12-PHASE-01-TASK-LEDGER.md` |
| the issue tracker | GitHub Issues, Phase 01 milestone |
| execution order | dependency-driven, re-evaluated before each task |
| the gates | `.github/scripts/*` + `.github/known-test-outcomes.json` |
| test discipline | removal-checked: revert the fix, confirm the test fails |
| security review | the `tenant-authz-reviewer` subagent, `.claude/agents/` |
| owner gates | recorded on the issue, never inferred from silence |

**No tool may introduce a second planning system.** This is the constraint that most of the
candidates brush against, and it is why `superpowers` is `OPTIONAL` rather than approved: its
`writing-plans` skill mandates `docs/superpowers/plans/YYYY-MM-DD-*.md` with checkbox steps, which
is a competing ledger. If a subset of that library is ever vendored, `writing-plans` and
`finishing-a-development-branch` are excluded by name.

## 2. Task-based routing

The rule is **one or two skills per task, chosen by what the work is** — not every skill for every
task. A lean context is a correctness property here: this project's defects are found by reading
specific code carefully, and a context crowded with unrelated instructions is worse at that.

| the work is… | reach for | notes |
|---|---|---|
| **a Laravel controller, FormRequest, policy, observer, Eloquent query, Artisan command** | `.claude/skills/store-syria-laravel-tenancy/` | **Project-local and committed.** Replaces the absent account-level `laravel-best-practices` for everything specific to this codebase. Generic Laravel idiom is not the risk here; the counter-intuitive local facts are. |
| **authorization, tenancy, a token boundary, an order, a payment, any handler reading an id from request input** | the `tenant-authz-reviewer` subagent, then `/tenant-check` | Non-negotiable before committing. Three of the last four packages had a defect found only by this review, including two in the fix itself. |
| **a security claim that needs proving** | an executable test with a removal check; a script under `tests/Support/Security/bin/` when the suite's engine cannot show it | SQLite hides MySQL defects. Four findings so far were invisible to the suite in both directions: P01-SEC-13, the `'5abc'` coercion, the NULL-into-NOT-NULL coercion, the `_ci` collation. |
| **interface work: components, accessibility, responsive layout, RTL, typography, colour** | `frontend-design` (account-level, present) | `ui-ux-pro-max` is **absent** in this environment. Do not write as if it were available. Check both LTR and RTL, and contrast-check: a previous verification found option names at 1.00:1 white-on-white in dark mode. |
| **a dependency or relationship map across many files** | nothing yet — `graphify` is `LATER_PHASE` | Read the code. For a broad sweep, an `Explore` agent returns the conclusion without the file dumps. |
| **current library documentation** (Laravel 11.47, Sanctum, Laratrust) | `vendor/` first. `context7` is permitted for **public framework documentation only** | `vendor/` is the version actually installed, which beats any documentation. If `context7` is ever wired up, the query may name a public package and version and nothing else — see prohibition 8. |
| **implementing any feature or fix** | `test-driven-development` (vendored) | Failing test first, confirmed failing for the expected reason. **This project is stricter than the skill:** after the fix, revert it and confirm the test fails again. The skill's rule catches a test that never ran; ours catches a test that passes for the wrong reason, which has happened repeatedly here. |
| **any bug, test failure or unexpected behaviour** | `systematic-debugging` (vendored) | Root cause before fix; one hypothesis at a time; stop and reconsider the architecture after three failed fixes. Its `root-cause-tracing.md` was localised on vendoring to name the pollution this codebase actually produces — a process-global Composer `ClassLoader`, `Cachable` models under `CACHE_STORE=array`, memoised container bindings. |
| **about to claim something works** | `verification-before-completion` (vendored) | "Evidence before claims, always" — the same rule as CLAUDE.md's "claim only what you executed", and worth having stated twice. |
| **planning or decomposition** | the existing ledger and issues — **nothing vendored** | A plan that is not in the ledger does not exist. This is deliberate and was checked: upstream's planning skills (`writing-plans`, `executing-plans`, `brainstorming`) each mandate their own document tree or progress ledger, and `brainstorming` hard-gates into `writing-plans`. There is no adoptable planning skill, and that is the correct outcome rather than a gap. |
| **reviewing a diff for correctness** | `/code-review`, and `/security-review` for the security pass | Both are built in. Do not vendor a third-party fork of either — see `anthropic-security-review`, rejected for exactly that. |

## 3. Hard prohibitions

These are not preferences. Each maps to a candidate that would have violated it:

1. **Nothing merges, ships or deploys.** (`gstack` runs `gh pr merge --auto --delete-branch` past
   one confirmation → **rejected**.)
2. **Nothing observes or transmits credentials, customer data or transcripts.** (`claude-mem` has
   no path-based exclusion; `.env` contents passing through any tool call are observed by default →
   **rejected**.)
3. **Nothing scans production or an externally hosted target.** (`strix` takes
   `--target https://your-app.com` with no authorization config, ownership check or scope allowlist
   → **rejected**.)
4. **No unreviewed auto-update, no automatic executable download.** Pin an exact version; never
   `npx -y <pkg>@latest` in committed config. (`agent-skills-cli` is unpinned remote code;
   `superpowers`' own README says updates "are often automatic".)
5. **No tool may weaken `.claude/settings.json` or a CI guard.** The 10 `permissions.deny` rules and
   the `PreToolUse` `packages/workdo/` hook stay exactly as they are. A tool that needs them relaxed
   is refused instead.
6. **No API key, token, personal config, binary or session data is committed.** Credentials live in
   the environment; the manifest records *that* a key is needed, never the key.
7. **Default-on telemetry is turned off before enabling anything**, and the off-switch is recorded
   in the manifest with the tool.
8. **No proprietary source, secret or customer data leaves the machine for a documentation
   lookup.** The owner permitted `context7` for **public framework documentation only**. So a
   permitted query names a public package and a version — "Laravel 11.47 session configuration",
   "Sanctum token abilities". A forbidden query carries anything of this repository's: a code
   excerpt, a schema, a migration, a route list, a config value, a customer row, an error message
   with our paths in it. The line is not "is it sensitive" but "did it come from here". Anything
   beyond public documentation needs a fresh approval.
9. **No vendored skill may introduce a second ledger, a git write, or network egress.** This is
   the condition the superpowers subset was chosen against, and it is enforced rather than trusted:
   `tests/Unit/Ci/ClaudeConfigurationTest::test_no_vendored_skill_competes_with_the_project_protocol`
   fails on a `docs/superpowers/` or `.superpowers/` path, on a `git merge|push|worktree`
   instruction, and on any URL in a skill file. It is why `writing-plans`, `executing-plans`,
   `brainstorming` and `finishing-a-development-branch` are excluded by name.

## 4. Third-party skills are supply-chain dependencies

A `SKILL.md` is instructions to an agent with shell and git access. Treat it as executable:

- Read it **verbatim** before committing it. Not the README — the file itself.
- Read any `hooks/`, `scripts/`, `postinstall` or `index.js` that ships beside it.
- Prefer a **reviewed project-local copy** over an account-level install, for license-compliant
  files: a committed copy travels with the repository, appears in the diff, and cannot silently
  change. That is the whole lesson of the three missing skills.
- Vendoring a subset is normal and preferred to adopting a library wholesale.
- A tool whose license is `UNKNOWN` is not adopted. A tool whose license is non-OSI (Commons
  Clause, proprietary) is an owner decision, not an engineering one.

## 5. What is actually installed right now

| capability | where | portable? |
|---|---|---|
| `tenant-authz-reviewer` | `.claude/agents/tenant-authz-reviewer.md` | **yes** — committed |
| `/tenant-check` | `.claude/commands/tenant-check.md` | **yes** — committed |
| `store-syria-laravel-tenancy` | `.claude/skills/store-syria-laravel-tenancy/SKILL.md` | **yes** — committed |
| `test-driven-development` | `.claude/skills/test-driven-development/` (2 files) | **yes** — vendored and committed, MIT, pinned in `.claude/skills/PROVENANCE.md` |
| `systematic-debugging` | `.claude/skills/systematic-debugging/` (4 files) | **yes** — vendored and committed, MIT, pinned |
| `verification-before-completion` | `.claude/skills/verification-before-completion/SKILL.md` | **yes** — vendored and committed, MIT, pinned |
| protected-path hook | `.claude/hooks/guard-protected-paths.php` | **yes** — committed |
| secret deny rules | `.claude/settings.json` | **yes** — committed |
| `frontend-design` | the account | **no** — install-required |
| `laravel-best-practices`, `ui-ux-pro-max`, `claude-automation-recommender` | **absent** | n/a — were never in the repository |

One tool from the candidate list is adopted — the superpowers three-skill subset above, as plain
Markdown with no hook, credential, update path or egress. Every other candidate is rejected,
deferred, or permitted only under a condition in §3. All of the owner decisions that were pending
have been answered; `docs/tooling/SKILLS-MANIFEST.md` §"Owner decisions" records each answer so it
is not re-asked.

## 6. Restoring the toolchain in a new environment

```bash
git clone <repo> && cd syriastore98
composer install
php artisan test --filter ClaudeConfigurationTest   # asserts the committed config is intact
php artisan test --testsuite=Contract               # 26 tests, the Flutter API contract
```

That is the whole bootstrap for everything the repository owns: no tool to install, nothing to
authenticate, no credential to supply.

**`composer install` does need the network** — `repo.packagist.org`, and whatever
`packages/workdo/` resolves from. The "nothing to install" above is about *tooling*, not about
PHP dependencies, and an earlier draft of this line said "no network" without that distinction.
Everything after `composer install` is offline: the tests run on SQLite `:memory:`, and no step
reaches a hosted service.

### What was actually verified, and what was not

Run on 2026-10-20 against commit `aeb2f225`, cloned fresh from `origin` into a scratch directory
(not copied from a working tree):

| step | result |
|---|---|
| every file this document lists as repository-owned | present in the clone |
| `.claude/agents/`, `.claude/commands/`, `.claude/hooks/`, `.claude/settings.json` | present |
| no `.env` in the clone (only `.env.example`, `.env.testing`) | confirmed |
| skill frontmatter parses; `name` matches its directory | confirmed |
| `php artisan test --filter ClaudeConfigurationTest` | **71 passed**, 413 assertions |
| `php artisan test --testsuite=Contract` | **26 passed**, 83 assertions |
| the four CI guards, run from the clone | all exit 0 |
| `composer install` | **not verified** — see below |

`composer install` could not be run to completion: this container's network policy resets the
connection to `repo.packagist.org`, so the install stalled and was killed at 9 minutes. The
dependency tree was instead supplied from the existing checkout by hardlink, after confirming the
clone's `composer.lock` is byte-identical (`sha256 1227b225…2557`) — so the *dependency set* is
exactly what `composer install` would resolve, and `realpath()` was checked to confirm the
autoloader resolved inside the clone rather than back into the source checkout. What remains
unverified is composer's own download step, which is a property of the network, not of this
repository. Anyone repeating this check in an environment that permits `repo.packagist.org` gets
the one missing line.

**What this does NOT restore, stated plainly:** `frontend-design` is an account-level skill and is
not in the repository. A fresh session on a different account will not have it, exactly as this
session does not have the other three. There is no repository-side fix for that, and claiming
otherwise is the error this issue was opened to correct.
