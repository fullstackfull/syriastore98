# Phase 1 Gap Closure — Verified Linking and Resumable Provisioning

**Status:** both workflows complete and passing. **Not merged, not deployed, no live channel activated, Phase 2 not started.**
**Branches:** `claude/store-syria-lynomia-integration-1oa1tt` in both repositories.

---

## 1. Commit IDs

### Store Syria — `fullstackfull/syriastore98`

| Commit | What |
|---|---|
| `e66e5c8a` | `feat(lynomia): finish existing-workspace linking, and make provisioning resumable` |
| `e002ecdd` | `fix(ci): exempt first-party modules from the vendored-workdo guard, by name` |
| `2849445d` | `fix(lynomia): three orphaning paths found by adversarial review` |
| `87aec2f3` | `fix(lynomia): lost update, displaced grants, dead state and an unfenced lock` |
| `0201c003` | `fix(lynomia): check the service identity instead of asserting it cannot be taken` |
| `39d1334a` | `test(lynomia): fix four assertions that proved less than their names claimed` |
| `92f606ef` | `fix(lynomia): fence a claim on the workspace it displaces, not only on state` |

### Lynomia Chat — `fullstackfull/lynomiachat`

| Commit | What |
|---|---|
| `ba7335a6` | `feat(integrations): prove account ownership on the Lynomia side before an external app may link` |
| `4e9c77e0` | `feat(integrations): let an administrator mint a link code from the dashboard` |
| `d0ab4796` | `test(integrations): pin the cross-repo wire contract for the account filter` |
| `63069ac3` | `fix(integrations): grant a scoped service user, not the person who authorized it` |
| `dd0b4e4b` | `fix(integrations): derive revocation from the grants held, not the account's membership` |
| `679a77ce` | `test(integrations): fix three specs that proved less than their names claimed` |
| `b4d64e06` | `fix(integrations): name the app that took the code, so a misdirection is visible` |

The Lynomia branch was restarted from `origin/lynomia-custom` @ `86a43252`: the branch of that name already existed locally but carried only already-merged history.

---

## 2. Gap 1 — linking an existing workspace

### What was wrong

The claim flow was safe and unfinished. It took the merchant's word for an account id, stored a pending row, and waited for a human. It granted nothing — but nothing in it ever learned a Lynomia **user** id, and SSO is minted per user, so an approved claim was a row that could not be opened.

The missing half was never an approval step. It was a way to find out, truthfully, who the claimant is.

### Why it had to be built on the Lynomia side

Store Syria cannot establish this by itself, and the two obvious ways of trying are both wrong:

- **By email** — `POST /platform/api/v1/users` resolves `User.from_email(...) || User.new(...)` and grants the caller a permissible on whatever it resolved. Handed a real customer's address that is not a link, it is a silent account takeover.
- **By account id** — a platform token reaches only accounts it already holds, so Store Syria cannot check an id it is given. It makes the *claimant* the source of truth about their own identity.

### What was built

```
POST /api/v1/accounts/:id/integrations/account_link_code   an administrator mints a code
POST /platform/api/v1/account_links                        the app spends it, server to server
DELETE /platform/api/v1/account_links/:id                  the app hands every grant back
```

`Integrations::AccountLinkCode` is modelled on the production `Commerce::Salla::ConnectionCode` almost line for line — same alphabet, same 80 bits, same HMAC-and-forget storage, same single-use `MULTI(GET, DEL)`. That service solves the identical problem in the opposite direction; this was not the place to invent a second scheme.

**The role is re-read at redemption**, which is the one place this deliberately differs from the Salla original. A code lives an hour, an administrator can be demoted inside an hour, and a bearer token minted before a dismissal must not survive it. The code is burned *before* the check, so a refused code is not retryable until its author happens to be promoted again.

**The merchant has a dashboard page** (Settings → Connect a platform). Without it they could only mint a code by calling the API, which in practice means not at all.

### The requirement you set, and where each half is enforced

