# HANDOFF — current state of Store Syria Phase 01

**Written 2026-10-21 for a fresh Claude Code account with no access to the conversations that produced this repository.**
**REVISED 2026-10-22** — the ledger/GitHub drift of §4 is now FIXED, four unrecorded tasks have rows and issues, and §2's CI line and §9's
sandbox note are corrected. What changed is marked `[2026-10-22]`; everything unmarked still reads as its author left it.
It records only what is NOT already in `CLAUDE.md`, the Phase 01 ledger, the security queue or GitHub — and points at those for the rest.
Read it once, then work from the ledger and GitHub. If this file and the ledger disagree, the ledger (`phase-01-tasks.json`) wins for task
state and this file wins for *process*; fix whichever is stale and say so in the commit.

Labels used below: **[VERIFIED]** = executed or read at the stated commit · **[PENDING]** = recorded, not yet verified by execution ·
**[OWNER-APPROVED]** = recorded owner decision (quote or file given) · **[OWNER-STATED]** = said by the owner in a session, not yet reflected
in the ledger · **[PROPOSED]** = engineering proposal, owner has not confirmed.

---

## 0. Read order (15 minutes)

1. `CLAUDE.md` — the traps (Laratrust not spatie, `custom.auth` guard crossing, `null_tenant => open`, `Product` is not scoped, Cachable `User`, SQLite vs MySQL).
2. This file.
3. `docs/development/phase-1/13-PHASE-01-EXECUTION-PROTOCOL.md` (the loop) and `14-PHASE-01-ACCEPTANCE-CRITERIA.md` (the finish line, A–H).
4. `docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md` (decisions D-01…D-05).
5. `.claude/skills/store-syria-laravel-tenancy/` before touching a controller, policy, query or route.
6. The ledger row and GitHub issue of the task you pick up.

## 1. Project identity and architecture [VERIFIED]

Multi-tenant Laravel 11.47 / PHP 8.3 e-commerce SaaS on the vendored **WorkDo eCommerceGo** platform (70 modules in `packages/workdo/`).
Shared database, `store_id` discriminator. Second repository `fullstackfull/StoreSyria_app98` is the shipped **admin Flutter app**
(base URL `https://storesyria.com/api/admin`; it **force-logs-out on any 401**, so refusals are **403**; `ApiResponser::error()` answers HTTP 200 `{status:0}` by default).
Blade + Tailwind storefront, Arabic (RTL) + English (LTR). Production is MySQL with `CACHE_STORE=database` and **`strict => false`**
(`config/database.php`); tests run SQLite `:memory:`.

## 2. Branch, commit and PR state [VERIFIED at b0911661; the handoff commit sits on top of it — run `git log -3`]

| | |
|---|---|
| Phase 01 branch (CLAUDE.md, PR #3 head) | `claude/store-syria-p01a-ci-foundation` |
| Session branch the harness assigned | per session. `claude/wonderful-gates-60khob` (the 2026-10-21 session) and `claude/modest-tesla-r74de7` (2026-10-22) — **keep identical to the branch above**. `[2026-10-22]` A harness branch is assigned fresh each session and is NOT created on the remote until you push it: `git fetch origin <new-name>` fails until then, which is expected and is not a lost push. |
| `main` | `1eebc721`, **never modified**; do not merge |
| PR | **#3, Draft, never merged**; title starts `⛔ DO NOT MERGE` |
| Milestone | #1 "Phase 01 — Foundation & Stability" |
| CI | `[2026-10-22]` run 39745532680 on `cdc213bd` **FAILED**: `Tests (REQUIRED -- clean install from composer.lock)` went red because `cdc213bd` added `ModuleStorefrontInputGuard` to the middleware stack and recorded its five raw-query sites in NONE of the three inventories (`OwnedPathRawQueryInventoryTest`, and two totals in `RawQueryTenancyInventoryTest`). Fixed in this session's commits. Earlier: run 39659848857 on `b0911661` `success`; run 39638907346 on `977e61b6` `success`. **Read CI back on the head you pushed — do not infer it from an older run.** |

**Branch sync rule.** Both branches carry the same commits. Before pushing: `git fetch origin` then
`git merge-base --is-ancestor origin/<branch> HEAD` for each; push `HEAD:refs/heads/<branch>` to both; **fast-forward only, never force**.
Other remote branches (`store-syria-p01b-design-foundation`, `lynomia-*`, old `claude/*`) belong to other work; do not touch them.

**PR #3 body is a SHORT FORM on purpose.** A full rewrite of the description was once cut off mid-text by a safety classifier and replaced the whole
body with a prefix; the write was not retried. The full text of §1–§15 is archived byte-identically in
`docs/development/phase-1/PR3-DESCRIPTION-ARCHIVE.md` (commit `960007dd`). §16 on the PR page itself covers `960007dd..b0911661` and says what was **not**
re-reviewed. The attestation workflow runs on PR `edited` and needs the literal line `SECURITY-DIFF-REVIEW: completed`. Any commit after `b0911661` — including the handoff commit
(docs and CLAUDE.md only) — is outside the attestation until a section covers it: extend §16 with a few lines and **read CI back, do not infer it**. Never send a very large body in one write.

## 3. Phase 01 finish line

Criteria A–H are in `14-PHASE-01-ACCEPTANCE-CRITERIA.md`; **only the owner may approve closing the phase**; Phase 02 does not start without that approval.
The finite checklist is §6 below. Phase 01 is *Foundation & Stability*, not a perfect platform.

## 4. Task counts (authoritative ledger = `phase-01-tasks.json`) [VERIFIED]

`[2026-10-22]` **105 tasks: 35 DONE · 25 IN_REVIEW · 9 READY · 9 BACKLOG · 27 BLOCKED**; 32 carry `owner_decision: true` (some of those are READY/DONE and only *need* an owner input).

**The drift below is FIXED.** It was far larger than this section described: between `b0911661` (where this file was written) and `cdc213bd`,
**24 commits** closed or deferred work and updated **neither** the ledger nor the issues. Reconciled on 2026-10-22:

* **21 rows moved READY/BACKLOG → `IN_REVIEW`** with the implementing commit named in the row's evidence: P1-INV-09, -11, -13, P1-ORD-05, -10,
  -12, -14, P1-PLT-02, -06, P1-SEC-04, -06, -09, -10, -11, -12, -14, -16, -22, -28, -30, -31. `IN_REVIEW` and not `DONE` on purpose: the
  reconciliation named the commit and re-ran the gates, it did **not** re-verify each task's acceptance criteria. The owner accepts them.
* **2 rows moved READY → `BACKLOG`**: P1-INV-16 and P1-ORD-06, which `b1517206` deliberately deferred (the auction-order path is dormant —
  its only writer is the unscheduled `auction:order` command, owner decision D-03) while leaving the ledger saying READY.
* **4 tasks had NO row and NO issue at all** and now have both: **P1-SEC-35 (#113, CRITICAL** — `/duologin` logged an anonymous caller in as
  any account, operator included), **P1-INV-23 (#114)**, **P1-SEC-33 (#115)**, and **P1-SEC-34 (#112)**, the follow-up that closes the two
  findings `cdc213bd`'s own message recorded as deferred.
* Issue hygiene: #63 closed (was DONE in the ledger, still open), #71 closed, #66 labelled, #42's `status:ready` corrected to
  `status:blocked`, and a `status:*`/`ws:*`/`risk:*` set put on the 16 issues that carried none. The 29 stale statuses in the ledger's own
  **Execution order** list were refreshed from the JSON, and four entries appended (73–76).

The original note read:

* #63 (`P1-INV-17`) is DONE in the ledger but the issue is still open — close it with the evidence.
* #66 (`P1-TOOL-01`, tooling standardization, DEFERRED) and #71 (reserved-role-name gates, fixed) are open issues with no ledger row — add rows or close with a note.
* Sixteen issues carry **no `status:*` label**: #50–#56, #59–#63, #67–#70 (ledger says READY/BACKLOG/BLOCKED/DONE as appropriate). #42 (`P1-OWN-01`) is labelled `status:ready` but the ledger says BLOCKED.
* Check with: the ledger JSON vs `search_issues` (`repo:fullstackfull/syriastore98 is:issue is:open`). **That call returns ~700 KB; the tool saves it to a file — parse it with Python, never print it.** `[2026-10-22]` `list_issues` with `fields: ["number","title","state","labels"]` and `perPage: 100` returns the whole open set compactly and is the cheaper check.

`[2026-10-22]` **How to find this drift yourself next time, in one command** — every commit on the branch whose subject starts with a task id,
cross-referenced with the ledger's status for that id:

```bash
git log --format='%H%x09%s' origin/main..HEAD   # then match ^P1-[A-Z]+-[0-9]+ in the subject against phase-01-tasks.json
```

Two commits use a parenthetical form (`P1-SEC-11 (shipping label): …`, `P1-SEC-22 (a)(b): …`), so a pattern that demands the colon straight
after the id misses them. That is how P1-SEC-11 and P1-SEC-22 were nearly missed again.

## 5. What is done (grouped; evidence is in each ledger row) [VERIFIED]

* **CI & gates** (P1-R-01): known-outcome gate (2 declared failures `RegistrationTest`/`ProfileTest`, 17 declared skips — `.github/known-test-outcomes.json`), five guards, merge-base regression gate, security attestation.
* **Tenancy/authz families**: customer-id-from-request family (R-02), role escalation (SEC-02), session hygiene (SEC-01), product/variant/category ownership (INV-01/02/04/07/08/14/17/18/19), refund family (ORD-07/08), cart family (SEC-21/23/24), order cancel (ORD-04).
* **Credential exposure closed in code**: visit log body capture (SEC-13), `payment-list` secrets (SEC-15), browser-handed credentials (SEC-17), ChatGPT keys (SEC-19), setting reads/writes (SEC-18/25), **by-name settings writers (SEC-27)**, **host takeover (SEC-29)**.
* **SEC-27 / SEC-29 are the most recent and the most review-hardened**: `App\Services\StoreSettingsAccess` (operator-first `permits()`, restricted-name classifier, domain-routing neutralisation) and `App\Services\HostClaims` (Setting `saving` hook + `HostClaims::row()` for the six host lookups in `app/`). Read their ledger evidence — three MySQL-only holes were found *after* green suites (see §9).
* Design System foundation + contrast gate (R-04), GATE F containment (R-06; the six money tables stay unrunnable until the owner releases them), MySQL validation harness (PLT-01).

## 6. Finite Phase 01 finish line [PROPOSED — confirm each row against its issue's acceptance criteria; nothing critical or financial is deferred silently]

### 6a. MUST finish in Phase 01 (release-blocking or required by criteria A–H), in dependency/priority order

> `[2026-10-22]` **Most of the "High, code-doable now" list below is now IN_REVIEW, not READY.** Done since this was written:
> P1-SEC-06, -12, -14, -28, -31 (and -04, -09, -10, -11, -16, -22, -30), P1-ORD-05, -10, -12, -14, P1-INV-09, -11, -13, P1-PLT-02, -06.
> P1-INV-16 and P1-ORD-06 went to BACKLOG as dormant (D-03). **Two more left READY moved to BLOCKED**, because every one of their
> acceptance criteria needs the owner or the server and carrying them as READY invited a session to start work it must not do:
> **P1-INV-12** (#53 — its engineering deliverable is already committed; what is left is the live `DELETE_RULE` check and the owner's
> choice between cascade / `SET NULL` / refuse-while-non-empty, and changing an FK delete rule is a migration against live catalogue
> data) and **P1-OWN-07** (#56 — a read-only reconciliation of residue on the live database, which §6d already classified as production
> verification while the ledger said READY).
>
> **The 7 rows still genuinely READY are:** P1-SEC-26 (#99), P1-SEC-05 (#19), P1-ORD-13 (#82), P1-PAY-01 (#23), P1-PLT-07 (#110) and
> P1-INV-15 (#60, which carries `owner_decision: true` — read it before starting), all medium, plus P1-INV-10 (#51, low). The BACKLOG
> rows the acceptance criteria still demand (P1-ORD-01/-02, P1-PAY-02, P1-INV-03/-05, P1-PLT-05, and P1-PLT-03's in-or-out decision) are
> unchanged.
High, code-doable now (`READY`): **P1-SEC-06** SSRF in four import handlers (#55) · **P1-SEC-12** storefront account area has no ownership check (#84) · **P1-SEC-14** error tracker is sent cookies/session/input (#89) ·
**P1-SEC-28** unlinks bypassing `StoredUpload` (#102; fold in BACKLOG **P1-INV-09** #50) · **P1-SEC-31** vendored module writers of `settings`, host-side control (#107) ·
**P1-ORD-05** order read family, forgeable encrypted id (#59) · **P1-ORD-06** auction status endpoint POSTs order PII to a merchant URL (#62) · **P1-ORD-14** refund restore reads by line position (#83) ·
**P1-INV-12** category delete cascades products (#53) · **P1-INV-13** import family unscoped mapping + transfer primitive (#54) · **P1-INV-16** auction return restocks an unscoped Product (#61) · **P1-PLT-02** order writers persist NULL `store_id` (#34).
Required by the acceptance criteria (currently `BACKLOG`, high): **P1-ORD-02** one server-authoritative order total (C1) → **P1-ORD-01** coupon charged = coupon printed (C2) · **P1-PAY-02** idempotency of gateway returns (D2) ·
**P1-INV-03** POS writes absolute stock · **P1-INV-05** oversell/concurrency, **on MySQL** (E3/E4) · **P1-PLT-05** observability (F7) · **P1-PLT-03** tenant-scope adoption (high; the criteria do not demand it by name — **owner/engineering decision whether it is in or out; do not drop it silently**).
Medium, cheap or security-relevant (`READY`): P1-SEC-04, -05, -09 (report handlers unauthorised), -10 (order-existence oracle), -11, -16, -22, -26, -30 · P1-INV-11, -15 (return robustness, double credit) · P1-ORD-10, -12, -13 · P1-PAY-01 · P1-PLT-06 (a Home/Checkout save removes the merchant's custom domain at the hosting panel).

### 6b. Safe to defer to Phase 02+ if the owner agrees [PROPOSED]
P1-INV-10 (low, catalogue cluster) · P1-PLT-07 (staff save re-stamps `created_by`; a correctness annoyance, not an exposure) · anything cosmetic. **Deferring needs a ledger edit that says so**; absence from §6a is not a deferral.

### 6c. Needs OWNER approval or an owner action (do not start; list is in §8)
Production secrets, server logs, migrations (GATE F), financial policy, vendor/upstream, retention, branch protection.

### 6d. Needs PRODUCTION verification (the repository cannot show it)
P1-OWN-01 (BackupRestore disabled?), -02 (APP_KEY and four tracked secrets are in git history, unrotated), -03 (compromised API log on the server), -04 (tokens never expire; every token is `['*']`), -07 (data residue from the old WooCommerce transfer primitive — read-only),
-08 (`shetabit_visits` rows already hold plaintext passwords/cookies), -09 (every store's gateway secrets were readable by any visitor), -11 (live `settings` indexes/duplicates), P1-PAY-03 (credentials for verification), and every MySQL claim below was measured on **MySQL 8.0.46 only** — confirm production's version, collation and `sql_mode`.

**Do not claim production readiness** while 6c/6d items are open. The acceptance report must keep "code ready for acceptance", "owner approvals", "operational containment / production verification" and "deferred" as four separate lists.

## 7. Owner decisions

### 7a. Recorded and approved [OWNER-APPROVED] — do not re-ask
* No merge into `main`; no deployment; no persistent migrations; no destructive data operations; no edits under `packages/workdo/` (except first-party `LynomiaChat`); PR #3 stays Draft. (`CLAUDE.md` §2, protocol §5–6.)
* **D-01** `SESSION_SAME_SITE` is **not** switched to Lax until a gateway-callback/session matrix and a payment-state strategy exist. **D-02** the lottery payment-method rewrite is not enabled; only a refuse-only allowlist may ship. **D-03** `auction:order` stays unscheduled. **D-04** the #69 upstream WorkDo report is drafted (`docs/upstream/WORKDO-REPORT-email-template-tenancy.md`), **not sent**. **D-05** do not trust a recorded `Paid` state without verified settlement evidence (#67/#68). (`15-DEFERRED-OWNER-DECISIONS.md`, with the owner's verbatim words.)
* #69: host-side tenant-safe email-template solution, report not submitted. #70: verify payment-method mismatch by tests, document, smallest safe fix, do not activate unreachable flows. Owner-gated financial/schema changes stay BLOCKED with dependencies and acceptance criteria in the issue and ledger.

### 7b. Said by the owner on 2026-10-21 and NOT yet reflected in the ledger [OWNER-STATED]
* Payment must be **verified before financial settlement**. · **COD is considered paid only after verified collection.** · **A refund request must not immediately create a completed refund.** · Financial records are preserved according to applicable legal obligations. · Preserve existing data and API contracts; keep merchant data isolated.
* Where they land: COD → `P1-PAY-04` (#26, BLOCKED "COD settlement timing", also blocks `P1-MIG-01`); refund request → `P1-ORD-11` (#75, BLOCKED "a request rewrites totals at request time"); retention → `P1-ORD-03` (#22, BLOCKED "`user_delete` hard-deletes orders"); verified settlement → `P1-PAY-03` (#25), `P1-INV-21` (#68).
* **Ambiguities that must be listed, not decided by engineering:** what counts as "verified collection" for COD (delivery-boy confirmation? merchant mark? both?) · whether a refund *request* may record a pending amount on the order or only a separate record · the retention period and whether account deletion anonymises or keeps the customer row · which gateways to enforce callback authenticity for first (and Khalti credentials). **First action: record 7b in the four ledger rows/issues as "owner direction received, details open", set nothing READY until the details are answered.**

### 7c. Pending owner decisions (each has a GitHub issue; the question is in its `blocked_on`)
Critical: **P1-OWN-08** (#86 scrub or purge `shetabit_visits`, notify customers) · **P1-OWN-09** (#88 tell merchants to rotate gateway keys; read-only access-log check).
This session: **P1-SEC-32** (#111 who may hold a host: platform host, reserved labels, DNS proof, release path) · **P1-OWN-11** (#106 three read-only queries on the live `settings` table; unique key?) · **P1-OWN-12** (#108 may `Manage Store Setting` alone put script on the checkout page? a/b/c).
Orders/payments: P1-ORD-09 (#73 order-link credential design; sequence with APP_KEY rotation) · P1-ORD-11 · P1-ORD-03 · P1-PAY-03 · P1-PAY-04.
Operations: P1-OWN-02 (#43) · -03 (#44) · -04 (#45) · -05 (#46) · -06 (#47) · P1-OWN-10 (#103 407 uploaded files in git history).
Schema: P1-MIG-01 (#38) · -02 (#39) · -03 (#40) · -04 (#41).
Vendor: P1-INV-06 (#32) · P1-INV-20/21/22 (#67/#68/#70) · P1-SEC-07 (#69) · P1-PLT-04 (#36) — upstream report vs host-side patch.
**Silence is not approval.**

## 8. Verified security risks and production concerns

[VERIFIED by execution unless marked] — details in `docs/security/P01-SECURITY-WORK-QUEUE.md` (S1…S46) and the ledger.
* **Still exposed until the owner acts (code cannot undo it):** gateway secrets already disclosed (OWN-09), plaintext credentials already in `shetabit_visits` (OWN-08), `APP_KEY` in git history so `Crypt::encrypt()` is an encoding not a control and encrypted order links are forgeable (OWN-02, ORD-09), Sanctum tokens never expire (OWN-04), 407 user uploads in history (OWN-10).
* **Open in code (READY):** §6a. Notably SSRF (SEC-06), no ownership check on the storefront account area (SEC-12), order PII posted to a merchant-chosen URL (ORD-06), report handlers with no authorisation (SEC-09).
* **Known, recorded, not fixable host-side:** vendored module writers/readers (SEC-31; one vendored ThemeCustomizer host lookup stays unscoped), lottery payment verification (INV-20/21: `purchase_tickets` has no payment-status column), email-template tenancy across six modules (SEC-07).
* **Honest limits of what was closed:** the `HostClaims` hook covers Eloquent writers only (a raw `DB::table('settings')` write bypasses it; the five raw writers in `Api\DashboardController` write literal non-host names); no unique key on `settings`; `Landing` lost the Setting model cache on two lookups (estimate, unmeasured).
* [PENDING] P1-SEC-03 and P1-INV-04 findings were recorded but not verified by execution (criteria H).

## 9. Tests, CI and the limits that have bitten

Commands (protocol §4 is authoritative): `php artisan test --testsuite=Unit|Contract|Feature` and `/tenant-check`; gate: `php .github/scripts/assert-known-test-outcomes.php storage/logs/feature-junit.xml .github/known-test-outcomes.json storage/logs/feature-teamcity.txt`.
Measured at this session: Unit 339 · Contract 26 · Feature 3629 passed / 2 failed (the two declared) / 17 skipped (first-fix tree) · Security+Tenancy directories 2397 passed / 10 skipped (final tree, ~6.5 min) · full Feature ~9 min · `HostClaimsTest` 106 tests, ~6 s.
`CLAUDE.md` and protocol §4 quote older counts (190/278); trust a fresh run.

* **SQLite is blind to MySQL behaviour.** Measured on MySQL 8.0.46, `utf8mb4_unicode_ci`, `strict => false`: names compare **case-, accent- and PAD-SPACE-insensitive** (`dómains` = `domains`, `domains ` = `domains`); control bytes (0x00–0x08, 0x0E–0x1F, 0x7F) are ignorable; printable ASCII folds by case only (94×94 pairs, 94³ contractions measured); `settings.name` is `VARCHAR(255)` and non-strict MySQL **stores a longer name cut to 255 characters** (so `domains`+300 spaces+`x` *is* `domains`) and **ends a name at the first invalid UTF-8 byte** (and, under Laravel's native prepared statements, a `SELECT` on that raw string answers NULL — asking the engine does not catch it); `where id = '5abc'` matches id 5; `where name = 0` matches every non-numeric name; admin-API writers use MySQL-only `insert … on duplicate key update`. Scripts: `tests/Support/Security/bin/` (`run-mysql-verifications.sh` runs all; each creates and drops its own database; **never point one at the dev/prod server**). The cloud sandbox can host a throwaway MySQL 8.0 (`apt-get install -y mysql-server-8.0 && service mysql start`); in the session that wrote this it listened on `/var/run/mysqld/mysqld.sock`.
* **No WorkDo module loads in a test run** unless the test inserts an `add_on_managers` row — the suite is structurally blind to module routes; enable through the production loader and add a premise test.
* **A Customer-token probe cannot test the `custom.auth` trap** — drive a merchant and a driver token too. A plain `Customer::find()`/`Cart::find()` in a test answers for the *resolved tenant*, not the table: assert through `withoutGlobalScopes()`. Raw `DB::table('users')->update()` leaves `Cachable` `User` stale.
* **Local sandbox noise, not defects:** `guard-composer-lock-integrity.sh` exits 1. `[2026-10-22]` **The two packages it names have changed.** `myfatoorah/library` and `myfatoorah/laravel-package` now install fine; what fails now is **`yoomoney/yookassa-sdk-php`**, because its own manifest pulls `yookassa-sdk-validator` from **`git.yoomoney.ru`**, which this container's network policy denies (`CONNECT tunnel failed, response 403`). `composer install` is all-or-nothing, so it aborts and never writes `vendor/autoload.php` — i.e. **no suite runs at all** until you deal with it. What worked: drop `yoomoney/yookassa-sdk-php` from `composer.json`'s `require` AND its entry from `composer.lock`'s `packages`, `composer install --no-scripts`, then **restore both files from the backups you took first** and `git diff --stat composer.json composer.lock` to prove they are byte-identical again. `vendor/` is gitignored, so a vendor built that way is safe to test against — but the lock guard will correctly report the 2-package drift, and any claim about the locked dependency set belongs to CI, not to that tree. The app does not need the package to boot: `app/Payments/RetiredGateways.php` treats YooKassa as retired. The alternative is to have the owner add `git.yoomoney.ru` to the environment's **Allowed domains** (cloud environment menu → Edit → Network access). `guard-no-workdo-changes.sh` needs a base-ref argument. Do not run two suites against one tree, and do not edit files while a suite runs.
* **Review findings are claims.** Two read-only review rounds (21–35 agents each) found real HIGH holes in the SEC-29 fix; the one that mattered most (invalid UTF-8) was found by *running the MySQL script*, not by any reviewer. A review that only READ cannot close a MySQL question — execute it.
* A previous claim that "the Contract suite proves the staff 403s" was **withdrawn**: that suite drives no staff principal.

## 10. Skills, agents and tools

Committed and portable (do not depend on account-level installs): `.claude/skills/` — `store-syria-laravel-tenancy`, and the vendored `test-driven-development`, `systematic-debugging`, `verification-before-completion` (provenance and hashes in `.claude/skills/PROVENANCE.md`); `.claude/agents/tenant-authz-reviewer.md` (read-only; use before committing authz/tenancy/money/stock changes); `.claude/commands/tenant-check.md`; `.claude/hooks/guard-protected-paths.php` (refuses edits under `packages/workdo/` except `LynomiaChat`, and secret-bearing files). Account-level skills (e.g. `frontend-design`) may be absent — never depend on them. Tool evaluations and the nine prohibitions: `docs/tooling/SKILLS-MANIFEST.md`, `SKILL-USAGE-POLICY.md`. `gstack`, `claude-mem`, `strix` must not be installed; `context7` for public framework docs only.

Process tools added with this handoff (`docs/development/phase-1/tools/`):
* `ledger-tool.py` — **use this, not `render-ledger.py`**. `render-ledger.py` re-renders the *whole* ledger and does **not** reproduce the committed file (~440 lines differ at b0911661), so protocol §3.11 as written would rewrite hand-kept evidence. `ledger-tool.py check` reports which blocks it reproduces byte-for-byte (78 of 101; it lists the 21 older hand-kept ones, which must be read before being amended, and 2 tasks — `P1-R-04`, `P1-OWN-07` — that have no `###` block); `amend <ID> '<json>'` replaces fields of one block + the JSON entry and refreshes the header counts; `issue-body <ID>` prints the GitHub issue text.
* `mutation-harness.py` — the "a test must bite" removal check (one textual mutant at a time, restores files, reports BITES / SURVIVED). **While it runs the tree holds mutated code: do not edit, commit, run another suite or start a read-only review.** If it must be stopped, restore from backups and `cmp` before anything else. A survivor is a test gap or an equivalent line: close the gap or delete the line.
* The `Workflow` tool (multi-agent) was used for read-only adversarial review: lenses (bypass / regression / evidence) → findings → two default-refute skeptics each. It reads the working tree, so **do not mutate or move the tree while it runs**. Cost: 21–35 agents, 25–40 minutes.
* GitHub: use the GitHub MCP tools (no `gh`). Issues carry labels `phase-01`, `ws:<workstream>`, `status:<state>`, `risk:<level>` and a footer `_Generated by [Claude Code](https://claude.ai/code)_`.

## 11. Rejected approaches (do not retry)
* **Deny-list of setting names** — bypassed by case/accent/space/length/UTF-8 spellings; the design that shipped puts the *permission* on the route and keeps the name rule only where a lower permission legitimately reaches a generic writer. A per-writer allow-list was deliberately **not** built (it would silently drop unlisted fields of nine screens) — recorded in P1-SEC-27's evidence.
* **Deciding in PHP what the database decides in SQL** (names, flag values, host spellings). Judge the value *as stored*, and ask the engine only for what PHP cannot know.
* **Globally switching `SESSION_SAME_SITE` to Lax** (D-01) · **returning 401 for a refusal** (Flutter logs the user out) · **editing `packages/workdo/`** · **adding to `known-test-outcomes.json` to get green** · **re-rendering the whole ledger** · **rewriting history for the uploaded files** · **one giant PR-body write**.

## 12. Continuing in a fresh Claude Code account — exact steps
1. `git clone` the repo; `git checkout claude/store-syria-p01a-ci-foundation` (or the branch the harness gives you — then keep both branches identical, §2). `composer install` (two `myfatoorah/*` packages may be unreachable; CI has them). Read §0.
2. `git fetch origin`; confirm the heads of both branches equal; `git log -5`; read CI on the head with `actions_get`/`pull_request_read get_check_runs` **before** claiming anything about it.
3. ~~Fix the ledger/GitHub drift in §4 (labels, #63, #66, #71)~~ `[2026-10-22]` **DONE — see §4.** Still owed: **record §7b** (the owner's
   2026-10-21 directions) in the four rows/issues it names, which is a documentation edit that unblocks nothing by itself and was NOT done.
4. Produce the checkpoint for the owner: HEAD, counts, the §6 classification confirmed or corrected, owner-gated list, finite plan. **Then** take READY tasks in the order of §6a, one at a time, following protocol §3 (reproduce → RED test → smallest fix → removal check → `tenant-authz-reviewer` → gate → commit with the task id → push both branches → issue + ledger). No parallel edits to one tree.
5. Before any push: Unit, Contract, Feature + `assert-known-test-outcomes.php`, the guards, and for anything schema- or collation-sensitive the MySQL scripts.
6. When §6a is done, write **one** consolidated acceptance report with the four lists of §6 kept separate, keep PR #3 Draft, and stop for the owner.

**Exact first instruction to give the next account:**
> Continue Store Syria Phase 01. Read `CLAUDE.md` and `docs/development/HANDOFF-CURRENT.md`, then reconcile the ledger with GitHub as §4/§12 say, give me the checkpoint (HEAD, task counts, release-blocking vs deferrable vs owner-gated, finite completion plan), and then continue the READY tasks in dependency and security-priority order without restarting completed work. No merge into main, no deployment, no migrations, no production data changes, no edits under `packages/workdo/`, no force-push; keep PR #3 Draft.

## 13. References
`CLAUDE.md` · `.claude/README.md` · `docs/development/phase-1/{12-PHASE-01-TASK-LEDGER.md, phase-01-tasks.json, 13-…PROTOCOL.md, 14-…ACCEPTANCE-CRITERIA.md, 15-DEFERRED-OWNER-DECISIONS.md, 07-OWNER-APPROVAL-GATES.md (dated, not appended to), PR3-DESCRIPTION-ARCHIVE.md}` ·
`docs/security/{P01-SECURITY-WORK-QUEUE.md, P01-TOKEN-EXPIRY-PROPOSAL.md, P01-OTP-LENGTH-COMPATIBILITY.md, P01-SESSION-HYGIENE.md, SECRET-ROTATION-CHECKLIST.md, P01-OWN-01-SERVER-VERIFICATION-RUNBOOK.md}` ·
`docs/GATE-F-SCHEMA-EXPORT.md`, `database/migrations/_gate_f_pending/README.md` · `docs/tooling/` · `docs/design-system/README.md` ·
GitHub: milestone #1; PR #3; issues #9–#111 (task number ↔ issue number is in each ledger row); most relevant now: #86, #88 (critical owner), #111, #106, #108 (this session), #101/#104 (closed work with full evidence).

## 14. Assumptions a new session must NOT make
* `[2026-10-22]` That a task's ledger status reflects the branch. It did not for 24 commits, and the four most interesting findings of the
  2026-10-21 session had no row and no issue at all — including a CRITICAL anonymous login bypass. Cross-reference `git log` against the JSON
  (§4) before trusting either. · That a green CI run on an older head says anything about the head you just pushed: `cdc213bd` added a
  middleware and left three raw-query inventories unrecorded, and CI was red on it the whole time this file said CI was `success`.
* That `CLAUDE.md` test counts are current. · That `Product` (or `Order`, `Wishlist`, `DeliveryAddress`, `User`) is tenant-filtered by a global scope. · That a `-guest` route is public (they carry `custom.auth`). · That GitHub status labels match the ledger (they do not, §4). · That a green CI means no failures (2 are declared). · That SQLite results transfer to MySQL. · That an unchanged-looking `Setting` save cannot change what a lookup reads. · That account-level skills or plugins exist. · That an owner direction in §7b is a decision on its details. · That any BLOCKED task may be started because it looks easy.