| Requirement | Enforcement |
|---|---|
| Ownership verified Lynomia-side | The code can only be minted by someone who authenticated to Lynomia as an administrator of that account |
| Authorized account membership verified | The administrator role is re-read from `account_users` **at redemption**, not trusted from the code |
| Never by email matching | No email field exists on this path. Neither does an account id — the form takes a code and nothing else |
| No unauthorized impersonation | The grant goes to a **service user scoped to one account**, never the human (see §4) |
| Operator-approved | `ownership_proven_at` (Lynomia's answer) and `verified_at` (the operator's) are separate columns, and `approve()` refuses a row without the first |

---

## 3. Gap 2 — resumable, idempotent provisioning

### What was wrong

`provision()` returned early whenever a row existed and wrote all three results together at the end. A failure anywhere left a row with no ids and no record of what had been created, and every retry returned that stale row untouched. Its own comment claimed "the next attempt resumes against the same row". It did not.

Worse, the transport retried `POST /accounts` on a dropped connection. A read timeout is exactly the case where the server did all the work and only the answer was lost — so **the retry created a second workspace**. That was a live duplicate-account vector, not a theoretical one.

### Four properties, each paid for somewhere specific

| Property | How |
|---|---|
| **Repeated requests** | A finished link answers from the row and reaches Lynomia not at all. An unfinished one resumes from the first id it is missing |
| **No duplicate accounts** | Account creation is no longer transport-retried (retry is opted into per call, only where a second execution is a no-op), and every attempt first asks Lynomia whether this store already has a workspace. Each is stamped with the store **and the installation**, so a create whose answer was lost is *adopted*. Two candidates is a refusal, never a guess |
| **No orphaned mappings** | Every id is persisted in the same breath as the call that produced it. A process killed between calls leaves a row that knows exactly what exists |
| **One at a time** | A conditional `UPDATE` on `provisioning_started_at` decides which of two concurrent callers proceeds. The claim expires, so a killed process cannot wedge the store; it is fenced by its own timestamp, so an overrunning attempt cannot release its successor's claim |

Two subtleties worth your attention because no test on SQLite could catch either:

- **`provisioning_attempts + 1` in the lock's `SET` list is load-bearing.** The lock reads the affected-row count, and the databases do not count the same thing — SQLite returns rows *matched*, MySQL returns rows *changed*, because `PDO::MYSQL_ATTR_FOUND_ROWS` is set nowhere. With only a timestamp, two attempts in the same second would write an identical value and MySQL would report 0, telling the winner someone else held the claim.
- **The installation reference is not decoration.** Store ids are per-database autoincrements. Restore a production dump into staging — routine — and staging's store 42 *is* production's store 42. Without it the lookup did not fail closed: it found exactly one candidate and adopted it, signing a staging merchant into a production workspace.

---

## 4. The adversarial review, and what it found

I ran a six-dimension adversarial review across both repositories after the work was written, with every finding independently verified. **24 findings were reported across six dimensions; 14 were real defects in code I had just written and described as well-defended.** All are fixed; each has a regression test that goes red when the fix is reverted.

The two that matter most:

**The grant was wider than the consent.** `generate_sso_link` mints an *unscoped* Devise session for a human. Granting the authorizing administrator's own identity therefore cashed a proof about one account in for the ability to act as that person in **every account they belong to**. Measured, not reasoned: with a grant on a user who administers account L and is an agent of unrelated account S, the redeemed session read `/api/v1/accounts/S/conversations` successfully. S consented to nothing.

The grant now goes to a service user that belongs to that one account. **The trade-off is real and is yours to accept or reject:** a merchant opening their workspace through SSO now arrives as the integration's user rather than as themselves.

**An operator's approval could be silently repointed.** `assertClaimable` ran against a snapshot taken before a remote call that can take fifteen seconds, and an operator could approve inside that window. A plain `save()` writes only the columns dirty relative to that stale snapshot — `state` matched in both, so it was never in the `UPDATE` — and the operator's `state=active` survived while the ids they approved were replaced. An ACTIVE link, carrying their `verified_by`, to a workspace nobody approved.

The other twelve: a claim could abandon a half-finished provision and orphan its workspace; a refused claim revoked an innocent store's live grant; re-claiming orphaned the workspace it displaced; revocation derived its set from mutable membership, so a granted user who left kept their grant; the SSO sweep killed other platform apps' and super-admin impersonation links; two concurrent mints left two live codes; a concurrent redemption 500'd and burned the code; the provisioning service identity could be pre-registered and adopted as administrator; revocation was a one-way door with no restore; a second concurrent claim over one pending row orphaned a workspace; and four of my own test assertions proved less than their names claimed (see §5).

### Where I was wrong earlier

Two of these I had considered during Phase 1 and dismissed:

- **Installation identity.** I left it as a documented operational requirement. That was wrong: the failure is silent and the trigger (restoring a dump) is routine.
- **Code-to-app binding.** I accepted that any PlatformApp can redeem any code, reasoning that all apps are operator-created. The review's version is better — a merchant pasting a code into the wrong integration's field hands it a permanent grant, and neither side shows it. **This one is still open** (see §7).

---

## 5. Test results — what actually ran

Every figure below is from a run executed in this session. The Lynomia Ruby suite had never been runnable in this container; Ruby 3.4.4, the gem set, Postgres 16 with pgvector, and Redis were all built here so these are real runs, not projections.

### Store Syria

| Suite | Result |
|---|---|
| `tests/Feature/Lynomia/LynomiaConnectionTest` | **62 tests, 263 assertions, all passing** (was 25) |
| Unit | **173 tests, 1,010 assertions — all passing** |
| Feature | **1,474 tests, 13 failures, 17 skipped** |

The 13 are the identical pre-existing set, compared name by name, with the census numbers unmoved (`app=113` vs 114, `93` vs `95`, `12` vs `14`). Out of scope per your instruction.

### Lynomia Chat

| Suite | Result |
|---|---|
| New integration specs (service + both request specs) | **55 examples, 0 failures** |
| `AccountLink.spec.js` (vitest) | **7 tests, 0 failures** |
| `spec/requests` + `spec/controllers/platform` + `spec/services/integrations` + `spec/swagger` | **1,110 examples, 0 failures**, 16 pending |

RuboCop, ESLint and Prettier clean on every file touched.

### Adversarial, concurrency and failure-recovery coverage

**Failure recovery** — a create whose answer was lost adopted not repeated (and attempted exactly once); resumption from the account step, the user step and the attach step, each asserting earlier calls are *not* repeated; the claim released when an attempt throws; a revocation standing when Lynomia is unreachable.

**Concurrency** — a second concurrent provision refused with nothing sent; the conditional UPDATE itself, proving only one of two callers can take the claim; a stale claim taken over; a superseded attempt unable to release its successor's claim; an operator approving *inside* the redemption window; two interleaved code mints leaving exactly one live code; a re-redemption granting nothing new.

**Adversarial** — an administrator of a different account refused; an agent refused; no session refused; an unauthenticated probe that must not burn the code; identical answers for unknown, used and expired codes; a demoted author; a removed author; a second app reusing a spent code; a claim unable to abandon a provision; an operator unable to approve an unproven claim; a merchant unable to name the account they are claiming; another installation's workspace never adopted; a pre-registered service identity refused on both sides; and an SSO link already minted killed by revocation.

### Two honesty notes

**A harness bug made four recovery tests vacuous before it was found.** `Http::fake()` *merges* stub callbacks rather than replacing them, and the earliest answers first — so re-faking between the two phases of a recovery test changed nothing and phase two re-ran phase one's failure. The fake is now registered once and rescripted through a property. `sentCount()` also matched paths by substring, counting `/accounts/7001/account_users` as an account create.

**Seven of my own test assertions proved less than their names claimed**, and a review dimension aimed only at the tests found them by mutating the production code. The worst: the lock test rebuilt `acquireLock`'s query in the test body and asserted on *that*, so replacing the whole production method with `return now();` left it green; an assertion that the service address's local part contained no `@` is true of every string by construction; a "never flashed" check named a session key no production code writes; and a cross-account leak spec redeemed the wrong account's code, staying green while a collapsed key made `redeem(A)` return account B. All seven are fixed and each now fails against the mutation that exposed it.

**One regression test passed for the wrong reason** and I caught it the same way. It approved the link *before* calling `request()`, so the refusal came from an unrelated guard. Rewritten to drive the approval from inside the redemption, which is where the window actually opens. **Every fix in this task was mutation-checked**: the fix reverted, the suite run, the expected tests confirmed red, the fix restored.

**What the concurrency tests do and do not prove.** The test database is SQLite `:memory:`, where two connections are two different databases and `lockForUpdate` compiles to nothing. These tests prove the *logic* — that a guarded `UPDATE` affects one row and not two, that a held claim turns a caller away, that a fencing token refuses a stale release. They do not prove behaviour under genuine parallelism, and no test in this suite could. The same applies on the Lynomia side, where MockRedis makes `WATCH` a no-op.

---

## 6. One CI blocker found and fixed

`.github/scripts/guard-no-workdo-changes.sh` fails any PR that touches `packages/workdo/` at all, and it is a blocking step of the required `tests` job. The LynomiaChat module lives there, so **the Phase 1 commit already tripped it** and every commit in this project would keep tripping it. Found by reading the guard, not by watching CI fail.

The guard is right about what it was written for — a vendored module edited in place is reverted by the next upstream update — and none of that is true of a module this project wrote. The exemption is an allowlist of exact directory names, not a pattern, so a vendored module cannot fall into it by accident. Verified both directions by running it: this branch passes, and a commit that also touches a vendored module exits 1 and names only that file.

---

## 7. Open items

**1. A code is still not bound to the platform app it was minted for.** Whichever app presents it first wins. This was **verified by execution** during review: an unintended app redeemed a merchant's code, received the account and service-user grants, minted a working SSO session, and the intended app saw only the deliberately indistinguishable "Invalid or expired code".

The mitigation the review proposed as the minimum is now in: the redeeming app's name is recorded and shown on the merchant's own status page, so a misdirected grant is something they can notice and undo rather than something nobody can see. **The binding itself is not done**, because it means the merchant picks a target integration at mint time, and how that list is presented is a product decision I did not make unilaterally.

**2. SSO identity is now the integration's service user, not the merchant.** §4 explains why. If you would rather merchants arrive as themselves, the alternative is to scope the SSO artifact to an account, which means changing `generate_sso_link` — a shared Chatwoot primitive with other callers. That is a larger change and I did not make it speculatively.

**3. The prerequisites from the Phase 1 report still stand**, unchanged: the APP_KEY / `.env` exposure is unresolved, no Lynomia PlatformApp token exists yet for Store Syria, and `php artisan package:seed LynomiaChat` must run before the module is enabled for anyone — the permission gate fails *closed*.

**4. The 13 pre-existing Feature failures** remain out of scope per your instruction.

---

## 8. Phase 1 acceptance status

Both workflows are **complete, reviewed, tested and pushed**.

- Linking an existing workspace works end to end and produces an openable link, with ownership proven by Lynomia and the connection still gated on operator approval.
- Provisioning is resumable and idempotent across timeouts, partial failures and repeated requests, with duplicate accounts and orphaned mappings each closed by a specific mechanism and a specific test.

What I would not do yet is call it *safe to enable*. The three prerequisites in §7.3 are unchanged from the Phase 1 report, and item §7.1 is a real gap I chose to surface rather than close with a guess. Nothing here is merged, deployed, or pointed at a live channel, and Phase 2 has not been started.
